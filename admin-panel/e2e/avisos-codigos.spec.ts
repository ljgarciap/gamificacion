import AxeBuilder from '@axe-core/playwright';
import { expect, test, Page } from '@playwright/test';
import { login } from './support';

const API = '/admin/api/v1';

const failedItem = {
  id: 9001, redemptionId: '11111111-2222-3333-4444-555555555555', status: 'failed', attempts: 5, nextAttemptAt: null,
  lastError: 'El host respondió 503 al acreditar el premio y no hubo más reintentos disponibles.', createdAt: '2026-10-20T15:00:00Z', sentAt: null,
  userId: 'host-a:u1', batchId: 'b', prizeId: 'p10k', prizeType: 'money',
};

/** Simula una cola con un aviso fallido; el reintento lo saca de la cola. */
async function mockOutbox(page: Page, retryStatus = 204) {
  const state = { failed: true, retried: [] as { url: string; xsrf: string | undefined }[] };
  await page.route(`**${API}/outbox/*/retry`, (route) => {
    state.retried.push({ url: route.request().url(), xsrf: route.request().headers()['x-xsrf-token'] });
    if (retryStatus === 204) state.failed = false;
    return retryStatus === 204 ? route.fulfill({ status: 204 }) : route.fulfill({ status: retryStatus, json: { error: { code: 'wrongState', message: 'Solo se reintenta un aviso fallido.' } } });
  });
  await page.route(`**${API}/outbox?*`, (route) =>
    route.fulfill({
      json: {
        items: state.failed ? [failedItem] : [], total: state.failed ? 1 : 0,
        counts: { pending: 2, sent: 7, failed: state.failed ? 1 : 0 }, page: 1, perPage: 25,
      },
    }),
  );
  return state;
}

test.describe('Avisos al host (P8, grupo 2)', () => {
  test('los contadores son los de la API y el filtro por estado queda en la URL', async ({ page }) => {
    await login(page, 'superadmin');
    await page.getByRole('link', { name: 'Avisos al host' }).click();
    const o = await (await page.request.get(`${API}/outbox`)).json();
    await expect(page.getByTestId('filter-pending')).toContainText(`Pendientes: ${o.counts.pending}`);
    await expect(page.getByTestId('filter-failed')).toContainText(`Fallidos: ${o.counts.failed}`);
    await page.getByTestId('filter-failed').click();
    await expect(page).toHaveURL(/estado=failed/);
    for (const badge of await page.locator('[data-testid=outbox-row] .badge').allInnerTexts()) expect(badge).toContain('Fallido');
  });

  test('el operador reintenta un aviso fallido: va con CSRF, se avisa y sale de la lista', async ({ page }) => {
    await login(page, 'operator');
    const state = await mockOutbox(page);
    await page.goto('/avisos?estado=failed');
    await expect(page.getByTestId('outbox-row')).toHaveCount(1);
    await expect(page.getByText('debe tratarlo como idempotente')).toBeVisible();
    await page.getByTestId('retry-9001').click();
    await expect(page.getByTestId('message')).toContainText('volvió a la cola');
    await expect(page.getByTestId('empty')).toBeVisible();
    expect(state.retried).toHaveLength(1);
    expect(state.retried[0].url).toContain('/outbox/9001/retry');
    expect(state.retried[0].xsrf, 'la petición lleva el token CSRF').toBeTruthy();
  });

  test('si el servidor rechaza el reintento, se muestra su mensaje', async ({ page }) => {
    await login(page, 'superadmin');
    await mockOutbox(page, 409);
    await page.goto('/avisos');
    await page.getByTestId('retry-9001').click();
    await expect(page.getByTestId('message')).toContainText('Solo se reintenta un aviso fallido.');
  });

  test('el auditor ve los avisos pero no tiene ni columna ni botón de reintento', async ({ page }) => {
    await login(page, 'auditor');
    await mockOutbox(page);
    await page.goto('/avisos');
    await expect(page.getByTestId('outbox-row')).toHaveCount(1);
    await expect(page.getByRole('button', { name: /Reintentar/ })).toHaveCount(0);
    await expect(page.getByRole('columnheader', { name: 'Acción' })).toHaveCount(0);
  });

  test('un fallo del servidor se dice y se puede reintentar', async ({ page }) => {
    await login(page, 'operator');
    let fail = true;
    await page.route(`**${API}/outbox?*`, (r) => (fail ? r.fulfill({ status: 500, json: { error: { code: 'serverError', message: 'Falló.' } } }) : r.continue()));
    await page.goto('/avisos');
    await expect(page.getByTestId('load-error')).toContainText('Falló.');
    fail = false;
    await page.getByRole('button', { name: 'Reintentar' }).click();
    await expect(page.getByTestId('filter-all')).toBeVisible();
  });
});

/** Un código de demostración sin canjear, con la descripción de su premio (solo existe en desarrollo). */
async function demoCodes(page: Page): Promise<{ code: string; prize: string }[]> {
  const body = await (await page.request.get('http://localhost:8000/v1/dev/demo-codes')).json();
  return body.codes
    .map((c: { code: string; description: string }) => ({ code: c.code, prize: c.description.split(': ').slice(1).join(': ') }))
    .filter((c: { prize: string }) => c.prize && c.prize !== 'Sin premio');
}

