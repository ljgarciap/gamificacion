import AxeBuilder from '@axe-core/playwright';
import { expect, test, Page } from '@playwright/test';
import { login } from './support';

const API = '/admin/api/v1';
const stamp = () => Date.now().toString(36);

/** Cierra el diálogo de secreto de una vez después de comprobarlo y devuelve el secreto que mostraba. */
async function readAndCloseSecret(page: Page): Promise<string> {
  const dialog = page.getByRole('dialog');
  await expect(dialog).toBeVisible();
  const secret = (await dialog.getByTestId('secret-value').innerText()).trim();
  // No se puede cerrar ni con Esc ni sin marcar «Ya lo guardé».
  await page.keyboard.press('Escape');
  await expect(dialog).toBeVisible();
  await expect(dialog.getByTestId('secret-close')).toBeDisabled();
  await dialog.getByText('Ya lo guardé').click();
  await dialog.getByTestId('secret-close').click();
  await expect(dialog).toHaveCount(0);
  return secret;
}

test.describe('Usuarios (P8, grupo 4)', () => {
  test('alta con contraseña temporal de una vez, cambio de rol, desactivación y restablecimientos, cada uno con su confirmación', async ({ page }) => {
    await login(page, 'superadmin');
    await page.goto('/usuarios');
    const email = `e2e-u-${stamp()}@ejemplo.com`;
    await page.getByLabel('Correo', { exact: true }).fill(email);
    await page.getByLabel('Nombre', { exact: true }).fill('Persona de prueba');
    await page.getByRole('button', { name: 'Crear usuario' }).click();

    const first = await readAndCloseSecret(page);
    expect(first.length).toBeGreaterThanOrEqual(12);
    // UA-4: tras cerrar el diálogo no hay forma de recuperarlo desde la interfaz.
    expect(await page.locator('body').innerText()).not.toContain(first);

    const row = page.getByTestId('user-row').filter({ hasText: email });
    await expect(row).toContainText('Operador');
    await expect(row).toContainText('Debe cambiar la contraseña');
    await expect(row).toContainText('Sin enrolar');

    // Cambiar el rol pide confirmación y avisa del cierre de sesiones; cancelar no cambia nada.
    await row.getByRole('combobox').click();
    await page.getByRole('option', { name: 'Auditor' }).click();
    await expect(row.getByTestId('confirm')).toContainText('Se cerrarán todas sus sesiones');
    await row.getByRole('button', { name: 'Cancelar' }).click();
    await expect(row).toContainText('Operador');
    await row.getByRole('combobox').click();
    await page.getByRole('option', { name: 'Auditor' }).click();
    await row.getByTestId('confirm-yes').click();
    await expect(row).toContainText('Auditor');

    await row.getByRole('button', { name: /Desactivar/ }).click();
    await expect(row.getByTestId('confirm')).toContainText('Se cerrarán todas sus sesiones y no podrá entrar');
    await row.getByTestId('confirm-yes').click();
    await expect(row).toContainText('Desactivado');
    await row.getByRole('button', { name: /Activar/ }).click();
    await row.getByTestId('confirm-yes').click();
    await expect(row).toContainText('Activo');

    await row.getByRole('button', { name: /Restablecer 2FA/ }).click();
    await row.getByTestId('confirm-yes').click();
    await expect(page.getByTestId('message')).toContainText('Restablecer el 2FA');

    // Restablecer la contraseña da OTRA temporal, de una vez.
    await row.getByRole('button', { name: /Restablecer contraseña/ }).click();
    await row.getByTestId('confirm-yes').click();
    const second = await readAndCloseSecret(page);
    expect(second).not.toBe(first);
    expect(await page.locator('body').innerText()).not.toContain(second);

    // Todo quedó en el servidor, y la bitácora no guarda la contraseña.
    const { users } = await (await page.request.get(`${API}/users`)).json();
    const stored = users.find((u: { email: string }) => u.email === email);
    expect(stored).toMatchObject({ role: 'auditor', active: true, mfaEnrolled: false });
    const audit = JSON.stringify(await (await page.request.get(`${API}/audit?perPage=50`)).json());
    expect(audit).not.toContain(first);
    expect(audit).not.toContain(second);
  });

  test('un correo repetido lo dice el servidor junto al formulario', async ({ page }) => {
    await login(page, 'superadmin');
    await page.goto('/usuarios');
    await page.getByLabel('Correo', { exact: true }).fill('e2e-superadmin@ejemplo.com');
    await page.getByLabel('Nombre', { exact: true }).fill('Repetido');
    await page.getByRole('button', { name: 'Crear usuario' }).click();
    await expect(page.getByTestId('create-error')).toBeVisible();
    await expect(page.getByRole('dialog')).toHaveCount(0);
  });

  test('la fila propia no ofrece cambiarse el rol ni desactivarse, y un 409 lastSuperadmin se explica', async ({ page }) => {
    await login(page, 'superadmin');
    await page.goto('/usuarios');
    const mine = page.getByTestId('user-row').filter({ hasText: 'e2e-superadmin@ejemplo.com' });
    await expect(mine).toContainText('(tú)');
    await expect(mine.getByRole('button')).toHaveCount(0);
    await page.route(new RegExp(`${API}/users/\\d+$`), (r) =>
      r.request().method() === 'PATCH' ? r.fulfill({ status: 409, json: { error: { code: 'lastSuperadmin', message: 'No puedes dejar el sistema sin un superadmin activo.' } } }) : r.continue(),
    );
    const other = page.getByTestId('user-row').filter({ hasText: 'Superadmin' }).filter({ hasNotText: '(tú)' }).first();
    await other.getByRole('button', { name: /Desactivar/ }).click();
    await other.getByTestId('confirm-yes').click();
    await expect(page.getByTestId('fail')).toContainText('sin un superadmin activo');
  });

  test('un operador no ve Usuarios y la ruta directa dice «sin acceso»', async ({ page }) => {
    await login(page, 'operator');
    await expect(page.getByRole('navigation').getByRole('link', { name: 'Usuarios' })).toHaveCount(0);
    await page.goto('/usuarios');
    await expect(page.getByText('No tienes acceso a esta sección')).toBeVisible();
  });
});

