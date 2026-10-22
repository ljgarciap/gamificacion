import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import { codeAt } from './totp';
import { fixtures, login } from './support';

test.describe('Acceso y guardas (P7)', () => {
  test('sin sesión una ruta protegida lleva al acceso y, al entrar, se vuelve a ella', async ({ page }) => {
    await page.goto('/lotes');
    await expect(page).toHaveURL(/\/acceso\?volver=%2Flotes/);
    await login(page, 'superadmin', '/lotes');
    await expect(page).toHaveURL(/\/lotes$/);
    await expect(page.getByRole('heading', { name: 'Lotes' })).toBeVisible();
  });

  test('un fallo de credenciales dice lo mismo exista o no la cuenta', async ({ page }) => {
    const f = fixtures();
    const messages: string[] = [];
    for (const email of [f.users.operator.email, 'nadie-existe@ejemplo.com']) {
      await page.goto('/acceso');
      await page.getByLabel('Correo', { exact: true }).fill(email);
      await page.getByLabel('Contraseña', { exact: true }).fill('una-contrasena-equivocada');
      await page.getByRole('button', { name: 'Entrar' }).click();
      await expect(page.getByTestId('error')).toBeVisible();
      messages.push((await page.getByTestId('error').innerText()).trim());
    }
    expect(messages[0]).toBe(messages[1]);
    expect(messages[0]).toMatch(/incorrect/i);
  });

  test('el superadmin ve todas las secciones', async ({ page }) => {
    await login(page, 'superadmin');
    const nav = page.getByRole('navigation', { name: 'Secciones del panel' });
    for (const name of ['Resumen', 'Campañas', 'Lotes', 'Aprobaciones', 'Avisos al host', 'Buscar código', 'Bitácora', 'Hosts', 'Parámetros', 'Usuarios', 'Mi cuenta']) {
      await expect(nav.getByRole('link', { name })).toBeVisible();
    }
  });

  test('UA-3: el auditor no ve secciones de escritura y la ruta directa dice «sin acceso»', async ({ page }) => {
    await login(page, 'auditor');
    const nav = page.getByRole('navigation', { name: 'Secciones del panel' });
    for (const name of ['Hosts', 'Parámetros', 'Usuarios']) await expect(nav.getByRole('link', { name })).toHaveCount(0);
    for (const name of ['Resumen', 'Campañas', 'Lotes', 'Bitácora']) await expect(nav.getByRole('link', { name })).toBeVisible();
    await page.goto('/usuarios');
    await expect(page.getByRole('heading', { name: 'No tienes acceso a esta sección' })).toBeVisible();
  });

  test('UA-4: primer ingreso: contraseña temporal, enrolamiento y códigos de recuperación de una sola vez', async ({ page }) => {
    const f = fixtures();
    await page.goto('/acceso');
    await page.getByLabel('Correo', { exact: true }).fill(f.fresh.email);
    await page.getByLabel('Contraseña', { exact: true }).fill(f.fresh.tempPassword);
    await page.getByRole('button', { name: 'Entrar' }).click();

    // Contraseña nueva: una floja se rechaza diciendo qué falta; una buena avanza.
    await page.getByLabel('Contraseña actual', { exact: true }).fill(f.fresh.tempPassword);
    await page.getByLabel('Contraseña nueva', { exact: true }).fill('corta');
    await page.getByLabel('Repite la contraseña nueva').fill('corta');
    await page.getByRole('button', { name: 'Cambiar contraseña' }).click();
    await expect(page.getByTestId('error')).toBeVisible();
    const good = 'Nube-Verde-Tractor-2026!';
    await page.getByLabel('Contraseña nueva', { exact: true }).fill(good);
    await page.getByLabel('Repite la contraseña nueva').fill(good);
    await page.getByRole('button', { name: 'Cambiar contraseña' }).click();

    // Enrolamiento: QR local y clave; el código se calcula con la clave mostrada.
    await expect(page.getByAltText('Código QR para tu app de autenticación')).toBeVisible();
    const secret = (await page.getByTestId('enroll-secret').innerText()).trim();
    expect(secret).toMatch(/^[A-Z2-7]{16,}$/);
    await page.getByLabel('Código de 6 dígitos', { exact: true }).fill(codeAt(secret, Math.floor(Date.now() / 1000 / 30)));
    await page.getByRole('button', { name: 'Confirmar' }).click();

    // Códigos de recuperación: 10, y no se puede seguir sin confirmar que se guardaron.
    await expect(page.getByTestId('recovery-codes').locator('li')).toHaveCount(10);
    const codes = await page.getByTestId('recovery-codes').locator('li').allInnerTexts();
    const cont = page.getByRole('button', { name: 'Continuar' });
    await expect(cont).toBeDisabled();
    await page.getByRole('checkbox', { name: 'Los guardé en un lugar seguro' }).check();
    await cont.click();
    await expect(page.getByTestId('who')).toBeVisible();

    // Una sola vez: ya dentro, ninguna pantalla vuelve a mostrar los códigos.
    for (const path of ['/', '/cuenta']) {
      await page.goto(path);
      const text = await page.locator('body').innerText();
      for (const c of codes) expect(text).not.toContain(c.trim());
    }
  });

  test('UA-8: aviso antes de vencer por inactividad y, al vencer, vuelve al acceso con la ruta', async ({ page }) => {
    await page.clock.install({ time: Date.now() });
    await login(page, 'operator', '/campanas');
    await expect(page.getByTestId('idle-warning')).toHaveCount(0);

    await page.clock.fastForward((30 * 60 - 90) * 1000); // quedan 90 s
    await expect(page.getByTestId('idle-warning')).toBeVisible();
    await page.getByRole('button', { name: 'Seguir conectado' }).click();
    await expect(page.getByTestId('idle-warning')).toHaveCount(0);

    await page.clock.fastForward(31 * 60 * 1000);
    await expect(page).toHaveURL(/\/acceso\?volver=%2Fcampanas&vencida=1/);
    await expect(page.getByTestId('expired')).toBeVisible();
  });

  test('UA-9: sin violaciones graves de accesibilidad en el acceso y en el panel', async ({ page }) => {
    await page.goto('/acceso');
    await expect(page.getByRole('button', { name: 'Entrar' })).toBeVisible();
    const loginResults = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa']).analyze();
    expect(loginResults.violations.filter((v) => ['serious', 'critical'].includes(v.impact ?? ''))).toEqual([]);

    await login(page, 'superadmin');
    const shellResults = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa']).analyze();
    expect(shellResults.violations.filter((v) => ['serious', 'critical'].includes(v.impact ?? ''))).toEqual([]);
  });

  test('UA-10: sin desbordes horizontales en 1280 px y 768 px (acceso y panel)', async ({ page }) => {
    for (const width of [1280, 768]) {
      await page.setViewportSize({ width, height: 800 });
      await page.goto('/acceso');
      await expect(page.getByRole('button', { name: 'Entrar' })).toBeVisible();
      await page.screenshot({ path: `test-results/acceso-${width}.png` });
      expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), `acceso ${width}`).toBe(true);
    }
    await login(page, 'superadmin');
    for (const width of [1280, 768]) {
      await page.setViewportSize({ width, height: 800 });
      await page.screenshot({ path: `test-results/panel-${width}.png` });
      expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), `panel ${width}`).toBe(true);
    }
  });
});