test.describe('Buscar código (P8, grupo 2)', () => {
  test('acepta el formato de una persona, no muestra el premio de un código sin canjear y no deja rastro', async ({ page }) => {
    await login(page, 'operator');
    await page.getByRole('link', { name: 'Buscar código' }).click();
    const candidates = await demoCodes(page);
    let tried = '';
    let prize = '';
    for (const c of candidates.slice(0, 6)) {
      // Minúsculas, espacios en vez de guiones, como lo dictaría una persona.
      await page.getByLabel('Código', { exact: true }).fill(c.code.toLowerCase().replace(/-/g, ' '));
      await page.getByRole('button', { name: 'Buscar' }).click();
      await expect(page.getByTestId('lookup-result')).toBeVisible();
      if ((await page.getByTestId('lookup-result').innerText()).includes('Canjeado\nNo')) { tried = c.code; prize = c.prize; break; }
    }
    expect(tried, 'hay un código demo sin canjear').not.toBe('');
    await expect(page.getByTestId('lookup-prize')).toHaveText('Premio oculto hasta revelar el lote');
    expect(await page.locator('body').innerText()).not.toContain(prize);
    // El texto escrito se borra y no hay rastro del código en la URL ni en el almacenamiento.
    await expect(page.getByLabel('Código', { exact: true })).toHaveValue('');
    expect(page.url()).not.toContain(tried.slice(7, 11));
    const stored = await page.evaluate(() => JSON.stringify([{ ...localStorage }, { ...sessionStorage }]));
    expect(stored).not.toContain(tried);
    // Y la búsqueda quedó en la bitácora.
    const top = (await (await page.request.get(`${API}/audit?action=code.lookup&perPage=1`)).json()).items[0];
    expect(top.action).toBe('code.lookup');
    expect(JSON.stringify(top)).not.toContain(tried);
  });

  test('un texto que no es un código lo dice sin más detalle', async ({ page }) => {
    await login(page, 'auditor');
    await page.goto('/codigos');
    await page.getByLabel('Código', { exact: true }).fill('esto no es un codigo');
    await page.getByRole('button', { name: 'Buscar' }).click();
    await expect(page.getByTestId('lookup-error')).toContainText('no es un código válido');
    await expect(page.getByTestId('lookup-result')).toHaveCount(0);
  });

  test('defensa en profundidad: si el servidor mandara un premio de un código sin canjear, la pantalla no lo muestra', async ({ page }) => {
    await login(page, 'operator');
    await page.route(`**${API}/codes/lookup`, (r) =>
      r.fulfill({ json: { batchId: 'b1', batchName: 'Lote', batchState: 'active', index: 3, redeemed: false, redeemedAt: null, redeemedBy: null, prizeId: 'PREMIO-FILTRADO' } }),
    );
    await page.goto('/codigos');
    await page.getByLabel('Código', { exact: true }).fill('RULETA-AAAA-BBBB-CCC');
    await page.getByRole('button', { name: 'Buscar' }).click();
    await expect(page.getByTestId('lookup-prize')).toHaveText('Premio oculto hasta revelar el lote');
    expect(await page.locator('body').innerText()).not.toContain('PREMIO-FILTRADO');
  });

  test('un código canjeado muestra cuándo, por quién (seudónimo) y su premio', async ({ page }) => {
    await login(page, 'operator');
    await page.route(`**${API}/codes/lookup`, (r) =>
      r.fulfill({ json: { batchId: 'b1', batchName: 'Lote de prueba', batchState: 'active', index: 12, redeemed: true, redeemedAt: '2026-10-20T15:00:00Z', redeemedBy: 'host-a:u7', prizeId: 'p50k' } }),
    );
    await page.goto('/codigos');
    await page.getByLabel('Código', { exact: true }).fill('RULETA-AAAA-BBBB-CCC');
    await page.getByRole('button', { name: 'Buscar' }).click();
    const r = page.getByTestId('lookup-result');
    await expect(r).toContainText('Lote de prueba');
    await expect(r).toContainText('host-a:u7');
    await expect(page.getByTestId('lookup-prize')).toHaveText('p50k');
  });

  test('con el límite de búsquedas se muestra la cuenta regresiva y se bloquea el botón', async ({ page }) => {
    await login(page, 'operator');
    await page.route(`**${API}/codes/lookup`, (r) => r.fulfill({ status: 429, json: { error: { code: 'rateLimited', message: 'Demasiadas búsquedas; espera un momento.', retryAfter: 9 } } }));
    await page.goto('/codigos');
    await page.getByLabel('Código', { exact: true }).fill('RULETA-AAAA-BBBB-CCC');
    await page.getByRole('button', { name: 'Buscar' }).click();
    await expect(page.getByTestId('lookup-error')).toContainText('Espera');
    await expect(page.getByRole('button', { name: 'Buscar' })).toBeDisabled();
  });
});

test('UA-9/UA-10: axe sin violaciones graves y sin desbordes en Avisos y Buscar código', async ({ page }) => {
  await login(page, 'superadmin');
  await mockOutbox(page);
  for (const [path, name] of [['/avisos', 'avisos'], ['/codigos', 'codigos']] as const) {
    for (const width of [1280, 768]) {
      await page.setViewportSize({ width, height: 900 });
      await page.goto(path);
      await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
      if (path === '/avisos') await expect(page.getByTestId('outbox-row')).toBeVisible();
      expect(await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth), `${name} a ${width}px`).toBeLessThanOrEqual(0);
      await page.screenshot({ path: `test-results/${name}-${width}.png`, fullPage: true });
    }
    const res = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa']).analyze();
    expect(res.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical'), JSON.stringify(res.violations.map((v) => v.id))).toEqual([]);
  }
});
