import AxeBuilder from '@axe-core/playwright';
import { expect, test, Page } from '@playwright/test';
import { apiCall, login } from './support';

const API = '/admin/api/v1';
const stamp = () => Date.now().toString(36);

/** Campaña de prueba con un premio de giro extra de 2 giros (reserva mínima = cantidad × 2). */
async function campaignWithExtraSpin(page: Page): Promise<{ id: string; name: string }> {
  const name = `E2E lotes ${stamp()}`;
  const { id } = await apiCall<{ id: string }>(page, 'POST', '/campaigns', {
    name, gameType: 'roulette', gameConfig: { segments: ['p50k', 'none', 'p50k', 'none'] },
    prizes: [
      { prizeId: 'none', type: 'none', label: 'Sin premio' },
      { prizeId: 'p50k', type: 'money', label: '$ 50.000', amount: 50000 },
      { prizeId: 'extra', type: 'extraSpin', label: 'Gira otra vez', meta: { extra_spins: 2 } },
    ],
  });
  return { id, name };
}

async function addPoolRow(page: Page, index: number, prize: RegExp, quantity: number): Promise<void> {
  if (index > 0) await page.getByRole('button', { name: 'Agregar premio al pool' }).click();
  await page.getByRole('combobox', { name: `Premio ${index + 1}` }).click();
  await page.getByRole('option', { name: prize }).click();
  await page.getByLabel(`Cantidad ${index + 1}`).fill(String(quantity));
}

test.describe('Lotes: asistente (P8, grupo 5a)', () => {
  test('el asistente calcula la reserva mínima, avisa de lo que sobra y crea el borrador', async ({ page }) => {
    await login(page, 'operator');
    const c = await campaignWithExtraSpin(page);
    await page.goto('/lotes/nuevo');
    await expect(page.getByTestId('step-label')).toContainText('Paso 1 de 3');
    await page.getByRole('combobox', { name: 'Campaña' }).click();
    await page.getByRole('option', { name: c.name }).click();
    const name = `Lote ${stamp()}`;
    await page.getByLabel('Nombre del lote').fill(name);
    await page.getByRole('button', { name: 'Siguiente' }).click();

    await expect(page.getByTestId('step-label')).toContainText('Paso 2 de 3');
    await addPoolRow(page, 0, /Sin premio/, 10);
    await expect(page.getByTestId('reserve')).toHaveCount(0);
    await addPoolRow(page, 1, /\$ 50\.000/, 2);
    await addPoolRow(page, 2, /Gira otra vez/, 3);

    // 3 premios de giro extra × 2 giros = mínimo 6; con R = 0 el avance está bloqueado con el motivo.
    await expect(page.getByTestId('reserve-error')).toContainText('mínimo 6');
    await expect(page.getByRole('button', { name: 'Siguiente' })).toBeDisabled();
    await page.getByTestId('reserve').fill('5');
    await expect(page.getByTestId('reserve-error')).toContainText('mínimo 6');
    await page.getByTestId('reserve').fill('8');
    await expect(page.getByTestId('reserve-error')).toHaveCount(0);
    await expect(page.getByTestId('reserve-extra')).toContainText('2 casillas de reserva quizá nunca se entreguen');
    // Pool de 15 posiciones: 8 son de reserva y 7 son códigos.
    await expect(page.getByTestId('n-total')).toHaveText('15');
    await expect(page.getByTestId('n-reserve')).toHaveText('8');
    await expect(page.getByTestId('n-codes')).toHaveText('7');

    await page.getByRole('button', { name: 'Siguiente' }).click();
    await expect(page.getByTestId('step-label')).toContainText('Paso 3 de 3');
    await expect(page.getByText('Después de comprometer, el pool, el mazo y el número de códigos no se podrán cambiar.')).toBeVisible();
    await page.getByTestId('create-batch').click();

    await expect(page).toHaveURL(/\/lotes\/[0-9a-f-]{36}$/);
    await expect(page.getByRole('heading', { level: 1, name })).toBeVisible();
    await expect(page.getByTestId('lifecycle').locator('[aria-current=step]')).toHaveText(/Borrador/);
    await expect(page.getByTestId('primary-action')).toHaveText('Generar códigos');
    const id = page.url().split('/').pop();
    const stored = await apiCall<{ state: string; totalCodes: number; reserveSize: number }>(page, 'GET', `/batches/${id}`);
    expect(stored).toMatchObject({ state: 'draft', totalCodes: 7, reserveSize: 8 });
  });

  test('un premio repetido o sin elegir bloquea el avance, y el servidor es la última palabra', async ({ page }) => {
    await login(page, 'operator');
    const c = await campaignWithExtraSpin(page);
    await page.goto('/lotes/nuevo');
    await page.getByRole('combobox', { name: 'Campaña' }).click();
    await page.getByRole('option', { name: c.name }).click();
    await page.getByLabel('Nombre del lote').fill('x');
    await page.getByRole('button', { name: 'Siguiente' }).click();
    await expect(page.getByTestId('pool-error')).toContainText('Elige un premio');
    await addPoolRow(page, 0, /Sin premio/, 5);
    await addPoolRow(page, 1, /Sin premio/, 5);
    await expect(page.getByTestId('pool-error')).toContainText('no puede repetirse');
    await expect(page.getByRole('button', { name: 'Siguiente' })).toBeDisabled();
  });

  test('el auditor no puede abrir el asistente', async ({ page }) => {
    await login(page, 'auditor');
    await page.goto('/lotes');
    await expect(page.getByTestId('new-batch')).toHaveCount(0);
    await page.goto('/lotes/nuevo');
    await expect(page.getByText('No tienes acceso a esta sección')).toBeVisible();
  });
});

