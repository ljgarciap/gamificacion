import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import { apiCall, login } from './support';

interface State { configured: boolean; config: Record<string, unknown>; poolByPlan: unknown[] }

/** Configuración «por pasos» de partida: las pruebas arrancan siempre del mismo punto y la restauran al terminar. */
const STEPS = {
  user: { displayName: 'Cliente de muestra', hostUserPrefix: 'muestra', initialBalance: 0 },
  products: [
    { id: 'recarga-20', name: 'Recarga $20.000', price: 20_000 },
    { id: 'recarga-50', name: 'Recarga $50.000', price: 50_000 },
  ],
  credit: { balancePercent: 10, minPurchase: 10_000, codeStepAmount: 20_000, maxCodesPerPurchase: 3, maxCodesPerSession: 10 },
  mix: { roulette: { prizes: [{ type: 'money', amount: 5_000, count: 2 }], noPrizeCount: 8 } },
  ttlHours: 24,
};

test.describe('Muestra por planes (SPEC-007 M13)', () => {
  test('pasar al modo por planes trae los cuatro planes provisionales, el catálogo con «Participa» y la probabilidad de cada uno', async ({ page }) => {
    await login(page, 'superadmin');
    await apiCall(page, 'POST', '/demo/disable').catch(() => undefined);
    const original = (await apiCall<State>(page, 'GET', '/demo')).config;
    await apiCall(page, 'PUT', '/demo/config', STEPS);
    try {
      await page.goto('/muestra');
      await expect(page.getByTestId('mode-steps')).toBeChecked();
      await expect(page.getByTestId('minPurchase')).toBeVisible();
      await expect(page.getByTestId('plan-card')).toHaveCount(0);

      await page.getByTestId('mode-plans').check();
      await expect(page.getByTestId('mode-note')).toBeVisible();
      await expect(page.getByTestId('minPurchase')).toHaveCount(0); // los campos del modo anterior desaparecen
      await expect(page.getByTestId('plan-card')).toHaveCount(4);
      const prob = async (id: string) => page.getByTestId('plan-card').and(page.locator(`[data-plan="${id}"]`)).getByTestId('plan-probability').innerText();
      expect(await prob('welcome')).toBe('100 %');
      expect(await prob('bronce')).toBe('5 %');
      expect(await prob('plata')).toBe('15 %');
      expect(await prob('oro')).toBe('40 %');
      await expect(page.getByTestId('pool-total')).toContainText('Pool total: 400');

      // El catálogo trae los productos que NO participan (Pasa Plata, Saca Plata, Recarga de Saldo).
      await expect(page.getByTestId('product-row')).toHaveCount(5);
      await expect(page.getByTestId('participates')).toHaveCount(5);
      await expect(page.getByTestId('participates').nth(0)).toBeChecked();
      await expect(page.getByTestId('participates').nth(2)).not.toBeChecked();
      const rows = page.getByTestId('preview-row');
      await expect(rows.nth(0)).toContainText('Plata'); // $20.000 cae en Plata
      await expect(rows.nth(1)).toContainText('Plata'); // $50.000 exactos siguen en Plata (Oro empieza en $50.001)
      await expect(rows.nth(2)).toContainText('No participa');
    } finally {
      await apiCall(page, 'PUT', '/demo/config', original).catch(() => undefined);
    }
  });

  test('guardar persiste el modo por planes y recargar no lo ve como cambios sin guardar', async ({ page }) => {
    await login(page, 'superadmin');
    await apiCall(page, 'POST', '/demo/disable').catch(() => undefined);
    const original = (await apiCall<State>(page, 'GET', '/demo')).config;
    await apiCall(page, 'PUT', '/demo/config', STEPS);
    try {
      await page.goto('/muestra');
      await page.getByTestId('mode-plans').check();
      await page.getByTestId('reactivationDays').fill('5');
      await page.getByTestId('save').click();
      await expect(page.getByTestId('message')).toContainText('Configuración guardada');
      await expect(page.getByTestId('save')).toBeDisabled();

      await page.reload();
      await expect(page.getByTestId('mode-plans')).toBeChecked();
      await expect(page.getByTestId('reactivationDays')).toHaveValue('5');
      await expect(page.getByTestId('plan-card')).toHaveCount(4);
      await expect(page.getByTestId('save')).toBeDisabled();
      await expect(page.getByTestId('dirty-note')).toHaveCount(0);
      const saved = (await apiCall<State>(page, 'GET', '/demo')).config as { mode: string; plans: unknown[]; triggers: { reactivationDays: number } };
      expect(saved.mode).toBe('plans');
      expect(saved.plans).toHaveLength(4);
      expect(saved.triggers.reactivationDays).toBe(5);

      // Volver al modo anterior desde lo guardado: el pool no se toca y se avisa.
      await page.getByTestId('mode-steps').check();
      await expect(page.getByTestId('minPurchase')).toBeVisible();
      await expect(page.getByTestId('plan-card')).toHaveCount(0);
      await expect(page.getByTestId('problems')).toHaveCount(0);
      await page.getByTestId('save').click();
      await expect(page.getByTestId('message')).toContainText('Configuración guardada');
      expect(((await apiCall<State>(page, 'GET', '/demo')).config as { mode?: string }).mode).toBeUndefined();
    } finally {
      await apiCall(page, 'PUT', '/demo/config', original).catch(() => undefined);
    }
  });

  test('editar los rangos encadena el mínimo del siguiente plan y las reglas incumplidas bloquean el guardado', async ({ page }) => {
    await login(page, 'superadmin');
    await apiCall(page, 'POST', '/demo/disable').catch(() => undefined);
    const original = (await apiCall<State>(page, 'GET', '/demo')).config;
    await apiCall(page, 'PUT', '/demo/config', STEPS);
    try {
      await page.goto('/muestra');
      await page.getByTestId('mode-plans').check();

      await page.getByTestId('plan-max-bronce').fill('20000');
      await expect(page.getByTestId('plan-min-plata')).toHaveValue('20001');
      await expect(page.getByTestId('plan-min-plata')).toHaveJSProperty('readOnly', true);
      await expect(page.getByTestId('plan-min-bronce')).toHaveJSProperty('readOnly', false);
      await expect(page.getByTestId('plan-max-oro')).toBeDisabled(); // el último no tiene tope
      await expect(page.getByTestId('plan-max-oro')).toHaveValue('Sin tope');
      await expect(page.getByTestId('problems')).toHaveCount(0);

      // Un premio en dinero por encima del máximo del plan lo dice el panel y bloquea guardar.
      await page.getByTestId('plan-cap-bronce').fill('5000');
      await expect(page.getByTestId('problems')).toContainText('supera el máximo del plan');
      await expect(page.getByTestId('save')).toBeDisabled();
      await page.getByTestId('plan-cap-bronce').fill('10000');
      await expect(page.getByTestId('problems')).toHaveCount(0);

      // La probabilidad se recalcula al cambiar la mezcla del plan (Bronce: 5 con premio + 25 sin premio = 5 / 30 ≈ 16,7 %).
      const bronce = page.getByTestId('plan-card').and(page.locator('[data-plan="bronce"]'));
      await bronce.getByTestId('none-bronce-roulette').fill('25');
      await expect(bronce.getByTestId('plan-probability')).toHaveText('16,7 %');

      // El pool total se calcula entre todos los planes: pasarse del techo bloquea guardar.
      await bronce.getByTestId('none-bronce-roulette').fill('500');
      await expect(page.getByTestId('problems')).toContainText('el pool total no puede superar 500');
      await expect(page.getByTestId('save')).toBeDisabled();
    } finally {
      await apiCall(page, 'PUT', '/demo/config', original).catch(() => undefined);
    }
  });

  test('el panel avisa de un monto mínimo inválido en el primer plan y no deja guardar', async ({ page }) => {
    await login(page, 'superadmin');
    await apiCall(page, 'POST', '/demo/disable').catch(() => undefined);
    const original = (await apiCall<State>(page, 'GET', '/demo')).config;
    await apiCall(page, 'PUT', '/demo/config', STEPS);
    try {
      await page.goto('/muestra');
      await page.getByTestId('mode-plans').check();
      // El mínimo del primer plan sí es editable; desde 0 no es válido.
      await page.getByTestId('plan-min-bronce').fill('0');
      await expect(page.getByTestId('problems')).toContainText('monto mínimo');
      await expect(page.getByTestId('save')).toBeDisabled();
    } finally {
      await apiCall(page, 'PUT', '/demo/config', original).catch(() => undefined);
    }
  });

  test('cumple accesibilidad en el modo por planes', async ({ page }) => {
    await login(page, 'superadmin');
    await apiCall(page, 'POST', '/demo/disable').catch(() => undefined);
    const original = (await apiCall<State>(page, 'GET', '/demo')).config;
    await apiCall(page, 'PUT', '/demo/config', STEPS);
    try {
      await page.goto('/muestra');
      await page.getByTestId('mode-plans').check();
      await expect(page.getByTestId('plan-card')).toHaveCount(4);
      const res = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa']).analyze();
      expect(res.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical'), JSON.stringify(res.violations.map((v) => [v.id, v.nodes[0]?.html?.slice(0, 160)]))).toEqual([]);
    } finally {
      await apiCall(page, 'PUT', '/demo/config', original).catch(() => undefined);
    }
  });

  test('la simulación de inactividad solo aparece con la muestra guardada por planes, pide confirmación y llama a la API (servidor simulado)', async ({ page }) => {
    await login(page, 'superadmin');
    const real = await apiCall<State & Record<string, unknown>>(page, 'GET', '/demo');
    const calls: string[] = [];
    let mode: 'plans' | 'steps' = 'plans';
    const account = { id: '11111111-2222-4333-8444-555555555555', email: 'a***@example.com', balance: 1000, createdAt: new Date().toISOString(), lastLoginAt: new Date().toISOString() };
    await page.route('**/admin/api/v1/demo**', async (route) => {
      const req = route.request();
      const path = new URL(req.url()).pathname.replace('/admin/api/v1', '');
      const json = (body: unknown, status = 200) => route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(body) });
      if (req.method() !== 'GET') {
        calls.push(`${req.method()} ${path}`);
        return json({}, 204);
      }
      if (path === '/demo/accounts') return json({ items: [account], total: 1, page: 1, perPage: 20 });
      if (path === '/demo') {
        const cfg = mode === 'plans' ? { ...(real['config'] as object), mode: 'plans', triggers: { reactivationDays: 7, turnsPerPurchase: 1, welcomeOnFirstLogin: true }, plans: [] } : real['config'];
        return json({ ...real, configured: true, config: cfg, poolByPlan: mode === 'plans' ? [{ id: 'welcome', name: 'Bienvenida', available: 5, assigned: 1, probability: 1 }] : [] });
      }
      return route.continue();
    });
    try {
      await page.goto('/muestra');
      await expect(page.getByTestId('plan-stock-row')).toHaveCount(1);
      await expect(page.getByTestId('plan-stock-row')).toContainText('Bienvenida');
      await expect(page.getByTestId('plan-stock-row')).toContainText('100 %');
      await expect(page.getByTestId('account-simulate')).toBeVisible();

      await page.getByTestId('account-simulate').click();
      await expect(page.getByTestId('simulate-confirm')).toContainText('7 días sin comprar');
      await page.getByTestId('simulate-confirm-no').click();
      await expect(page.getByTestId('simulate-confirm')).toHaveCount(0);
      expect(calls).toEqual([]);

      await page.getByTestId('account-simulate').click();
      await page.getByTestId('simulate-confirm-yes').click();
      await expect(page.getByTestId('message')).toContainText('turno de reactivación');
      expect(calls).toEqual([`POST /demo/accounts/${account.id}/simulate-inactivity`]);

      // En el modo anterior no hay botón de simulación ni existencias por plan.
      mode = 'steps';
      await page.reload();
      await expect(page.getByTestId('account-row')).toHaveCount(1);
      await expect(page.getByTestId('account-simulate')).toHaveCount(0);
      await expect(page.getByTestId('plan-stock-row')).toHaveCount(0);
    } finally {
      await page.unroute('**/admin/api/v1/demo**');
    }
  });
});
