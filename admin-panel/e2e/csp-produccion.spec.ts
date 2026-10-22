import { expect, test } from '@playwright/test';
import { login } from './support';

// Solo tiene sentido contra la imagen de producción (PANEL_URL): `ng serve` no pone cabeceras ni nonce.
test.skip(!process.env['PANEL_URL'], 'requiere PANEL_URL (imagen de producción)');

const SECCIONES = ['/', '/auditoria', '/avisos', '/codigos', '/campanas', '/campanas/nueva', '/lotes', '/lotes/nuevo', '/aprobaciones', '/hosts', '/parametros', '/usuarios'];

test.describe('Imagen de producción: CSP estricta (RNF-32, P9)', () => {
  test('las cabeceras de seguridad están y el nonce cambia en cada petición y coincide con el de la página', async ({ request }) => {
    const nonces: string[] = [];
    for (let i = 0; i < 2; i++) {
      const res = await request.get('/acceso');
      const h = res.headers();
      const csp = h['content-security-policy'];
      expect(csp).toContain("default-src 'none'");
      expect(csp).not.toContain('unsafe-inline');
      expect(csp).not.toContain('unsafe-eval');
      expect(csp).toContain("frame-ancestors 'none'");
      expect(h['x-content-type-options']).toBe('nosniff');
      expect(h['referrer-policy']).toBe('no-referrer');
      expect(h['strict-transport-security']).toContain('max-age=');
      expect(h['cache-control']).toContain('no-store');
      const nonce = /'nonce-([0-9a-f]{32})'/.exec(csp)?.[1];
      expect(nonce).toBeTruthy();
      expect(await res.text()).toContain(`ngcspnonce="${nonce}"`);
      nonces.push(nonce!);
    }
    expect(nonces[0]).not.toBe(nonces[1]);
  });

  test('ninguna pantalla viola la CSP ni deja errores de consola, y no hay estilos ni scripts en línea sin nonce', async ({ page }) => {
    const problemas: string[] = [];
    await page.addInitScript(() => {
      document.addEventListener('securitypolicyviolation', (e) => {
        (window as unknown as { __csp: string[] }).__csp ??= [];
        (window as unknown as { __csp: string[] }).__csp.push(`${e.violatedDirective} ${e.blockedURI} ${e.sourceFile ?? ''}:${e.lineNumber}`);
      });
    });
    page.on('console', (m) => {
      if (m.type() === 'error') problemas.push(`consola: ${m.text()}`);
    });
    await login(page, 'superadmin');
    for (const ruta of SECCIONES) {
      await page.goto(ruta);
      await expect(page.locator('h1').first()).toBeVisible();
      await page.waitForLoadState('networkidle');
      const v = await page.evaluate(() => (window as unknown as { __csp?: string[] }).__csp ?? []);
      problemas.push(...v.map((x) => `${ruta}: ${x}`));
    }
    // Superposiciones (selector, confirmación en línea): también viven bajo la misma política.
    await page.goto('/usuarios');
    await expect(page.locator('h1').first()).toBeVisible();
    await page.getByRole('combobox', { name: 'Rol', exact: true }).click();
    await expect(page.getByRole('option').first()).toBeVisible();
    await page.keyboard.press('Escape');
    await page.getByRole('button', { name: /^Desactivar/ }).first().click();
    await expect(page.getByRole('alertdialog')).toBeVisible();
    problemas.push(...(await page.evaluate(() => (window as unknown as { __csp?: string[] }).__csp ?? [])).map((x) => `interacciones: ${x}`));
    expect(problemas).toEqual([]);
    // Todo <style>/<script> en línea lleva el nonce de la página.
    const sinNonce = await page.evaluate(() =>
      [...document.querySelectorAll('style:not([nonce]), script:not([src]):not([nonce])')].length,
    );
    expect(sinNonce).toBe(0);
  });

  test('la API del panel responde por el mismo origen con sus cabeceras y sin caché', async ({ request }) => {
    const res = await request.get('/admin/api/v1/auth/me');
    expect(res.status()).toBe(401);
    expect(res.headers()['cache-control']).toContain('no-store');
    expect(res.headers()['content-security-policy']).toContain("default-src 'none'");
    // Lo que no es del panel no se expone por este puerto.
    expect((await request.get('/v1/dev/token')).status()).toBe(200); // cae en la página (SPA), nunca llega a la API
    expect(await (await request.get('/v1/dev/token')).text()).toContain('<app-root');
  });
});