const base = {
  id: '11111111-1111-4111-8111-111111111111', campaignId: '22222222-2222-4222-8222-222222222222', name: 'Lote simulado', totalCodes: 20, reserveSize: 0, generatedCodes: 20, redeemed: 4,
  redeemableUntil: null, createdAt: '2026-10-20T15:00:00Z', committedAt: null, activatedAt: null, closedAt: null, revealedAt: null, pool: [{ prize_id: 'none', quantity: 20 }],
  poolHash: 'aa', redeemedByPrize: [{ prizeId: 'none', count: 4 }], createdBy: 'admin:1', commitment: null, codesDigest: null, chainHash: null, beaconRound: null, beaconValue: null, seed: null,
};

/** Responde el detalle del lote con el estado indicado (el estado real de la base de desarrollo no se toca). */
async function mockBatch(page: Page, patch: object, calls: { method: string; path: string; body: unknown }[] = []) {
  const batch = { ...base, ...patch };
  await page.route(new RegExp(`${API}/(batches/${base.id}|approvals|campaigns/${base.campaignId})(/.*)?$`), (route) => {
    const req = route.request();
    const path = new URL(req.url()).pathname.replace(API, '');
    if (req.method() === 'GET' && path === `/batches/${base.id}`) return route.fulfill({ json: batch });
    if (req.method() === 'GET' && path.startsWith('/campaigns/')) return route.fulfill({ json: { id: base.campaignId, name: 'Campaña simulada', prizes: [{ prizeId: 'none', type: 'none', label: 'Sin premio', amount: null, meta: {} }] } });
    if (req.method() === 'GET' && path.endsWith('/redemptions')) return route.fulfill({ json: { items: [], total: 0, page: 1, perPage: 50 } });
    calls.push({ method: req.method(), path, body: req.postData() ? req.postDataJSON() : null });
    return route.fulfill(path === '/approvals' ? { status: 201, json: { id: 5 } } : path.endsWith('/commit') ? { json: { commitment: 'ab'.repeat(32), beacon_round: 777 } } : { status: 204 });
  });
}

