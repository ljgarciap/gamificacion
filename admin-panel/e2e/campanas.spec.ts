import AxeBuilder from '@axe-core/playwright';
import { expect, test, Page } from '@playwright/test';
import { login } from './support';

const API = '/admin/api/v1';
const unique = () => `E2E campaña ${Date.now().toString(36)}`;

async function create(page: Page, name: string): Promise<void> {
  await page.goto('/campanas');
  await page.getByTestId('new-campaign').click();
  await page.getByLabel('Nombre', { exact: true }).fill(name);
  await page.getByRole('button', { name: 'Crear campaña' }).click();
  await expect(page).toHaveURL(/\/campanas\/[0-9a-f-]{36}$/);
  await expect(page.getByRole('heading', { level: 1, name })).toBeVisible();
}

test.describe('Campañas (P8, grupo 3)', () => {
  test('el operador crea una campaña y administra sus premios (alta, edición, baja con confirmación)', async ({ page }) => {
    await login(page, 'operator');
    const name = unique();
    await create(page, name);

    await page.getByRole('tab', { name: 'Premios' }).click();
    await expect(page.getByTestId('no-prizes')).toBeVisible();

    // Alta de un premio de dinero.
    await page.getByLabel('Id del premio').fill('p50k');
    await page.getByLabel('Etiqueta', { exact: true }).fill('$ 50.000');
    await page.getByLabel('Monto (COP, entero)').fill('50000');
    await page.getByRole('button', { name: 'Agregar premio' }).click();
    await expect(page.getByTestId('prize-row')).toHaveCount(1);
    await expect(page.getByTestId('prize-row').first()).toContainText('$ 50.000');

    // Alta de un giro extra con 3 giros.
    await page.getByLabel('Id del premio').fill('extra');
    await page.getByRole('combobox', { name: 'Tipo' }).click();
    await page.getByRole('option', { name: 'Giro extra' }).click();
    await page.getByLabel('Etiqueta', { exact: true }).fill('Gira otra vez');
    await page.getByLabel('Giros por premio').fill('3');
    await page.getByRole('button', { name: 'Agregar premio' }).click();
    const extra = page.getByTestId('prize-row').filter({ hasText: 'extra' });
    await expect(extra).toContainText('Giro extra');
    await expect(extra.locator('td').nth(4)).toHaveText('3');

    // Un premio de dinero sin monto lo rechaza el servidor y el mensaje queda junto al formulario.
    await page.getByLabel('Id del premio').fill('malo');
    await page.getByLabel('Etiqueta', { exact: true }).fill('Sin monto');
    await page.getByRole('button', { name: 'Agregar premio' }).click();
    await expect(page.getByTestId('prize-error')).toContainText('Indica el monto');
    await expect(page.getByTestId('prize-row')).toHaveCount(2);

    // Edición: el id no se edita.
    await page.getByRole('button', { name: /Editar el premio p50k/ }).click();
    await expect(page.getByLabel('Id del premio')).toBeDisabled();
    await page.getByLabel('Etiqueta', { exact: true }).fill('Cincuenta mil');
    await page.getByRole('button', { name: 'Guardar premio' }).click();
    await expect(page.getByTestId('prize-row').first()).toContainText('Cincuenta mil');

    // Baja: cancelar la conserva; confirmar la elimina.
    await page.getByTestId('delete-extra').click();
    await page.getByRole('button', { name: 'Cancelar' }).click();
    await expect(page.getByTestId('prize-row')).toHaveCount(2);
    await page.getByTestId('delete-extra').click();
    await page.getByTestId('confirm-delete-extra').click();
    await expect(page.getByTestId('prize-row')).toHaveCount(1);

    // Y la lista la muestra con 1 premio, editable.
    await page.getByRole('link', { name: '← Campañas' }).click();
    const row = page.getByTestId('campaign-row').filter({ hasText: name });
    await expect(row).toContainText('Editable');
    await expect(row.locator('td').nth(3)).toHaveText('1');
    const stored = await (await page.request.get(`${API}/campaigns`)).json();
    expect(stored.campaigns.find((c: { name: string }) => c.name === name).prizeCount).toBe(1);
  });

  test('el tema y la configuración del juego se validan en el cliente antes de enviarse', async ({ page }) => {
    await login(page, 'operator');
    await create(page, unique());
    const patches: string[] = [];
    page.on('request', (r) => { if (r.method() === 'PATCH') patches.push(r.url()); });
    await page.getByRole('tab', { name: 'Tema' }).click();
    await page.getByLabel('Principal', { exact: true }).fill('azul');
    await page.getByRole('button', { name: 'Guardar tema' }).click();
    await expect(page.getByRole('alert').filter({ hasText: 'usa el formato #RRGGBB' })).toBeVisible();
    expect(patches, 'con un color inválido no sale ninguna petición').toHaveLength(0);
    await page.getByLabel('Principal', { exact: true }).fill('#0B4EF0');
    await page.getByRole('button', { name: 'Ver JSON' }).click();
    await expect(page.getByTestId('theme-json')).toContainText('"primary": "#0B4EF0"');
    await page.getByRole('button', { name: 'Guardar tema' }).click();
    await expect(page.getByTestId('saved')).toHaveText('Tema guardado.');

    await page.getByRole('tab', { name: 'Juego' }).click();
    const before = patches.length;
    await page.getByTestId('game-json').fill('{esto no es json');
    await page.getByRole('button', { name: 'Guardar configuración' }).click();
    await expect(page.getByTestId('game-error')).toContainText('JSON válido');
    expect(patches.length, 'un JSON inválido no se envía').toBe(before);
    await page.getByRole('button', { name: /Usar un ejemplo de Ruleta/ }).click();
    await page.getByRole('button', { name: 'Guardar configuración' }).click();
    await expect(page.getByTestId('saved')).toHaveText('Configuración guardada.');
  });

  test('UA-5: una campaña congelada deshabilita lo que la base impone, con el motivo, y solo envía lo editable', async ({ page }) => {
    await login(page, 'superadmin');
    const { campaigns } = await (await page.request.get(`${API}/campaigns`)).json();
    const frozen = campaigns.find((c: { frozen: boolean }) => c.frozen);
    expect(frozen, 'la base de desarrollo trae campañas demo con lote comprometido').toBeTruthy();
    const bodies: Record<string, unknown>[] = [];
    await page.route(new RegExp(`${API}/campaigns/${frozen.id}$`), (route) => {
      if (route.request().method() === 'PATCH') { bodies.push(route.request().postDataJSON()); return route.fulfill({ status: 204 }); }
      return route.continue();
    });
    await page.goto(`/campanas/${frozen.id}`);
    await expect(page.getByTestId('frozen-badge')).toBeVisible();
    await expect(page.getByTestId('game-type')).toHaveAttribute('aria-disabled', 'true');
    await expect(page.getByTestId('reason-game')).toContainText('Tiene un lote comprometido');
    await expect(page.getByLabel('Título', { exact: true })).toBeEnabled();
    await page.getByRole('tab', { name: 'Juego' }).click();
    await expect(page.getByTestId('game-json')).toBeDisabled();
    await expect(page.getByTestId('reason-config')).toContainText('Tiene un lote comprometido');
    await expect(page.getByRole('button', { name: 'Guardar configuración' })).toHaveCount(0);
    await page.getByRole('tab', { name: 'General' }).click();
    await page.getByRole('button', { name: 'Guardar cambios' }).click();
    await expect(page.getByTestId('saved')).toBeVisible();
    expect(bodies).toHaveLength(1);
    expect(Object.keys(bodies[0]).sort()).toEqual(['name', 'title']);
  });

  test('un 409 residual al editar un premio usado se muestra junto a su fila', async ({ page }) => {
    await login(page, 'operator');
    const { campaigns } = await (await page.request.get(`${API}/campaigns`)).json();
    const frozen = campaigns.find((c: { frozen: boolean; prizeCount: number }) => c.frozen && c.prizeCount > 0);
    await page.route(new RegExp(`${API}/campaigns/${frozen.id}/prizes/.*`), (r) =>
      r.fulfill({ status: 409, json: { error: { code: 'campaignFrozen', message: 'La campaña tiene un lote comprometido.' } } }),
    );
    await page.goto(`/campanas/${frozen.id}`);
    await page.getByRole('tab', { name: 'Premios' }).click();
    const first = page.getByTestId('prize-row').first();
    const id = (await first.locator('code').innerText()).trim();
    await page.getByTestId(`delete-${id}`).click();
    await page.getByTestId(`confirm-delete-${id}`).click();
    await expect(page.getByTestId(`row-error-${id}`)).toContainText('lote comprometido');
    await expect(page.getByTestId('prize-row').first()).toBeVisible();
  });

  test('el auditor ve las campañas sin ninguna acción de escritura', async ({ page }) => {
    await login(page, 'auditor');
    await page.goto('/campanas');
    await expect(page.getByTestId('campaign-row').first()).toBeVisible();
    await expect(page.getByTestId('new-campaign')).toHaveCount(0);
    await page.getByTestId('campaign-row').first().getByRole('link').click();
    await expect(page.getByText('Solo lectura')).toBeVisible();
    await expect(page.getByLabel('Nombre', { exact: true })).toBeDisabled();
    for (const name of ['Guardar cambios', 'Guardar tema', 'Agregar premio']) await expect(page.getByRole('button', { name })).toHaveCount(0);
    await page.goto('/campanas/nueva');
    await expect(page.getByText('No tienes acceso a esta sección')).toBeVisible();
  });

  test('el filtro por juego de la lista', async ({ page }) => {
    await login(page, 'operator');
    await page.goto('/campanas');
    await expect(page.getByTestId('campaign-row').first()).toBeVisible();
    await page.getByLabel('Filtrar por juego').selectOption('dice');
    for (const t of await page.locator('[data-testid=campaign-row] td:nth-child(2)').allInnerTexts()) expect(t).toBe('Dados');
    await page.getByLabel('Filtrar por juego').selectOption('scratch');
    for (const t of await page.locator('[data-testid=campaign-row] td:nth-child(2)').allInnerTexts()) expect(t).toBe('Raspa y Gana');
  });

  test('UA-9/UA-10: axe y sin desbordes en la lista y en el detalle', async ({ page }) => {
    await login(page, 'superadmin');
    const { campaigns } = await (await page.request.get(`${API}/campaigns`)).json();
    const id = campaigns.find((c: { prizeCount: number }) => c.prizeCount > 0).id;
    for (const [path, name] of [['/campanas', 'campanas'], [`/campanas/${id}`, 'campana-detalle']] as const) {
      for (const width of [1280, 768]) {
        await page.setViewportSize({ width, height: 900 });
        await page.goto(path);
        await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
        if (path === '/campanas') await expect(page.getByTestId('campaign-row').first()).toBeVisible();
        else await page.getByRole('tab', { name: 'Premios' }).click();
        expect(await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth), `${name} a ${width}px`).toBeLessThanOrEqual(0);
        await page.screenshot({ path: `test-results/${name}-${width}.png`, fullPage: true });
      }
      const res = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa']).analyze();
      expect(res.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical'), JSON.stringify(res.violations.map((v) => [v.id, v.nodes[0]?.html?.slice(0, 120)]))).toEqual([]);
    }
  });
});
