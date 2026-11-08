import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import { apiCall, login } from './support';

interface State { configured: boolean; config: Record<string, unknown>; status: { serverAllowed: boolean; enabled: boolean } }

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
    const original = (await apiCall<State>(page, 'GET', '/demo')).config;
    try {
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
});
