import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import { apiCall, login } from './support';

interface State { configured: boolean; config: Record<string, unknown>; status: { serverAllowed: boolean; enabled: boolean } }

/** Configuración de partida de las pruebas que editan: no dependen de lo que haya dejado otra corrida. */
const KNOWN = {
  user: { displayName: 'Cliente de muestra', hostUserPrefix: 'muestra', initialBalance: 0 },
  products: [
    { id: 'recarga-20', name: 'Recarga $20.000', price: 20_000 },
    { id: 'recarga-50', name: 'Recarga $50.000', price: 50_000 },
  ],
  credit: { balancePercent: 10, minPurchase: 10_000, codeStepAmount: 20_000, maxCodesPerPurchase: 3, maxCodesPerSession: 10 },
  mix: { roulette: { prizes: [{ type: 'money', amount: 5_000, count: 2 }], noPrizeCount: 8 } },
  ttlHours: 24,
};

test.describe('Muestra (SPEC-006 M4)', () => {
  test('solo el superadmin la ve; el operador no tiene la entrada ni la ruta ni la API', async ({ page }) => {
    await login(page, 'operator');
    await expect(page.getByRole('link', { name: 'Muestra' })).toHaveCount(0);
    await page.goto('/muestra');
    await expect(page).toHaveURL(/sin-acceso/);
    const res = await page.request.get('/admin/api/v1/demo');
    expect(res.status()).toBe(403);
  });

  test('el superadmin edita el escenario: totales y vista previa en vivo, validación y guardado', async ({ page }) => {
    await login(page, 'superadmin');
    await apiCall(page, 'POST', '/demo/disable').catch(() => undefined); // estado conocido: otras corridas pudieron dejarla encendida
    const original = (await apiCall<State>(page, 'GET', '/demo')).config;
    await apiCall(page, 'PUT', '/demo/config', KNOWN);
    try {
      await page.goto('/');
      await page.getByRole('link', { name: 'Muestra' }).click();
      await expect(page).toHaveURL(/\/muestra$/);
      await expect(page.getByRole('heading', { level: 1, name: 'Muestra' })).toBeVisible();
      await expect(page.getByTestId('status-chip')).toHaveText('Apagada');
      await expect(page.getByTestId('save')).toBeDisabled();

      // Vista previa: 10 % de $ 20.000 = $ 2.000 y un código por cada $ 20.000.
      await page.getByTestId('balancePercent').fill('25');
      const first = page.getByTestId('preview-row').first();
      await expect(first).toContainText('$ 5.000');
      await expect(page.getByTestId('save')).toBeEnabled();
      await expect(page.getByTestId('dirty-note')).toBeVisible();

      // Totales de la mezcla: 2 + 8 = 10 → 3 con premio + 8 sin premio = 11.
      await page.getByTestId('mix-game').first().getByLabel(/premio 1: cantidad/).fill('3');
      await expect(page.getByTestId('mix-total').first()).toContainText('3 con premio + 8 sin premio = 11 códigos');
      await expect(page.getByTestId('pool-total')).toContainText('Pool total: 11');

      // Validación: porcentaje fuera de rango bloquea el guardado con un mensaje.
      await page.getByTestId('balancePercent').fill('101');
      await expect(page.getByTestId('problems')).toContainText('Porcentaje de saldo');
      await expect(page.getByTestId('save')).toBeDisabled();
      await page.getByTestId('balancePercent').fill('25');
      await expect(page.getByTestId('problems')).toHaveCount(0);

      // Producto nuevo y juego nuevo.
      await page.getByTestId('add-product').click();
      await expect(page.getByTestId('product-row')).toHaveCount(3);
      await page.getByTestId('add-game').selectOption('scratch');
      await expect(page.getByTestId('mix-game')).toHaveCount(2);

      await page.getByTestId('displayName').fill('Cliente E2E');
      await page.getByTestId('save').click();
      await expect(page.getByTestId('message')).toContainText('Configuración guardada');
      await expect(page.getByTestId('save')).toBeDisabled();

      // Persistió (el servidor la guarda en jsonb: el orden de claves cambia y no debe verse como «cambios sin guardar»).
      await page.reload();
      await expect(page.getByTestId('displayName')).toHaveValue('Cliente E2E');
      await expect(page.getByTestId('balancePercent')).toHaveValue('25');
      await expect(page.getByTestId('mix-game')).toHaveCount(2);
      await expect(page.getByTestId('save')).toBeDisabled();
      await expect(page.getByTestId('dirty-note')).toHaveCount(0);

      // Descartar vuelve a lo guardado.
      await page.getByTestId('displayName').fill('Otro');
      await page.getByTestId('discard').click();
      await expect(page.getByTestId('displayName')).toHaveValue('Cliente E2E');
    } finally {
      await apiCall(page, 'PUT', '/demo/config', original).catch(() => undefined);
    }
  });

  test('una configuración que el cliente acepta pero el servidor rechaza muestra el mensaje del servidor', async ({ page }) => {
    await login(page, 'superadmin');
    const original = (await apiCall<State>(page, 'GET', '/demo')).config;
    await apiCall(page, 'PUT', '/demo/config', KNOWN);
    try {
      await page.goto('/muestra');
      const game = page.getByTestId('mix-game').first();
      for (let i = 0; i < 12; i++) await game.getByTestId('add-prize-roulette').click(); // 13 premios distintos: la ruleta admite hasta 12 segmentos
      const rows = game.getByTestId('prize-row');
      for (let i = 0; i < 13; i++) await rows.nth(i).getByLabel(/monto/).fill(String(1000 + i));
      await expect(page.getByTestId('problems')).toHaveCount(0);
      await page.getByTestId('save').click();
      await expect(page.getByTestId('fail')).toBeVisible();
      await expect(page.getByTestId('save')).toBeEnabled(); // no se perdió lo editado
    } finally {
      await apiCall(page, 'PUT', '/demo/config', original).catch(() => undefined);
    }
  });

  test('sin GAMIF_DEMO_ALLOWED avisa y no deja sembrar, reiniciar ni encender; cumple accesibilidad', async ({ page }) => {
    await login(page, 'superadmin');
    await apiCall(page, 'POST', '/demo/disable').catch(() => undefined);
    const s = await apiCall<State>(page, 'GET', '/demo');
    await page.goto('/muestra');
    await expect(page.getByTestId('status-chip')).toBeVisible();
    if (!s.status.serverAllowed) {
      await expect(page.getByTestId('not-allowed')).toBeVisible();
      for (const id of ['seed', 'reset', 'enable']) await expect(page.getByTestId(id)).toBeDisabled();
    }
    const res = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa']).analyze();
    expect(res.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical'), JSON.stringify(res.violations.map((v) => [v.id, v.nodes[0]?.html?.slice(0, 120)]))).toEqual([]);
  });

  test('interruptor y corridas: confirmaciones, sondeo hasta terminar y estado (servidor simulado)', async ({ page }) => {
    await login(page, 'superadmin');
    const real = await apiCall<State & Record<string, unknown>>(page, 'GET', '/demo');
    let enabled = false;
    let available = 0;
    let run: Record<string, unknown> | null = null;
    let runPolls = 0;
    const calls: string[] = [];
    const iso = new Date().toISOString();
    await page.route('**/admin/api/v1/demo**', async (route) => {
      const req = route.request();
      const path = new URL(req.url()).pathname.replace('/admin/api/v1', '');
      if (req.method() !== 'GET') calls.push(`${req.method()} ${path}`);
      const json = (body: unknown, status = 200) => route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(body) });
      if (path === '/demo' && req.method() === 'GET') {
        return json({ ...real, configured: true, status: { enabled, enabledUntil: enabled ? new Date(Date.now() + 86_400_000).toISOString() : null, active: enabled, serverAllowed: true }, pool: { available, assigned: 0, retired: 0 }, lastRun: run });
      }
      if (path === '/demo/seed') {
        run = { id: 7, kind: 'seed', state: 'pending', error: null, requestedBy: 'x', createdAt: iso, startedAt: null, finishedAt: null };
        return json({ runId: 7 }, 202);
      }
      if (path === '/demo/runs/7') {
        runPolls++;
        if (runPolls >= 2) { available = 10; run = { ...run, state: 'done', finishedAt: iso }; } else run = { ...run, state: 'running' };
        return json(run);
      }
      if (path === '/demo/enable') { enabled = true; return route.fulfill({ status: 204 }); }
      if (path === '/demo/disable') { enabled = false; return route.fulfill({ status: 204 }); }
      return route.continue();
    });
    await page.goto('/muestra');
    await expect(page.getByTestId('status-chip')).toHaveText('Apagada');
    await expect(page.getByTestId('enable')).toBeDisabled(); // sin códigos no se enciende
    await expect(page.getByTestId('seed')).toBeEnabled();

    // Con cambios sin guardar no se puede sembrar (se sembraría con la configuración vieja).
    await page.getByTestId('displayName').fill('Cambio pendiente');
    await expect(page.getByTestId('seed')).toBeDisabled();
    await page.getByTestId('discard').click();
    await expect(page.getByTestId('seed')).toBeEnabled();

    await page.getByTestId('seed').click();
    await expect(page.getByTestId('last-run')).toContainText('Siembra');
    await expect(page.getByTestId('pool-available')).toHaveText('10', { timeout: 15_000 });
    await expect(page.getByTestId('last-run')).toContainText('terminada');
    await expect(page.getByTestId('seed')).toBeDisabled(); // ya hay códigos: se usa «Reiniciar»

    // Encender pide confirmación; cancelar no llama al servidor.
    await page.getByTestId('enable').click();
    await expect(page.getByTestId('confirm')).toContainText('acceso público');
    await page.getByTestId('confirm-no').click();
    await expect(page.getByTestId('confirm')).toHaveCount(0);
    expect(calls).not.toContain('POST /demo/enable');
    await page.getByTestId('enable').click();
    await page.getByTestId('confirm-yes').click();
    await expect(page.getByTestId('status-chip')).toHaveText('Encendida');
    expect(calls).toContain('POST /demo/enable');

    await page.getByTestId('disable').click();
    await expect(page.getByTestId('confirm')).toContainText('dejará de responder');
    await page.getByTestId('confirm-yes').click();
    await expect(page.getByTestId('status-chip')).toHaveText('Apagada');

    // Con cambios sin guardar no se puede sembrar, reiniciar ni encender.
    await page.getByTestId('displayName').fill('Cambio pendiente');
    for (const id of ['seed', 'reset', 'enable']) await expect(page.getByTestId(id)).toBeDisabled();
  });

  test('cuentas (SPEC-006A): totales, lista enmascarada con páginas, borrar con confirmación y correo de prueba', async ({ page }) => {
    await login(page, 'superadmin');
    const real = await apiCall<State & Record<string, unknown>>(page, 'GET', '/demo');
    const all = Array.from({ length: 25 }, (_, i) => ({
      id: `00000000-0000-4000-8000-${String(i).padStart(12, '0')}`,
      email: `p${i}***@ejemplo.com`,
      balance: 1000 * i,
      createdAt: new Date().toISOString(),
      lastLoginAt: new Date().toISOString(),
    }));
    const calls: string[] = [];
    let mailBody = '';
    await page.route('**/admin/api/v1/demo**', async (route) => {
      const req = route.request();
      const url = new URL(req.url());
      const path = url.pathname.replace('/admin/api/v1', '');
      const json = (body: unknown, status = 200) => route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(body) });
      if (path === '/demo' && req.method() === 'GET') return json({ ...real, accounts: { total: all.length, active7d: 3 }, mail: { mailer: 'log', host: null, port: null, from: 'x@y.co' } });
      if (path === '/demo/accounts' && req.method() === 'GET') {
        const pg = Number(url.searchParams.get('page'));
        return json({ items: all.slice((pg - 1) * 20, pg * 20), total: all.length, page: pg, perPage: 20 });
      }
      if (path.startsWith('/demo/accounts/') && req.method() === 'DELETE') {
        calls.push(`DELETE ${path}`);
        all.splice(all.findIndex((a) => path.endsWith(a.id)), 1);
        return route.fulfill({ status: 204 });
      }
      if (path === '/demo/mail/test') { mailBody = req.postData() ?? ''; return route.fulfill({ status: 204 }); }
      return route.continue();
    });
    await page.goto('/muestra');
    await expect(page.getByTestId('accounts-total')).toHaveText('25');
    await expect(page.getByTestId('accounts-active')).toHaveText('3');
    await expect(page.getByTestId('mail-mode')).toContainText('Sin envío real');
    await expect(page.getByTestId('mail-warning')).toBeVisible();
    await expect(page.getByTestId('account-row')).toHaveCount(20);
    await expect(page.getByTestId('account-row').first()).toContainText('p0***@ejemplo.com');
    await expect(page.getByTestId('accounts-page')).toHaveText('Página 1 de 2');
    await page.getByTestId('accounts-next').click();
    await expect(page.getByTestId('account-row')).toHaveCount(5);
    await expect(page.getByTestId('accounts-next')).toBeDisabled();

    // Borrar pide confirmación; cancelar no llama al servidor.
    await page.getByTestId('account-delete').first().click();
    await expect(page.getByTestId('account-confirm')).toContainText('p20***@ejemplo.com');
    await page.getByTestId('account-confirm-no').click();
    expect(calls).toEqual([]);
    await page.getByTestId('account-delete').first().click();
    await page.getByTestId('account-confirm-yes').click();
    await expect(page.getByTestId('accounts-total')).toHaveText('24');
    expect(calls).toEqual(['DELETE /demo/accounts/00000000-0000-4000-8000-000000000020']);

    // Correo de prueba.
    await expect(page.getByTestId('mail-test')).toBeDisabled();
    await page.getByTestId('mail-to').fill('ana@ejemplo.com');
    await page.getByTestId('mail-test').click();
    await expect(page.getByTestId('message')).toContainText('Correo de prueba enviado');
    expect(JSON.parse(mailBody)).toEqual({ to: 'ana@ejemplo.com' });

    const res = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa']).analyze();
    expect(res.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical'), JSON.stringify(res.violations.map((v) => [v.id, v.nodes[0]?.html?.slice(0, 120)]))).toEqual([]);
  });

  test('reiniciar obliga a elegir qué pasa con las cuentas y manda la elección', async ({ page }) => {
    await login(page, 'superadmin');
    const real = await apiCall<State & Record<string, unknown>>(page, 'GET', '/demo');
    const bodies: string[] = [];
    await page.route('**/admin/api/v1/demo**', async (route) => {
      const req = route.request();
      const path = new URL(req.url()).pathname.replace('/admin/api/v1', '');
      if (path === '/demo' && req.method() === 'GET') return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ...real, configured: true, status: { ...real.status, serverAllowed: true } }) });
      if (path === '/demo/reset') { bodies.push(req.postData() ?? ''); return route.fulfill({ status: 202, contentType: 'application/json', body: JSON.stringify({ runId: 9 }) }); }
      if (path === '/demo/runs/9') return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ id: 9, kind: 'reset', state: 'running', error: null, requestedBy: 'x', createdAt: new Date().toISOString(), startedAt: null, finishedAt: null }) });
      return route.continue();
    });
    await page.goto('/muestra');
    for (const choice of ['reset-keep', 'reset-delete']) {
      await page.getByTestId('reset').click();
      await expect(page.getByTestId('reset-choice')).toBeVisible();
      await expect(page.getByTestId('reset-keep')).not.toBeChecked();
      await expect(page.getByTestId('reset-delete')).not.toBeChecked(); // sin valor por defecto
      await expect(page.getByTestId('confirm-yes')).toBeDisabled();
      await page.getByTestId(choice).check();
      await expect(page.getByTestId('confirm-yes')).toBeEnabled();
      await page.getByTestId('confirm-yes').click();
      await expect(page.getByTestId('message')).toContainText('Reinicio pedido');
      await expect.poll(() => bodies.length).toBe(choice === 'reset-keep' ? 1 : 2);
      await page.reload();
    }
    expect(bodies.map((b) => JSON.parse(b))).toEqual([{ accounts: 'keep' }, { accounts: 'delete' }]);
  });

  test('parámetros de las cuentas: se editan, validan contra los techos y se guardan', async ({ page }) => {
    await login(page, 'superadmin');
    await apiCall(page, 'POST', '/demo/disable').catch(() => undefined);
    const original = (await apiCall<State>(page, 'GET', '/demo')).config;
    await apiCall(page, 'PUT', '/demo/config', { ...KNOWN, accounts: { retentionDays: 30, codeTtlMinutes: 10, maxAccounts: 500 } });
    try {
      await page.goto('/muestra');
      await expect(page.getByTestId('retentionDays')).toHaveValue('30');
      await page.getByTestId('retentionDays').fill('0');
      await expect(page.getByTestId('problems')).toContainText('Retención de cuentas');
      await expect(page.getByTestId('save')).toBeDisabled();
      await page.getByTestId('codeTtlMinutes').fill('31');
      await expect(page.getByTestId('problems')).toContainText('Vigencia del código');
      await page.getByTestId('retentionDays').fill('60');
      await page.getByTestId('codeTtlMinutes').fill('15');
      await page.getByTestId('maxAccounts').fill('200');
      await expect(page.getByTestId('problems')).toHaveCount(0);
      await page.getByTestId('save').click();
      await expect(page.getByTestId('message')).toContainText('Configuración guardada');
      const saved = (await apiCall<State>(page, 'GET', '/demo')).config as { accounts: unknown };
      expect(saved.accounts).toEqual({ retentionDays: 60, codeTtlMinutes: 15, maxAccounts: 200 });
    } finally {
      await apiCall(page, 'PUT', '/demo/config', original).catch(() => undefined);
    }
  });

  test('correo de prueba: un correo inválido muestra el mensaje del servidor', async ({ page }) => {
    await login(page, 'superadmin');
    await page.goto('/muestra');
    await expect(page.getByTestId('mail-mode')).not.toBeEmpty();
    await page.getByTestId('mail-to').fill('Esto no es un correo');
    await page.getByTestId('mail-test').click();
    await expect(page.getByTestId('fail')).toContainText('correo válido');
  });
});
