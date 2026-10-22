import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import { fixtures, login } from './support';

test.describe('Mi cuenta (P8)', () => {
  test('muestra nombre, correo y rol, y es accesible sin desbordes', async ({ page }) => {
    const f = fixtures();
    await login(page, 'auditor');
    await page.goto('/cuenta');
    await expect(page.getByRole('heading', { name: 'Mi cuenta', level: 1 })).toBeVisible();
    await expect(page.getByTestId('account-email')).toHaveText(f.users.auditor.email);
    await expect(page.getByTestId('account-role')).toHaveText('Auditor');
    const bad = (await new AxeBuilder({ page }).analyze()).violations.filter((v) => v.impact === 'serious' || v.impact === 'critical');
    expect(bad.map((v) => v.id)).toEqual([]);
    for (const width of [1280, 768]) {
      await page.setViewportSize({ width, height: 900 });
      expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
    }
  });

  test('cambiar la contraseña: los errores se dicen sin sacar al usuario, y al lograrlo se cierran las otras sesiones', async ({ page, browser, baseURL }) => {
    const f = fixtures();
    const original = f.password;
    const nueva = 'Nube-Verde-Tractor-2027!';
    await login(page, 'auditor');
    const otra = await browser.newContext({ baseURL });
    const second = await otra.newPage();
    await login(second, 'auditor');

    await page.goto('/cuenta');
    const fill = async (cur: string, nw: string, rep: string) => {
      await page.getByLabel('Contraseña actual', { exact: true }).fill(cur);
      await page.getByLabel('Contraseña nueva', { exact: true }).fill(nw);
      await page.getByLabel('Repite la contraseña nueva').fill(rep);
      await page.getByRole('button', { name: 'Cambiar contraseña' }).click();
    };

    // Contraseña actual equivocada: error del formulario, la sesión sigue viva (no es un 401 de sesión vencida).
    await fill('una-contrasena-equivocada', nueva, nueva);
    await expect(page.getByTestId('error')).toContainText('actual no es correcta');
    await expect(page).toHaveURL(/\/cuenta$/);
    await expect(page.getByTestId('who')).toBeVisible();
    // No coinciden, y floja: el servidor dice qué falta.
    await fill(original, nueva, nueva + 'x');
    await expect(page.getByTestId('error')).toContainText('no coinciden');
    await fill(original, 'corta', 'corta');
    await expect(page.getByTestId('error')).not.toHaveText('');
    await expect(page.getByTestId('done')).toHaveCount(0);

    // Bueno: se confirma, y la otra sesión queda cerrada.
    await fill(original, nueva, nueva);
    await expect(page.getByTestId('done')).toBeVisible();
    await expect(page.getByTestId('who')).toBeVisible();
    await second.goto('/');
    await expect(second).toHaveURL(/\/acceso/);
    await otra.close();

    // Se restablece la original para no romper las demás pruebas.
    await fill(nueva, original, original);
    await expect(page.getByTestId('done')).toBeVisible();
  });

  test('un token CSRF vencido en una escritura devuelve al acceso recordando la ruta (revisión P10)', async ({ page }) => {
    await login(page, 'superadmin');
    await page.goto('/usuarios');
    await page.route('**/admin/api/v1/users', (route) =>
      route.request().method() === 'POST'
        ? route.fulfill({ status: 419, contentType: 'application/json', body: JSON.stringify({ error: { code: 'csrfMismatch', message: 'Token CSRF inválido o ausente.' } }) })
        : route.continue(),
    );
    await page.getByLabel('Correo', { exact: true }).fill('nadie@ejemplo.com');
    await page.getByLabel('Nombre', { exact: true }).fill('Nadie');
    await page.getByRole('button', { name: 'Crear usuario' }).click();
    await expect(page).toHaveURL(/\/acceso\?volver=%2Fusuarios/);
  });
});
