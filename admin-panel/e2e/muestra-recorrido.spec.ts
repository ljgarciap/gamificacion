import { Page, expect, test } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { mkdtempSync, readFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { apiCall, login } from './support';

// CA-9 de SPEC-006: recorrido completo en navegador real. El superadmin configura, siembra y enciende la muestra DESDE EL PANEL; una persona
// entra a la app (Flutter web), compra en línea, recibe saldo y un código sorteado y juega; se comprueba contra la API real (saldo, resultado
// decidido por el servidor) y, al reiniciar, el lote demo se verifica con el verificador independiente (drand REAL).
//
// Requiere el backend en modo demostración con baliza real y worker (ver docker/compose.muestra-e2e.yml):
//   docker compose -f docker-compose.yml -f docker/compose.muestra-e2e.yml up -d --build backend worker web
// Tarda varios minutos (espera a drand, dos veces). WEB_URL / API_URL cambian los orígenes por defecto.
//
// SPEC-006A (CA-20): la persona entra con su CORREO y un código de un solo uso que la prueba lee de Mailpit (correo simulado del compose); compra,
// juega, sale, vuelve a entrar con el mismo correo y ve su saldo acumulado y sus códigos jugados; el panel lista la cuenta enmascarada, el
// reinicio conserva la cuenta (elección «keep») y al final se borra desde el panel.
const WEB = process.env['WEB_URL'] ?? 'http://localhost:8080';
const API = process.env['API_URL'] ?? 'http://localhost:8000';
const MAILPIT = process.env['MAILPIT_URL'] ?? 'http://localhost:8025';
/** Espera mínima del servidor entre dos códigos al mismo correo (techo duro COOLDOWN_SECONDS = 60 en DemoLoginCodes). */
const COOLDOWN_MS = 62_000;

const config = {
  user: { displayName: 'Cliente E2E', hostUserPrefix: 'e2e', initialBalance: 1000 },
  products: [
    { id: 'recarga-20', name: 'Recarga E2E 20', price: 20_000 },
    { id: 'recarga-50', name: 'Recarga E2E 50', price: 50_000 },
  ],
  credit: { balancePercent: 10, minPurchase: 10_000, codeStepAmount: 20_000, maxCodesPerPurchase: 3, maxCodesPerSession: 10 },
  mix: {
    roulette: { prizes: [{ type: 'money', amount: 5_000, count: 3 }], noPrizeCount: 7 },
    dice: { prizes: [{ type: 'money', amount: 10_000, count: 2 }], noPrizeCount: 8 },
  },
  ttlHours: 2,
};

interface DemoState { status: { enabled: boolean; active: boolean }; pool: { available: number; assigned: number; retired: number }; lastRun: { state: string; error: string | null } | null }

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
  await app.waitForTimeout(400); // el campo recién aparecido tarda en recibir el foco: sin esto se pierden las primeras teclas
  await app.keyboard.type(text, { delay: 20 });
}

/** Espera a que termine ESA corrida (por id): la «última corrida» podría ser todavía una anterior ya terminada. */
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