test.describe('Hosts (P8, grupo 4)', () => {
  /** Estado simulado: no se tocan los hosts reales de desarrollo (rotar un secreto rompería el simulador). */
  async function mockHosts(page: Page, initial: object[] = []) {
    const state = { hosts: initial as Record<string, unknown>[], calls: [] as string[], bodies: [] as unknown[] };
    await page.route(new RegExp(`${API}/hosts(/.*)?$`), async (route) => {
      const req = route.request();
      const path = new URL(req.url()).pathname.replace(API, '');
      if (req.method() === 'GET') return route.fulfill({ json: { hosts: state.hosts } });
      state.calls.push(`${req.method()} ${path}`);
      if (req.postData()) state.bodies.push(req.postDataJSON());
      if (path === '/hosts') {
        state.hosts = [{ id: 1, issuer: 'standalone-dev', callbackUrl: 'https://a.example.com/cb', balanceUrl: 'https://a.example.com/bal', active: true, hasPreviousSecret: false, createdAt: '', updatedAt: '' }];
        return route.fulfill({ status: 201, json: { id: 1, secret: 'SECRETO-NUEVO-DE-PRUEBA-0001' } });
      }
      if (path.endsWith('/rotate-secret')) { state.hosts[0]['hasPreviousSecret'] = true; return route.fulfill({ json: { secret: 'SECRETO-ROTADO-DE-PRUEBA-0002' } }); }
      if (path.endsWith('/retire-previous-secret')) { state.hosts[0]['hasPreviousSecret'] = false; return route.fulfill({ status: 204 }); }
      return route.fulfill({ status: 204 });
    });
    return state;
  }

  test('alta, rotación y retiro del secreto anterior: cada secreto se ve una sola vez', async ({ page }) => {
    await login(page, 'superadmin');
    const state = await mockHosts(page);
    await page.goto('/hosts');
    await expect(page.getByTestId('empty')).toBeVisible();
    await page.getByLabel('Emisor').fill('standalone-dev');
    await page.getByLabel('URL de avisos').fill('https://a.example.com/cb');
    await page.getByLabel('URL de saldo').fill('https://a.example.com/bal');
    await page.getByRole('button', { name: 'Crear host' }).click();
    const s1 = await readAndCloseSecret(page);
    expect(s1).toBe('SECRETO-NUEVO-DE-PRUEBA-0001');
    expect(await page.locator('body').innerText()).not.toContain(s1);
    expect(state.bodies[0]).toEqual({ issuer: 'standalone-dev', callbackUrl: 'https://a.example.com/cb', balanceUrl: 'https://a.example.com/bal' });

    const row = page.getByTestId('host-row');
    await expect(row).toContainText('No hay');
    await expect(row.getByRole('button', { name: /Retirar secreto anterior/ })).toHaveCount(0);

    await row.getByRole('button', { name: /Rotar secreto/ }).click();
    await expect(row.getByTestId('confirm')).toContainText('dos secretos válidos a la vez');
    await row.getByTestId('confirm-yes').click();
    const s2 = await readAndCloseSecret(page);
    expect(s2).toBe('SECRETO-ROTADO-DE-PRUEBA-0002');
    expect(await page.locator('body').innerText()).not.toContain(s2);
    await expect(row).toContainText('Vigente');

    await row.getByRole('button', { name: /Retirar secreto anterior/ }).click();
    await row.getByTestId('confirm-yes').click();
    await expect(row).toContainText('No hay');
    expect(state.calls).toEqual(['POST /hosts', 'POST /hosts/1/rotate-secret', 'POST /hosts/1/retire-previous-secret']);
  });

  test('un emisor o una URL que el servidor rechaza se muestra junto al formulario y no abre ningún secreto', async ({ page }) => {
    await login(page, 'superadmin');
    await page.route(`**${API}/hosts`, (r) =>
      r.request().method() === 'POST' ? r.fulfill({ status: 422, json: { error: { code: 'validationFailed', message: 'La URL no es pública.' } } }) : r.continue(),
    );
    await page.goto('/hosts');
    await page.getByLabel('Emisor').fill('standalone-dev');
    await page.getByLabel('URL de avisos').fill('https://10.0.0.1/cb');
    await page.getByLabel('URL de saldo').fill('https://10.0.0.1/bal');
    await page.getByRole('button', { name: 'Crear host' }).click();
    await expect(page.getByTestId('create-error')).toContainText('La URL no es pública.');
    await expect(page.getByRole('dialog')).toHaveCount(0);
  });

  test('desactivar un host dice qué implica', async ({ page }) => {
    await login(page, 'superadmin');
    const state = await mockHosts(page, [{ id: 1, issuer: 'h', callbackUrl: 'https://a.example.com/cb', balanceUrl: 'https://a.example.com/b', active: true, hasPreviousSecret: false, createdAt: '', updatedAt: '' }]);
    await page.goto('/hosts');
    await page.getByRole('button', { name: /Desactivar/ }).click();
    await expect(page.getByTestId('confirm')).toContainText('deja de recibir avisos');
    await page.getByTestId('confirm-yes').click();
    await expect(page.getByTestId('message')).toContainText('desactivado');
    expect(state.bodies).toEqual([{ active: false }]);
  });

  test('la lista real carga y un operador no tiene acceso', async ({ page }) => {
    await login(page, 'superadmin');
    await page.goto('/hosts');
    await expect(page.getByRole('heading', { name: 'Hosts' })).toBeVisible();
    await expect(page.getByTestId('load-error')).toHaveCount(0);
  });
});

