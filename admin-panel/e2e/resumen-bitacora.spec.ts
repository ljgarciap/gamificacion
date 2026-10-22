import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import { login } from './support';

const API = '/admin/api/v1';

test.describe('Resumen y Bitácora (P8, grupo 1)', () => {
  test('el resumen muestra lo mismo que dice la API y 14 días de canjes', async ({ page }) => {
    await login(page, 'superadmin');
    const d = await (await page.request.get(`${API}/dashboard`)).json();
    await expect(page.getByRole('heading', { name: 'Resumen' })).toBeVisible();
    await expect(page.getByTestId('campaigns')).toHaveText(String(d.campaigns));
    await expect(page.getByTestId('codes')).toHaveText(`${d.activeCodes.redeemed} / ${d.activeCodes.total}`);
    await expect(page.getByTestId('outbox-failed')).toHaveText(String(d.outbox.failed));
    await expect(page.getByTestId('by-day').locator('rect')).toHaveCount(14);
    if (d.stockByBatch.length === 0) await expect(page.getByTestId('no-stock')).toBeVisible();
    else for (const b of d.stockByBatch) await expect(page.getByRole('list', { name: `Existencias de ${b.name}` })).toBeVisible();
  });

  test('un fallo del servidor se dice y se puede reintentar', async ({ page }) => {
    await login(page, 'operator');
    let fail = true;
    await page.route(`**${API}/dashboard`, (route) => (fail ? route.fulfill({ status: 500, json: { error: { code: 'serverError', message: 'Algo falló.' } } }) : route.continue()));
    await page.getByRole('link', { name: 'Campañas' }).click();
    await page.getByRole('link', { name: 'Resumen' }).click();
    await expect(page.getByTestId('load-error')).toContainText('Algo falló.');
    fail = false;
    await page.getByRole('button', { name: 'Reintentar' }).click();
    await expect(page.getByTestId('campaigns')).toBeVisible();
  });

  test('la bitácora lista entradas, filtra y verifica la cadena completa', async ({ page }) => {
    await login(page, 'superadmin');
    await page.getByRole('link', { name: 'Bitácora' }).click();
    const rows = page.getByTestId('audit-row');
    await expect(rows.first()).toBeVisible();

    const first = await page.request.get(`${API}/audit?perPage=1`);
    const top = (await first.json()).items[0];
    await expect(rows.first().locator('td').first()).toHaveText(String(top.id));

    // Filtrar por la acción de la primera fila: todas las filas la comparten.
    await page.getByLabel('Acción', { exact: true }).fill(top.action);
    await page.getByRole('button', { name: 'Filtrar' }).click();
    await expect(rows.first()).toBeVisible();
    for (const cell of await rows.locator('td:nth-child(4)').allInnerTexts()) expect(cell.trim()).toBe(top.action);

    await page.getByLabel('Acción', { exact: true }).fill('accion.que.no.existe');
    await page.getByRole('button', { name: 'Filtrar' }).click();
    await expect(page.getByTestId('empty')).toBeVisible();
    await page.getByRole('button', { name: 'Limpiar' }).click();
    await expect(rows.first()).toBeVisible();

    await rows.first().getByRole('button', { name: /Ver/ }).click();
    await expect(rows.first().locator('pre')).toBeVisible();

    await page.getByTestId('verify').click();
    await expect(page.getByTestId('verify-result')).toContainText('Cadena íntegra', { timeout: 30_000 });
  });

  test('el ancla mostrada es la de la API', async ({ page }) => {
    await login(page, 'auditor');
    await page.getByRole('link', { name: 'Bitácora' }).click();
    const { anchor } = await (await page.request.get(`${API}/audit/anchor`)).json();
    if (anchor === null) await expect(page.getByTestId('no-anchor')).toBeVisible();
    else await expect(page.getByTestId('anchor')).toContainText(anchor.head_hash);
  });

  test('si la cadena se rompe, la pantalla dice dónde; si hay límite, cuánto esperar', async ({ page }) => {
    await login(page, 'superadmin');
    await page.getByRole('link', { name: 'Bitácora' }).click();
    await page.route(`**${API}/audit/verify*`, (route) => route.fulfill({ json: { checked: 7, brokenAt: 42, lastId: 42, lastHash: 'x', done: false } }));
    await page.getByTestId('verify').click();
    await expect(page.getByTestId('verify-result')).toContainText('se rompe en la entrada #42');
    await page.unroute(`**${API}/audit/verify*`);
    await page.route(`**${API}/audit/verify*`, (route) => route.fulfill({ status: 429, json: { error: { code: 'rateLimited', message: 'Muchas.', retryAfter: 17 } } }));
    await page.getByTestId('verify').click();
    await expect(page.getByTestId('verify-result')).toContainText('Espera 17 s');
  });

  test('el auditor ve ambas pantallas sin ninguna acción de escritura', async ({ page }) => {
    await login(page, 'auditor');
    await expect(page.getByRole('heading', { name: 'Resumen' })).toBeVisible();
    await page.getByRole('link', { name: 'Bitácora' }).click();
    await expect(page.getByTestId('audit-row').first()).toBeVisible();
    const buttons = (await page.getByRole('button').allInnerTexts()).map((t) => t.trim());
    for (const b of buttons) expect(b).toMatch(/Ver|Ocultar|Verificar|Filtrar|Limpiar|Cargar|Copiar|Cerrar sesión|detalle/);
  });

  test('UA-9/UA-10: sin violaciones graves de axe y sin desbordes a 1280 y 768 px', async ({ page }) => {
    await login(page, 'superadmin');
    for (const [path, name] of [['/', 'resumen'], ['/auditoria', 'bitacora']] as const) {
      for (const width of [1280, 768]) {
        await page.setViewportSize({ width, height: 900 });
        await page.goto(path);
        await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
        if (path === '/auditoria') await expect(page.getByTestId('audit-row').first()).toBeVisible();
        else await expect(page.getByTestId('campaigns')).toBeVisible();
        const over = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
        expect(over, `${name} a ${width}px`).toBeLessThanOrEqual(0);
        await page.screenshot({ path: `test-results/${name}-${width}.png`, fullPage: true });
      }
      const res = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa']).analyze();
      expect(res.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical'), JSON.stringify(res.violations.map((v) => v.id))).toEqual([]);
    }
  });
});