test.describe('Lotes: ciclo de vida (P8, grupo 5a)', () => {
  const STATES: [string, object, string, string][] = [
    ['borrador sin códigos', { state: 'draft', generatedCodes: 0 }, 'Generar códigos', 'Borrador'],
    ['borrador con códigos', { state: 'draft' }, 'Comprometer', 'Con códigos'],
    ['comprometido', { state: 'committed', commitment: 'cc'.repeat(32), codesDigest: 'dd', chainHash: 'ee', beaconRound: 5, beaconValue: 'ff' }, 'Solicitar activación', 'Comprometido'],
    ['activo', { state: 'active', activatedAt: '2026-10-21T10:00:00Z', commitment: 'cc'.repeat(32) }, 'Cerrar el lote', 'Activo'],
    ['cerrado', { state: 'closed', closedAt: '2026-10-21T11:00:00Z', commitment: 'cc'.repeat(32) }, 'Revelar el lote', 'Cerrado'],
    ['revelado', { state: 'revealed', seed: '99'.repeat(32), commitment: 'cc'.repeat(32) }, 'Exportar auditoría', 'Revelado'],
  ];

  for (const [name, patch, action, step] of STATES) {
    test(`${name}: la línea de pasos marca «${step}» y la acción disponible es «${action}»`, async ({ page }) => {
      await login(page, 'operator');
      await mockBatch(page, patch);
      await page.goto(`/lotes/${base.id}`);
      await expect(page.getByTestId('lifecycle').locator('[aria-current=step]')).toHaveText(new RegExp(step));
      await expect(page.getByTestId('primary-action')).toHaveText(action);
    });
  }

  test('el auditor ve cualquier estado sin ninguna acción', async ({ page }) => {
    await login(page, 'auditor');
    for (const [, patch] of STATES) {
      await page.unrouteAll();
      await mockBatch(page, patch);
      await page.goto(`/lotes/${base.id}`);
      await expect(page.getByTestId('lifecycle')).toBeVisible();
      await expect(page.getByTestId('primary-action')).toHaveCount(0);
    }
  });

  test('comprometer: dice lo irreversible, muestra el recibo y copia; si la baliza no responde no se pierde nada', async ({ page }) => {
    await login(page, 'operator');
    const calls: { method: string; path: string; body: unknown }[] = [];
    await mockBatch(page, { state: 'draft' }, calls);
    await page.goto(`/lotes/${base.id}`);
    await page.getByTestId('primary-action').click();
    await expect(page.getByTestId('confirm')).toContainText('Ya no se podrán cambiar el pool, la reserva');
    await page.getByTestId('confirm-yes').click();
    await expect(page.getByTestId('receipt')).toContainText('ab'.repeat(32));
    await expect(page.getByTestId('receipt')).toContainText('777');
    expect(calls.map((c) => `${c.method} ${c.path}`)).toEqual([`POST /batches/${base.id}/commit`]);

    // Baliza no disponible.
    await page.unrouteAll();
    await page.route(new RegExp(`${API}/batches/${base.id}/commit`), (r) => r.fulfill({ status: 503, json: { error: { code: 'beaconUnavailable', message: 'x' } } }));
    await mockBatchGetsOnly(page, { state: 'draft' });
    await page.goto(`/lotes/${base.id}`);
    await page.getByTestId('primary-action').click();
    await page.getByTestId('confirm-yes').click();
    await expect(page.getByTestId('fail')).toContainText('No se perdió nada');
    await expect(page.getByTestId('primary-action')).toBeVisible();
  });

  test('cerrar y revelar piden escribir el nombre del lote; cerrar avisa de los giros pendientes', async ({ page }) => {
    await login(page, 'operator');
    const calls: { method: string; path: string; body: unknown }[] = [];
    await mockBatch(page, { state: 'active', reserveSize: 4, reserveConsumed: 1, pendingSpins: 3, spinsPlayed: 1, commitVersion: 2, commitment: 'cc'.repeat(32) }, calls);
    await page.goto(`/lotes/${base.id}`);
    await page.getByTestId('primary-action').click();
    await expect(page.getByTestId('confirm')).toContainText('3 pendientes');
    const yes = page.getByTestId('confirm-yes');
    await expect(yes).toBeDisabled();
    await page.getByTestId('typed-name').fill('Lote simulad');
    await expect(yes).toBeDisabled();
    await page.getByTestId('typed-name').fill('Lote simulado');
    await expect(yes).toBeEnabled();
    await yes.click();
    await expect(page.getByTestId('message')).toContainText('giros pendientes caducaron');
    expect(calls.map((c) => c.path)).toEqual([`/batches/${base.id}/close`]);

    await page.getByRole('tab', { name: 'Reserva' }).click();
    await expect(page.getByTestId('pending-spins')).toHaveText('3');
    await expect(page.getByText('Los giros pendientes caducan al cerrar el lote.')).toBeVisible();
  });

  test('activar, generar códigos y exportar solo SOLICITAN: crean la solicitud de control dual y llevan a Aprobaciones', async ({ page }) => {
    await login(page, 'operator');
    for (const [patch, label, expected] of [
      [{ state: 'committed', commitment: 'cc'.repeat(32) }, 'Solicitar activación', { action: 'batch.activate', params: { batchId: base.id } }],
      [{ state: 'draft', generatedCodes: 0 }, 'Generar códigos', { action: 'codes.generate', params: { batchId: base.id, prefix: 'PROMO' } }],
      [{ state: 'revealed', seed: '99'.repeat(32), commitment: 'cc'.repeat(32) }, 'Exportar auditoría', { action: 'audit.export', params: { batchId: base.id } }],
    ] as const) {
      await page.unrouteAll();
      const calls: { method: string; path: string; body: unknown }[] = [];
      await mockBatch(page, patch, calls);
      await page.goto(`/lotes/${base.id}`);
      await page.getByTestId('primary-action').click();
      if (label === 'Generar códigos') await page.getByLabel('Prefijo de los códigos').fill('PROMO');
      await page.getByTestId('confirm-yes').click();
      await expect(page).toHaveURL(/\/aprobaciones/);
      expect(calls).toEqual([{ method: 'POST', path: '/approvals', body: expected }]);
    }
  });

  test('una solicitud que ya existe se explica y enlaza a Aprobaciones', async ({ page }) => {
    await login(page, 'operator');
    await mockBatch(page, { state: 'committed', commitment: 'cc'.repeat(32) });
    await page.route(`**${API}/approvals`, (r) => r.fulfill({ status: 409, json: { error: { code: 'alreadyPending', message: 'Ya hay una solicitud pendiente para este lote.' } } }));
    await page.goto(`/lotes/${base.id}`);
    await page.getByTestId('primary-action').click();
    await page.getByTestId('confirm-yes').click();
    await expect(page.getByTestId('fail')).toContainText('Ya hay una solicitud pendiente');
    await expect(page.getByTestId('fail').getByRole('link', { name: 'Ver aprobaciones' })).toBeVisible();
  });

  test('tras revelar se muestra la semilla; antes, nunca', async ({ page }) => {
    await login(page, 'auditor');
    await mockBatch(page, { state: 'closed', commitment: 'cc'.repeat(32), codesDigest: 'dd', chainHash: 'ee', beaconRound: 5, beaconValue: 'ff' });
    await page.goto(`/lotes/${base.id}`);
    await page.getByRole('tab', { name: 'Compromiso' }).click();
    await expect(page.getByTestId('commitment')).toContainText('Se publica al revelar el lote');
    await expect(page.getByTestId('seed')).toHaveCount(0);
    await page.unrouteAll();
    await mockBatch(page, { state: 'revealed', seed: '99'.repeat(32), commitment: 'cc'.repeat(32), codesDigest: 'dd', chainHash: 'ee', beaconRound: 5, beaconValue: 'ff' });
    await page.goto(`/lotes/${base.id}`);
    await page.getByRole('tab', { name: 'Compromiso' }).click();
    await expect(page.getByTestId('seed')).toHaveText('99'.repeat(32));
  });
});

