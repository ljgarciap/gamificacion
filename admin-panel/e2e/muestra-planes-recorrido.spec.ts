import { Page, expect, test } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { mkdtempSync, readFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { apiCall, login } from './support';

// CA-80 de SPEC-007: recorrido completo por planes en navegador real. El superadmin configura los planes DESDE EL PANEL, siembra y enciende; una
// persona entra a la app con su correo (código leído de Mailpit), recibe el turno de BIENVENIDA, compra en cada rango y ve el plan de cada código;
// una compra de un producto que no participa no da ruleta; el superadmin simula «una semana sin comprar» y al volver a entrar llega el turno de
// REACTIVACIÓN; al reiniciar, los lotes de CADA plan se verifican con el verificador independiente (drand REAL).
//
// Requiere el backend en modo demostración con baliza real, worker y Mailpit (ver docker/compose.muestra-e2e.yml):
//   docker compose -f docker-compose.yml -f docker/compose.muestra-e2e.yml up -d --build backend worker web mailpit
// Tarda varios minutos (espera a drand, dos veces, y a la espera de 62 s entre dos códigos al mismo correo).
const WEB = process.env['WEB_URL'] ?? 'http://localhost:8080';
const API = process.env['API_URL'] ?? 'http://localhost:8000';
const MAILPIT = process.env['MAILPIT_URL'] ?? 'http://localhost:8025';
/** Espera mínima del servidor entre dos códigos al mismo correo (techo duro COOLDOWN_SECONDS = 60 en DemoLoginCodes). */
const COOLDOWN_MS = 62_000;

const money = (amount: number, count: number) => ({ type: 'money', amount, count });
const mix = (prizes: unknown[], noPrizeCount: number) => ({ roulette: { prizes, noPrizeCount } });

/** Mezclas chicas (16 códigos) para que la siembra sea rápida; los porcentajes son los del plan: 100 %, 25 %, 25 % y 50 %. */
const config = {
  mode: 'plans',
  user: { displayName: 'Cliente E2E', hostUserPrefix: 'e2e', initialBalance: 1000 },
  products: [
    { id: 'bono-bronce', name: 'Bono Bronce', price: 5_000, participates: true },
    { id: 'bono-plata', name: 'Bono Plata', price: 20_000, participates: true },
    { id: 'bono-oro', name: 'Bono Oro', price: 60_000, participates: true },
    { id: 'pasa-plata', name: 'Pasa Plata', price: 30_000, participates: false },
  ],
  credit: { balancePercent: 10, minPurchase: 10_000, codeStepAmount: 20_000, maxCodesPerPurchase: 3, maxCodesPerSession: 10 },
  mix: {},
  plans: [
    { id: 'welcome', kind: 'event', name: 'Bienvenida', maxMoneyPrize: 5_000, mix: mix([money(2_000, 2), money(3_000, 2)], 0) },
    { id: 'bronce', kind: 'amount', name: 'Bronce', minAmount: 1_000, maxAmount: 10_000, maxMoneyPrize: 10_000, mix: mix([money(10_000, 1)], 3) },
    { id: 'plata', kind: 'amount', name: 'Plata', minAmount: 10_001, maxAmount: 50_000, maxMoneyPrize: 20_000, mix: mix([money(20_000, 1)], 3) },
    { id: 'oro', kind: 'amount', name: 'Oro', minAmount: 50_001, maxAmount: null, maxMoneyPrize: 30_000, mix: mix([money(30_000, 2)], 2) },
  ],
  triggers: { reactivationDays: 7, turnsPerPurchase: 1, welcomeOnFirstLogin: true },
  ttlHours: 2,
};

interface DemoState {
  status: { enabled: boolean };
  pool: { available: number; assigned: number; retired: number };
  poolByPlan: { id: string; available: number; assigned: number; probability: number }[];
}

const cop = (n: number) => `$ ${n.toLocaleString('es-CO')}`;

/** Flutter Web: el árbol de accesibilidad se activa con foco + Enter en el marcador. */
async function openApp(page: Page): Promise<string[]> {
  const problems: string[] = [];
  page.on('pageerror', (e) => problems.push(`pageerror: ${e.message}`));
  page.on('console', (m) => {
    if (m.type() === 'error') problems.push(`console: ${m.text().slice(0, 200)}`);
  });
  await page.goto(WEB);
  await page.waitForTimeout(3500);
  await page.locator('flt-semantics-placeholder').focus().catch(() => undefined);
  await page.keyboard.press('Enter');
  await page.waitForTimeout(1000);
  return problems;
}

/** Último código de ingreso llegado a `email` (lo lee de la API de Mailpit), esperando a que haya al menos `count` correos para ese destino. */
async function mailCode(page: Page, email: string, count: number): Promise<string> {
  let code = '';
  await expect
    .poll(
      async () => {
        const found = await page.request.get(`${MAILPIT}/api/v1/search?query=${encodeURIComponent(`to:${email}`)}`);
        const list = (await found.json()) as { messages: { ID: string }[] };
        if (list.messages.length < count) return 'sin-correo';
        const msg = (await (await page.request.get(`${MAILPIT}/api/v1/message/${list.messages[0].ID}`)).json()) as { Text: string };
        code = /código para ingresar a la muestra es: (\d{6})/.exec(msg.Text)?.[1] ?? '';
        return code === '' ? 'sin-codigo' : 'ok';
      },
      { timeout: 30_000, intervals: [500] },
    )
    .toBe('ok');
  return code;
}

/** Flutter Web: un campo de texto se llena con clic + teclado (`fill` no llega a Flutter). */
async function typeInto(app: Page, name: RegExp, text: string): Promise<void> {
  const field = app.getByRole('textbox', { name });
  await field.click();
  await app.waitForTimeout(400);
  await app.keyboard.type(text, { delay: 20 });
}

/** Espera a que termine ESA corrida (por id). */
async function waitRun(panel: Page, runId: number, what: string): Promise<void> {
  await expect
    .poll(
      async () => {
        const r = await apiCall<{ state: string; error: string | null }>(panel, 'GET', `/demo/runs/${runId}`);
        if (r.state === 'failed') throw new Error(`${what} falló: ${r.error}`);
        return r.state;
      },
      { timeout: 6 * 60_000, intervals: [3000] },
    )
    .toBe('done');
}

/** Fila de «Tus códigos» con ese plan: Flutter expone la fila con botón como un grupo y la ya jugada como texto. */
const rowOf = (app: Page, plan: RegExp) => app.getByText(plan).or(app.getByRole('group', { name: plan }));

const stock = async (panel: Page) => {
  const s = await apiCall<DemoState>(panel, 'GET', '/demo');
  return Object.fromEntries(s.poolByPlan.map((p) => [p.id, [p.available, p.assigned]]));
};

test('recorrido por planes: panel → bienvenida, compra por rango, producto que no participa, reactivación simulada → reiniciar → verificador independiente', async ({ browser }) => {
  test.setTimeout(25 * 60_000);
  const ctx = await browser.newContext();
  const panel = await ctx.newPage();
  await login(panel, 'superadmin');

  // 0. Estado conocido: sin cuentas de corridas anteriores, la configuración de esta prueba, apagada y un pool NUEVO.
  const existing = await apiCall<{ items: { id: string }[] }>(panel, 'GET', '/demo/accounts?perPage=100');
  for (const a of existing.items) await apiCall(panel, 'DELETE', `/demo/accounts/${a.id}`);
  await apiCall(panel, 'PUT', '/demo/config', config);
  if ((await apiCall<DemoState>(panel, 'GET', '/demo')).status.enabled) await apiCall(panel, 'POST', '/demo/disable');
  const { runId } = await apiCall<{ runId: number }>(panel, 'POST', '/demo/reset', { accounts: 'delete' });
  await waitRun(panel, runId, 'reinicio inicial');

  // 1. En el panel: modo por planes, la probabilidad de cada plan sale de su mezcla y el pool sembrado coincide (4 por plan).
  await panel.goto('/muestra');
  await expect(panel.getByTestId('mode-plans')).toBeChecked();
  await expect(panel.getByTestId('plan-card')).toHaveCount(4);
  const prob = (id: string) => panel.locator(`[data-testid="plan-card"][data-plan="${id}"]`).getByTestId('plan-probability');
  await expect(prob('welcome')).toHaveText('100 %');
  await expect(prob('bronce')).toHaveText('25 %');
  await expect(prob('plata')).toHaveText('25 %');
  await expect(prob('oro')).toHaveText('50 %');
  await expect(panel.getByTestId('pool-total')).toContainText('Pool total: 16');
  await expect(panel.getByTestId('pool-available')).toHaveText('16');
  await expect(panel.getByTestId('plan-stock-row')).toHaveCount(4);
  for (const id of ['welcome', 'bronce', 'plata', 'oro']) await expect(panel.locator(`[data-testid="plan-stock-row"][data-plan="${id}"]`).getByTestId('plan-available')).toHaveText('4');

  // 2. Encender desde el panel.
  await panel.getByTestId('enable').click();
  await panel.getByTestId('confirm-yes').click();
  await expect(panel.getByTestId('status-chip')).toHaveText('Encendida');

  // 3. La persona entra: cuenta nueva → turno de BIENVENIDA anunciado y código del plan Bienvenida.
  const app = await ctx.newPage();
  const problems = await openApp(app);
  let token = '';
  const redemptions: { prize: { type: string; amount?: number } }[] = [];
  await app.route('**/v1/demo/auth/verify', async (route) => {
    const response = await route.fetch();
    if (response.status() === 200) token = ((await response.json()) as { token: string }).token;
    return route.fulfill({ response });
  });
  await app.route('**/v1/codes/redeem', async (route) => {
    const response = await route.fetch();
    const body = await response.json();
    if (response.status() === 200) redemptions.push(body.outcome);
    return route.fulfill({ response });
  });
  await expect(app.getByText('Bienvenido a la muestra')).toBeVisible({ timeout: 15_000 });
  await panel.request.delete(`${MAILPIT}/api/v1/messages`);
  const email = `e2e-${Date.now()}@ejemplo.com`;
  await typeInto(app, /Correo electrónico/, email);
  await app.getByRole('button', { name: /Enviarme el código/ }).click();
  const firstRequestAt = Date.now();
  await expect(app.getByText('Revisa tu correo')).toBeVisible({ timeout: 15_000 });
  await typeInto(app, /Código de 6 dígitos/, await mailCode(panel, email, 1));
  await app.getByRole('button', { name: /^Entrar$/ }).click();
  await expect(app.getByText(/ruleta de bienvenida esperándote/)).toBeVisible({ timeout: 15_000 });
  await expect(rowOf(app, /Plan Bienvenida/).first()).toBeVisible({ timeout: 20_000 });
  expect(await stock(panel)).toMatchObject({ welcome: [3, 1] });

  // 4. Juega el turno de bienvenida (100 %: siempre gana uno de los premios de ese plan).
  let expected = 1_000;
  await app.getByRole('button', { name: /^Jugar$/ }).first().click();
  await app.getByRole('button', { name: /GIRAR|TIRAR/ }).first().click();
  await expect(app.getByRole('button', { name: /Continuar/ })).toBeVisible({ timeout: 30_000 });
  expect(redemptions).toHaveLength(1);
  expect(redemptions[0].prize.type).toBe('money');
  expect([2_000, 3_000]).toContain(redemptions[0].prize.amount);
  expected += redemptions[0].prize.amount!;
  await app.getByRole('button', { name: /Continuar/ }).click();
  await expect(app.getByText('Jugado')).toHaveCount(1);
  await expect(app.getByText(/ruleta de bienvenida esperándote/)).toHaveCount(0); // ya la usó: el aviso no reaparece

  // 5. Compra en el rango PLATA ($ 20.000): la vista previa promete ruleta, el saldo es el 10 % y el código llega con su plan.
  await app.getByRole('button', { name: /Seguir comprando/ }).click();
  await app.getByRole('button', { name: /Agregar uno de Bono Plata/ }).click();
  await expect(app.getByText(`Recibirás ${cop(2_000)} de saldo y una ruleta según el valor de tu compra`)).toBeVisible();
  await app.getByRole('button', { name: /Pagar \(simulado\)/ }).click();
  await expect(app.getByText('Saldo cargado').first()).toBeVisible({ timeout: 20_000 });
  expected += 2_000;
  await expect(app.getByText(`+ ${cop(2_000)} a tu saldo`)).toBeVisible();
  await expect(app.getByText('1 código para jugar')).toBeVisible();
  await app.getByRole('button', { name: /Ver mis códigos/ }).click();
  await expect(rowOf(app, /Plan Plata/).first()).toBeVisible({ timeout: 20_000 });
  await app.getByRole('button', { name: /^Jugar$/ }).first().click();
  await app.getByRole('button', { name: /GIRAR|TIRAR/ }).first().click();
  await expect(app.getByRole('button', { name: /Continuar/ })).toBeVisible({ timeout: 30_000 });
  const plata = redemptions[1].prize;
  expect(['none', 'money']).toContain(plata.type);
  if (plata.type === 'money') {
    expect(plata.amount).toBe(20_000);
    expected += 20_000;
  }
  await app.getByRole('button', { name: /Continuar/ }).click();

  // 6. Un producto que NO participa: se compra, se acredita el 10 % y no da ruleta (ni lo dice la vista previa ni la entrega).
  await app.getByRole('button', { name: /Seguir comprando/ }).click();
  await app.getByRole('button', { name: /Agregar uno de Pasa Plata/ }).click();
  await expect(app.getByText(`Recibirás ${cop(3_000)} de saldo. Esta compra no da ruleta`)).toBeVisible();
  await app.getByRole('button', { name: /Pagar \(simulado\)/ }).click();
  await expect(app.getByText('Esta compra no dio ruleta')).toBeVisible({ timeout: 20_000 });
  expected += 3_000;
  await expect(app.getByText(`+ ${cop(3_000)} a tu saldo`)).toBeVisible();

  // 7. Los otros dos rangos, por la API con el token de la cuenta: Bronce ($ 5.000) y Oro ($ 60.000) entregan código de SU plan.
  const buy = async (productId: string, key: string) => {
    const r = await app.request.post(`${API}/v1/demo/purchase`, {
      headers: { Authorization: `Bearer ${token}`, 'Idempotency-Key': key },
      data: { items: [{ productId, quantity: 1 }] },
    });
    expect(r.status()).toBe(200);
    return (await r.json()) as { codes: { plan?: string }[]; credited: number };
  };
  const bronce = await buy('bono-bronce', 'e2e-bronce-0001');
  expect(bronce.codes.map((c) => c.plan)).toEqual(['Bronce']);
  const oro = await buy('bono-oro', 'e2e-oro-0001');
  expect(oro.codes.map((c) => c.plan)).toEqual(['Oro']);
  expected += bronce.credited + oro.credited;
  const balance = async () => (await (await app.request.get(`${API}/v1/balance`, { headers: { Authorization: `Bearer ${token}` } })).json()).amount as number;
  expect(await balance()).toBe(expected);

  // 8. El panel lo refleja: una existencia menos en cada plan (bienvenida, bronce, plata y oro: 3 disponibles y 1 entregado).
  await panel.reload();
  expect(await stock(panel)).toEqual({ welcome: [3, 1], bronce: [3, 1], plata: [3, 1], oro: [3, 1] });
  await expect(panel.getByTestId('pool-assigned')).toHaveText('4');

  // 9. REACTIVACIÓN: el superadmin simula «una semana sin comprar» y, al volver a entrar con el MISMO correo, llega el turno de regreso.
  await expect(panel.getByTestId('account-row')).toHaveCount(1);
  await panel.getByTestId('account-simulate').click();
  await expect(panel.getByTestId('simulate-confirm')).toContainText('7 días sin comprar');
  await panel.getByTestId('simulate-confirm-yes').click();
  await expect(panel.getByTestId('message')).toContainText('turno de reactivación');
  await app.getByRole('button', { name: /Ver mis códigos/ }).click(); // la compra sin ruleta dejó la app en «Saldo cargado»
  await app.getByRole('button', { name: /^Salir$/ }).click({ timeout: 15_000 });
  await expect(app.getByText(/solo para enviarte el código de ingreso/)).toBeVisible();
  const wait = COOLDOWN_MS - (Date.now() - firstRequestAt);
  if (wait > 0) await app.waitForTimeout(wait);
  await typeInto(app, /Correo electrónico/, email);
  await app.getByRole('button', { name: /Enviarme el código/ }).click();
  await expect(app.getByText('Revisa tu correo')).toBeVisible({ timeout: 15_000 });
  await typeInto(app, /Código de 6 dígitos/, await mailCode(panel, email, 2));
  await app.getByRole('button', { name: /^Entrar$/ }).click();
  await expect(app.getByText(/verte de nuevo/)).toBeVisible({ timeout: 15_000 });
  await expect(app.getByRole('group', { name: /Plan Bienvenida/ })).toHaveCount(1, { timeout: 20_000 }); // la de regreso, sin jugar (la ya jugada es texto) // el de bienvenida (ya jugado) y el de regreso
  await expect(app.getByText('Jugado')).toHaveCount(2); // bienvenida y plata, ya jugados
  await expect(app.getByRole('button', { name: /^Jugar$/ })).toHaveCount(3); // sin jugar: el de regreso, bronce y oro
  expect(await balance()).toBe(expected);
  expect(await stock(panel)).toMatchObject({ welcome: [2, 2] });
  // Un solo turno por ventana: volver a entrar enseguida no da otro (se comprueba por el panel, no hace falta otra espera de correo).
  const account = (await (await app.request.get(`${API}/v1/demo/account`, { headers: { Authorization: `Bearer ${token}` } })).json()) as { codes: { plan?: string; played: boolean }[] };
  expect(account.codes.filter((c) => c.plan === 'Bienvenida')).toHaveLength(2);
  expect(account.codes.map((c) => c.plan).sort()).toEqual(['Bienvenida', 'Bienvenida', 'Bronce', 'Oro', 'Plata']);

  // Sin errores de consola de la app.
  expect(problems.filter((p) => !/favicon|manifest/i.test(p))).toEqual([]);

  // 10. Reiniciar borrando las cuentas: pool nuevo, limpio, y la cuenta ya no existe.
  await apiCall(panel, 'POST', '/demo/disable');
  const retiredBefore = (await apiCall<DemoState>(panel, 'GET', '/demo')).pool.retired;
  await panel.reload();
  await panel.getByTestId('reset').click();
  await panel.getByTestId('reset-delete').check();
  await panel.getByTestId('confirm-yes').click();
  await expect(panel.getByTestId('pool-available')).toHaveText('16', { timeout: 6 * 60_000 });
  await expect(panel.getByTestId('pool-retired')).toHaveText(String(retiredBefore + 16));
  await expect(panel.getByTestId('accounts-total')).toHaveText('0');

  // 11. CA-7: los lotes de CADA plan del primer sembrado se revelan, se exportan y los verifica el verificador independiente.
  const ids: string[] = JSON.parse(
    execFileSync(
      'docker',
      ['compose', 'exec', '-T', 'backend', 'php', 'artisan', 'tinker', '--execute',
       "echo json_encode(array_column(DB::select(\"select b.id from batches b join campaigns c on c.id=b.campaign_id where c.is_demo and b.state='closed' order by b.created_at desc limit 4\"), 'id'));"],
      { cwd: '..', encoding: 'utf8' },
    ).trim().split('\n').pop()!,
  );
  expect(ids).toHaveLength(4);
  const dir = mkdtempSync(join(tmpdir(), 'muestra-planes-'));
  for (const id of ids) {
    await apiCall(panel, 'POST', `/batches/${id}/reveal`);
    const inContainer = `/tmp/export-${id}.json`;
    execFileSync('docker', ['compose', 'exec', '-T', 'backend', 'php', 'artisan', 'gamification:audit:export', id, `--out=${inContainer}`], { cwd: '..', encoding: 'utf8' });
    const file = join(dir, `export-${id}.json`);
    execFileSync('sh', ['-c', `docker compose cp backend:${inContainer} ${file}`], { cwd: '..' });
    expect(readFileSync(file, 'utf8')).toContain('beacon_round');
    const out = execFileSync('python3', ['-I', join(process.cwd(), '..', 'verifier', 'gamif_verify.py'), 'verify', file], { encoding: 'utf8' });
    expect(out).toContain('OK: el compromiso se reproduce, la baraja coincide con las asignaciones y los conteos son el stock.');
  }
  await ctx.close();
});