test('recorrido completo: panel (configura, siembra, enciende) → app (acceso, compra, saldo, juego) → apagar → reiniciar → verificador independiente', async ({ browser }) => {
  test.setTimeout(20 * 60_000);
  const ctx = await browser.newContext();
  const panel = await ctx.newPage();
  await login(panel, 'superadmin');

  // 0. Sin cuentas de corridas anteriores: el recorrido cuenta las suyas.
  const existing = await apiCall<{ items: { id: string }[] }>(panel, 'GET', '/demo/accounts?perPage=100');
  for (const a of existing.items) await apiCall(panel, 'DELETE', `/demo/accounts/${a.id}`);

  // 1. Estado inicial conocido: configuración de esta prueba, apagada y un pool NUEVO (el reinicio retira lo anterior y siembra de nuevo).
  await apiCall(panel, 'PUT', '/demo/config', config);
  if ((await apiCall<DemoState>(panel, 'GET', '/demo')).status.enabled) await apiCall(panel, 'POST', '/demo/disable');
  const { runId } = await apiCall<{ runId: number }>(panel, 'POST', '/demo/reset', { accounts: 'keep' });
  await waitRun(panel, runId, 'reinicio inicial');

  // 2. Desde el panel: la configuración guardada se ve y el pool sembrado coincide con la mezcla (20 = 10 de ruleta + 10 de dados).
  await panel.goto('/muestra');
  await expect(panel.getByTestId('status-chip')).toHaveText('Apagada');
  await expect(panel.getByTestId('displayName')).toHaveValue('Cliente E2E');
  await expect(panel.getByTestId('pool-total')).toContainText('Pool total: 20');
  await expect(panel.getByTestId('pool-available')).toHaveText('20');
  await expect(panel.getByTestId('pool-assigned')).toHaveText('0');
  await expect(panel.getByTestId('last-run')).toContainText('terminada');
  await expect(panel.getByTestId('seed')).toBeDisabled(); // ya hay códigos: se usa «Reiniciar»
  await expect(panel.getByTestId('enable')).toBeEnabled();

  // 3. Con la muestra apagada la app NO ofrece el recorrido y la API responde 404.
  expect((await panel.request.post(`${API}/v1/demo/session`)).status()).toBe(404);

  // 4. Encender desde el panel (con confirmación).
  await panel.getByTestId('enable').click();
  await panel.getByTestId('confirm-yes').click();
  await expect(panel.getByTestId('status-chip')).toHaveText('Encendida');

  // 5. La persona entra a la app.
  const app = await ctx.newPage();
  const problems = await openApp(app);
  let token = '';
  let order: { credited: number; balance: number; codes: { code: string; game: string }[]; total: number } | null = null;
  const redemptions: { prize: { type: string; amount?: number }; gameResult: unknown }[] = [];
  await app.route('**/v1/demo/purchase', async (route) => {
    const response = await route.fetch();
    token = route.request().headers()['authorization']?.replace('Bearer ', '') ?? token;
    order = await response.json();
    return route.fulfill({ response });
  });
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
  await expect(app.getByText(/MUESTRA · Datos simulados/)).toBeVisible();
  // Acceso (SPEC-006A): aviso de privacidad visible, correo → código de un solo uso leído de Mailpit → tienda.
  await expect(app.getByText(/solo para enviarte el código de ingreso/)).toBeVisible();
  await panel.request.delete(`${MAILPIT}/api/v1/messages`);
  const email = `e2e-${Date.now()}@ejemplo.com`;
  await typeInto(app, /Correo electrónico/, email);
  await app.getByRole('button', { name: /Enviarme el código/ }).click();
  const firstRequestAt = Date.now();
  await expect(app.getByText('Revisa tu correo')).toBeVisible({ timeout: 15_000 });
  // Un código errado no entra y no revela nada más que «no es válido».
  await typeInto(app, /Código de 6 dígitos/, '000000');
  await app.getByRole('button', { name: /^Entrar$/ }).click();
  await expect(app.getByText(/El código no es válido o ya venció/)).toBeVisible();
  // El código errado se borra solo y el foco vuelve al campo.
  await typeInto(app, /Código de 6 dígitos/, await mailCode(panel, email, 1));
  await app.getByRole('button', { name: /^Entrar$/ }).click();
  await expect(app.getByText(/Entrarás como Cliente E2E/)).toBeVisible();
  await expect(app.getByText(cop(1_000)).first()).toBeVisible(); // cuenta nueva: saldo inicial del escenario

  // Tienda: dos recargas de $ 20.000 = $ 40.000 → $ 4.000 de saldo y 2 códigos.
  const add = app.getByRole('button', { name: /Agregar uno de Recarga E2E 20/ });
  await add.click();
  await add.click();
  await expect(app.getByText(cop(40_000))).toBeVisible();
  await expect(app.getByText(`Recibirás ${cop(4_000)} de saldo y 2 códigos`)).toBeVisible();
  await app.getByRole('button', { name: /Pagar \(simulado\)/ }).click();

  // Saldo cargado: lo que dice la regla del panel y lo que dice el servidor.
  await expect(app.getByText('Saldo cargado').first()).toBeVisible({ timeout: 20_000 });
  await expect.poll(() => order).not.toBeNull();
  expect(order!.total).toBe(40_000);
  expect(order!.credited).toBe(4_000);
  expect(order!.balance).toBe(5_000); // saldo inicial 1.000 + 4.000
  expect(order!.codes).toHaveLength(2);
  expect(new Set(order!.codes.map((c) => c.code)).size).toBe(2);
  await expect(app.getByText(`+ ${cop(4_000)} a tu saldo`)).toBeVisible();
  const api = async () => (await (await app.request.get(`${API}/v1/balance`, { headers: { Authorization: `Bearer ${token}` } })).json()).amount as number;
  expect(await api()).toBe(5_000);

  // Juego: cada código se canjea solo, sin teclearlo.
  await app.getByRole('button', { name: /Ver mis códigos/ }).click();
  let expected = 5_000;
  for (let i = 0; i < 2; i++) {
    await app.getByRole('button', { name: /^Jugar$/ }).first().click();
    await app.getByRole('button', { name: /GIRAR|TIRAR/ }).first().click();
    await expect(app.getByRole('button', { name: /Continuar/ })).toBeVisible({ timeout: 30_000 });
    expect(redemptions).toHaveLength(i + 1);
    const prize = redemptions[i].prize;
    // El resultado es uno de los predefinidos en la mezcla (nada inventado por el cliente).
    expect(['none', 'money']).toContain(prize.type);
    if (prize.type === 'money') {
      expect([5_000, 10_000]).toContain(prize.amount);
      expected += prize.amount!;
    }
    await app.getByRole('button', { name: /Continuar/ }).click();
    await expect(app.getByText('Jugado')).toHaveCount(i + 1);
  }
  expect(await api()).toBe(expected);
  await expect(app.getByText(cop(expected)).first()).toBeVisible();

  // CA-20: salir y volver a entrar con el MISMO correo recupera el saldo acumulado y los códigos (jugados) de la cuenta.
  await app.getByRole('button', { name: /^Salir$/ }).click();
  await expect(app.getByText(/solo para enviarte el código de ingreso/)).toBeVisible();
  await expect(app.getByText('Jugado')).toHaveCount(0);
  const wait = COOLDOWN_MS - (Date.now() - firstRequestAt);
  if (wait > 0) await app.waitForTimeout(wait);
  await typeInto(app, /Correo electrónico/, email);
  await app.getByRole('button', { name: /Enviarme el código/ }).click();
  await expect(app.getByText('Revisa tu correo')).toBeVisible({ timeout: 15_000 });
  await typeInto(app, /Código de 6 dígitos/, await mailCode(panel, email, 2));
  await app.getByRole('button', { name: /^Entrar$/ }).click();
  await expect(app.getByText('Tus códigos').first()).toBeVisible({ timeout: 15_000 });
  await expect(app.getByText('Jugado')).toHaveCount(2);
  await expect(app.getByText(cop(expected)).first()).toBeVisible();
  expect(await api()).toBe(expected);
  const account = (await (await app.request.get(`${API}/v1/demo/account`, { headers: { Authorization: `Bearer ${token}` } })).json()) as { balance: number; codes: { played: boolean }[] };
  expect(account.balance).toBe(expected);
  expect(account.codes.map((c) => c.played)).toEqual([true, true]);
  // Sin errores de consola, salvo el 401 ESPERADO del código errado de más arriba (el navegador lo registra como error de carga).
  const unexpected = problems.filter((p) => !/favicon|manifest/i.test(p));
  expect(unexpected.filter((p) => /status of 401/.test(p))).toHaveLength(1);
  expect(unexpected.filter((p) => !/status of 401/.test(p))).toEqual([]);

  // 6. Las estadísticas del panel coinciden: 2 entregados, 18 disponibles.
  await panel.reload();
  await expect(panel.getByTestId('pool-assigned')).toHaveText('2');
  await expect(panel.getByTestId('pool-available')).toHaveText('18');
  // La cuenta aparece en el panel con el correo ENMASCARADO (nunca completo) y su saldo acumulado.
  await expect(panel.getByTestId('accounts-total')).toHaveText('1');
  const row = panel.getByTestId('account-row');
  await expect(row).toContainText(`${email[0]}***@ejemplo.com`);
  await expect(row).toContainText(cop(expected));
  await expect(panel.getByText(email)).toHaveCount(0);

  // 7. Apagar desde el panel: la app deja de ofrecer el recorrido y la API vuelve a 404.
  await panel.getByTestId('disable').click();
  await panel.getByTestId('confirm-yes').click();
  await expect(panel.getByTestId('status-chip')).toHaveText('Apagada');
  expect((await panel.request.post(`${API}/v1/demo/session`)).status()).toBe(404);
  const off = await ctx.newPage();
  await openApp(off);
  await expect(off.getByText(/MUESTRA · Datos simulados/)).toHaveCount(0);

  // 8. Reiniciar (CA-10): apaga, retira los códigos y siembra un pool nuevo, limpio.
  const retiredBefore = (await apiCall<DemoState>(panel, 'GET', '/demo')).pool.retired;
  await panel.getByTestId('reset').click();
  await expect(panel.getByTestId('confirm-yes')).toBeDisabled(); // hay que elegir qué pasa con las cuentas
  await panel.getByTestId('reset-keep').check();
  await panel.getByTestId('confirm-yes').click();
  await expect(panel.getByTestId('pool-available')).toHaveText('20', { timeout: 6 * 60_000 });
  await expect(panel.getByTestId('pool-assigned')).toHaveText('0');
  await expect(panel.getByTestId('pool-retired')).toHaveText(String(retiredBefore + 20)); // los 20 del pool anterior (acumulado de la historia)
  await expect(panel.getByTestId('status-chip')).toHaveText('Apagada');
  // «Conservar»: el pool es nuevo pero la cuenta y su saldo siguen ahí.
  await expect(panel.getByTestId('accounts-total')).toHaveText('1');
  await expect(panel.getByTestId('account-row')).toContainText(cop(expected));
  // Borrar la cuenta desde el panel (con confirmación): ya no queda ninguna.
  await panel.getByTestId('account-delete').click();
  await expect(panel.getByTestId('account-confirm')).toContainText('quedará inaccesible');
  await panel.getByTestId('account-confirm-yes').click();
  await expect(panel.getByTestId('accounts-total')).toHaveText('0');
  await expect(panel.getByTestId('accounts-empty')).toBeVisible();

  // 9. CA-7: el lote demo del primer sembrado (cerrado por el reinicio) se revela, se exporta y lo verifica el verificador independiente.
  const ids: string[] = JSON.parse(
    execFileSync(
      'docker',
      ['compose', 'exec', '-T', 'backend', 'php', 'artisan', 'tinker', '--execute',
       "echo json_encode(array_column(DB::select(\"select b.id from batches b join campaigns c on c.id=b.campaign_id where c.is_demo and b.state='closed' order by b.created_at desc limit 2\"), 'id'));"],
      { cwd: '..', encoding: 'utf8' },
    ).trim().split('\n').pop()!,
  );
  expect(ids).toHaveLength(2);
  const dir = mkdtempSync(join(tmpdir(), 'muestra-'));
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