async function mockBatchGetsOnly(page: Page, patch: object) {
  const batch = { ...base, ...patch };
  await page.route(new RegExp(`${API}/(batches/${base.id}|campaigns/${base.campaignId})$`), (route) => {
    const path = new URL(route.request().url()).pathname.replace(API, '');
    return route.fulfill({ json: path.startsWith('/campaigns/') ? { id: base.campaignId, name: 'Campaña simulada', prizes: [] } : batch });
  });
}

test.describe('Lotes: datos reales (P8, grupo 5a)', () => {
  test('la lista filtra por estado y el detalle de un lote activo coincide con la API', async ({ page }) => {
    await login(page, 'auditor');
    await page.goto('/lotes');
    await expect(page.getByTestId('batch-row').first()).toBeVisible();
    await page.getByLabel('Filtrar por estado').selectOption('active');
    await expect
      .poll(async () => [...new Set(await page.locator('[data-testid=batch-row] td:nth-child(3)').allInnerTexts())], { message: 'todas las filas son de lotes activos' })
      .toEqual(['Activo']);

    const { batches } = await (await page.request.get(`${API}/batches?state=active`)).json();
    const real = batches.find((b: { reserveSize: number }) => b.reserveSize > 0) ?? batches[0];
    await page.goto(`/lotes/${real.id}`);
    const detail = await (await page.request.get(`${API}/batches/${real.id}`)).json();
    await expect(page.getByTestId('redeemed')).toContainText(`${detail.redeemed} de ${detail.totalCodes}`);
    await expect(page.getByTestId('stock').locator('tbody tr')).toHaveCount(detail.pool.length);
    await expect(page.getByTestId('primary-action')).toHaveCount(0);

    // El compromiso es público; la semilla, no, antes de revelar. Y no hay mapa código → premio en ninguna parte.
    await page.getByRole('tab', { name: 'Compromiso' }).click();
    await expect(page.getByTestId('commitment')).toContainText(detail.commitment);
    await expect(page.getByTestId('seed')).toHaveCount(0);
    await page.getByRole('tab', { name: 'Canjes' }).click();
    if (detail.redeemed > 0) await expect(page.getByTestId('redemption-row').first()).toBeVisible();
    else await expect(page.getByTestId('no-redemptions')).toBeVisible();
    if (detail.reserveSize) {
      await page.getByRole('tab', { name: 'Reserva' }).click();
      await expect(page.getByTestId('reserve')).toContainText(String(detail.reserveSize));
    }
  });

  test('un lote inexistente se dice sin más', async ({ page }) => {
    await login(page, 'operator');
    await page.goto('/lotes/00000000-0000-4000-8000-000000000000');
    await expect(page.getByTestId('load-error')).toBeVisible();
  });

  test('UA-9/UA-10: axe y sin desbordes en la lista, el asistente y el detalle', async ({ page }) => {
    await login(page, 'operator');
    const { batches } = await (await page.request.get(`${API}/batches?state=active`)).json();
    const id = (batches.find((b: { reserveSize: number }) => b.reserveSize > 0) ?? batches[0]).id;
    for (const [path, name, ready] of [['/lotes', 'lotes', 'batch-row'], ['/lotes/nuevo', 'lote-asistente', null], [`/lotes/${id}`, 'lote-detalle', 'lifecycle']] as const) {
      for (const width of [1280, 768]) {
        await page.setViewportSize({ width, height: 900 });
        await page.goto(path);
        await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
        if (ready) await expect(page.getByTestId(ready).first()).toBeVisible();
        expect(await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth), `${name} a ${width}px`).toBeLessThanOrEqual(0);
        await page.screenshot({ path: `test-results/${name}-${width}.png`, fullPage: true });
      }
      const res = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa']).analyze();
      expect(res.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical'), JSON.stringify(res.violations.map((v) => [v.id, v.nodes[0]?.html?.slice(0, 140)]))).toEqual([]);
    }
  });
});