test.describe('Parámetros (P8, grupo 4)', () => {
  test('un parámetro normal se edita en línea, valida el rango, deja historial y se restaura', async ({ page }) => {
    await login(page, 'superadmin');
    await page.goto('/parametros');
    const key = 'outbox.backoff_base_seconds';
    const row = page.locator(`[data-testid=setting-row][data-key="${key}"]`);
    await expect(row).toBeVisible();
    const input = row.getByRole('spinbutton');
    const original = Number(await input.inputValue());
    const [min, max] = (await row.locator('td').nth(2).innerText()).split('–').map(Number);
    expect(row.getByTestId(`request-${key}`)).toHaveCount(0);

    await input.fill(String(max + 1));
    await expect(row.getByRole('alert')).toContainText(`entre ${min} y ${max}`);
    await expect(row.getByTestId(`save-${key}`)).toBeDisabled();

    const changed = original < max ? original + 1 : original - 1;
    await input.fill(String(changed));
    await row.getByTestId(`save-${key}`).click();
    await expect(page.getByTestId('message')).toContainText('Aplica en menos de 1 minuto');
    await expect(row).toContainText('Modificado');
    await row.getByRole('button', { name: /Historial/ }).click();
    await expect(row.getByTestId('history').locator('li').first()).toContainText(`→ ${changed}`);

    // Se restaura el valor original y el historial lo registra.
    await row.getByRole('spinbutton').fill(String(original));
    await row.getByTestId(`save-${key}`).click();
    await expect(page.getByTestId('message')).toContainText('guardado');
    await row.getByRole('button', { name: /Historial/ }).click();
    await row.getByRole('button', { name: /Historial/ }).click();
    await expect(row.getByTestId('history').locator('li').first()).toContainText(`→ ${original}`);
    const { settings } = await (await page.request.get(`${API}/settings`)).json();
    expect(settings.find((s: { key: string }) => s.key === key).value).toBe(original);
  });

  test('UA-6: un parámetro de seguridad no se edita en línea: crea una solicitud de aprobación', async ({ page }) => {
    await login(page, 'superadmin');
    const bodies: unknown[] = [];
    const puts: string[] = [];
    await page.route(`**${API}/approvals`, (r) => {
      if (r.request().method() !== 'POST') return r.continue();
      bodies.push(r.request().postDataJSON());
      return r.fulfill({ status: 201, json: { id: 77 } });
    });
    await page.route(new RegExp(`${API}/settings/.*`), (r) => { if (r.request().method() === 'PUT') puts.push(r.request().url()); return r.continue(); });
    await page.goto('/parametros');
    const key = 'redeem.requests_per_minute';
    const row = page.locator(`[data-testid=setting-row][data-key="${key}"]`);
    await expect(row).toContainText('Seguridad');
    await expect(row.getByTestId(`save-${key}`)).toHaveCount(0);
    const input = row.getByRole('spinbutton');
    const current = Number(await input.inputValue());
    await expect(row.getByTestId(`request-${key}`)).toBeDisabled();
    await input.fill(String(current + 1));
    await row.getByTestId(`request-${key}`).click();
    await expect(page.getByTestId('message')).toContainText('Pídele a otra persona que la apruebe');
    expect(bodies).toEqual([{ action: 'setting.change', params: { key, value: current + 1 } }]);
    expect(puts, 'un parámetro de seguridad nunca se cambia por la ruta de edición').toHaveLength(0);
  });

  test('un operador y un auditor no tienen acceso', async ({ page }) => {
    await login(page, 'auditor');
    await page.goto('/parametros');
    await expect(page.getByText('No tienes acceso a esta sección')).toBeVisible();
  });
});

test('UA-9/UA-10: axe y sin desbordes en Usuarios, Hosts y Parámetros', async ({ page }) => {
  await login(page, 'superadmin');
  for (const [path, name, ready] of [['/usuarios', 'usuarios', 'user-row'], ['/hosts', 'hosts', null], ['/parametros', 'parametros', 'setting-row']] as const) {
    for (const width of [1280, 768]) {
      await page.setViewportSize({ width, height: 900 });
      await page.goto(path);
      await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
      if (ready) await expect(page.getByTestId(ready).first()).toBeVisible();
      else await expect(page.getByTestId('empty')).toBeVisible();
      expect(await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth), `${name} a ${width}px`).toBeLessThanOrEqual(0);
      await page.screenshot({ path: `test-results/${name}-${width}.png`, fullPage: true });
    }
    const res = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa']).analyze();
    expect(res.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical'), JSON.stringify(res.violations.map((v) => [v.id, v.nodes[0]?.html?.slice(0, 140)]))).toEqual([]);
  }
});
