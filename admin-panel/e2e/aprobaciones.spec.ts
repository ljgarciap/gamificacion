import AxeBuilder from '@axe-core/playwright';
import { expect, test, Page } from '@playwright/test';
import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { fixtures, login } from './support';

const API = '/admin/api/v1';
const BATCH = '11111111-1111-4111-8111-111111111111';
const iso = (ms: number) => new Date(Date.now() + ms).toISOString();

/** Id del usuario con sesión iniciada (el servidor lo dice en /auth/me; /users es solo de superadmin). */
async function myId(page: Page): Promise<number> {
  return (await (await page.request.get(`${API}/auth/me`)).json()).id;
}

const approval = (id: number, over: object) => ({
  id, action: 'codes.generate', subjectType: 'batch', subjectId: BATCH, params: { batchId: BATCH, prefix: 'PROMO' }, status: 'pending',
  requestedBy: { id: 900, name: 'Otra Persona' }, approvedBy: null, requestedAt: iso(-3600_000), expiresAt: iso(20 * 3600_000), approvedAt: null, executedAt: null, ...over,
});

/** Simula la bandeja de aprobaciones y registra lo que el panel envía. */
async function mockApprovals(page: Page, list: object[], opts: { few?: boolean; approve?: { status: number; code?: string; message?: string }; execute?: object | number } = {}) {
  const calls: { path: string; body: unknown }[] = [];
  await page.route(new RegExp(`${API}/(approvals(/.*)?|batches)$`), (route) => {
    const req = route.request();
    const path = new URL(req.url()).pathname.replace(API, '');
    if (req.method() === 'GET' && path === '/approvals') return route.fulfill({ json: { approvals: list, fewApprovers: opts.few ?? false } });
    if (req.method() === 'GET' && path === '/batches') return route.fulfill({ json: { batches: [{ id: BATCH, name: 'Lote simulado' }] } });
    if (req.method() === 'GET') return route.continue();
    calls.push({ path, body: req.postData() ? req.postDataJSON() : null });
    if (path.endsWith('/approve')) return route.fulfill(opts.approve ? { status: opts.approve.status, json: { error: { code: opts.approve.code, message: opts.approve.message } } } : { status: 204 });
    if (path.endsWith('/execute')) {
      if (typeof opts.execute === 'number') return route.fulfill({ status: opts.execute, json: { error: { code: 'beaconNotReady', message: 'x' } } });
      return opts.execute ? route.fulfill({ json: opts.execute }) : route.fulfill({ status: 204 });
    }
    return route.fulfill({ status: 204 });
  });
  return calls;
}

test.describe('Aprobaciones (P8, grupo 5b)', () => {
  test('cada pestaña muestra lo suyo; los parámetros salen completos y lo propio no se puede aprobar', async ({ page }) => {
    await login(page, 'superadmin');
    const me = await myId(page);
    const mine = { id: me, name: 'E2E superadmin' };
    await mockApprovals(page, [
      approval(1, {}),
      approval(2, { requestedBy: mine, action: 'batch.activate', params: { batchId: BATCH } }),
      approval(3, { requestedBy: mine, status: 'approved', approvedBy: { id: 900, name: 'Otra Persona' }, approvedAt: iso(-60_000) }),
      approval(4, { status: 'approved', approvedBy: mine, approvedAt: iso(-60_000) }),
      approval(5, { status: 'executed', executedAt: iso(-1000) }),
      approval(6, { status: 'expired', expiresAt: iso(-1000) }),
      approval(7, { expiresAt: iso(90 * 60_000) }),
    ], { few: true });
    await page.goto('/aprobaciones');
    await expect(page.getByTestId('few-approvers')).toContainText('Solo hay una persona que puede aprobar');
    // Esperan mi aprobación: las pendientes ajenas (1 y 7).
    const waiting = page.getByTestId('tab-mine-to-approve').getByTestId('approval');
    await expect(waiting).toHaveCount(2);
    await expect(waiting.first()).toContainText('Generar los códigos del lote «Lote simulado»');
    await expect(waiting.first().getByTestId('param-prefix')).toHaveText('PROMO');
    await expect(waiting.first().getByTestId('param-batchId')).toContainText(BATCH);
    await expect(page.getByTestId('approvals-badge')).toHaveText('2');
    // Falta menos de 2 horas: aviso naranja con texto, no solo color.
    await expect(page.locator('[data-id="7"] .soon')).toContainText('caduca en 1 h 29 min');
    await expect(page.locator('[data-id="1"] .soon')).toHaveCount(0);

    await page.getByRole('tab', { name: /Mis solicitudes/ }).click();
    const requested = page.getByTestId('tab-requested');
    await expect(requested.getByTestId('approval')).toHaveCount(2);
    await expect(requested.locator('[data-id="2"]').getByTestId('needs-other')).toContainText('tú no puedes aprobar tu propia solicitud');
    await expect(requested.getByRole('button', { name: /Aprobar/ })).toHaveCount(0);

    await page.getByRole('tab', { name: /Esperan ejecución/ }).click();
    const exec = page.getByTestId('tab-to-execute');
    await expect(exec.getByTestId('approval')).toHaveCount(2);
    await expect(exec.locator('[data-id="3"]').getByRole('button', { name: /Ejecutar/ })).toBeVisible();
    await expect(exec.locator('[data-id="4"]').getByRole('button', { name: /Ejecutar/ })).toHaveCount(0);
    await expect(exec.locator('[data-id="4"]')).toContainText('La ejecuta quien la solicitó');

    await page.getByRole('tab', { name: /Historial/ }).click();
    await expect(page.getByTestId('tab-history').getByTestId('approval')).toHaveCount(2);
  });

  test('aprobar: el diálogo repite los parámetros reales, pide un código de 6 dígitos y explica cada rechazo', async ({ page }) => {
    await login(page, 'operator');
    for (const [status, code, text] of [[403, 'selfApproval', 'No puedes aprobar tu propia solicitud'], [422, 'invalidCode', 'El código no es válido'], [409, 'expired', 'La solicitud caducó']] as const) {
      await page.unrouteAll();
      const calls = await mockApprovals(page, [approval(1, {})], { approve: { status, code, message: 'x' } });
      await page.goto('/aprobaciones');
      await page.getByTestId('approve-1').click();
      const form = page.getByTestId('approve-form');
      await expect(form).toContainText('Vas a aprobar: Generar los códigos del lote «Lote simulado»');
      await expect(form).toContainText('Prefijo: PROMO');
      await expect(form).toContainText(`Lote: Lote simulado · ${BATCH}`);
      const input = page.getByTestId('approve-code');
      await expect(input).toHaveAttribute('autocomplete', 'one-time-code');
      await expect(input).toHaveAttribute('inputmode', 'numeric');
      await expect(page.getByTestId('approve-submit')).toBeDisabled();
      await input.fill('123456');
      await page.getByTestId('approve-submit').click();
      await expect(page.getByTestId('fail')).toContainText(text);
      expect(calls).toEqual([{ path: '/approvals/1/approve', body: { code: '123456' } }]);
      await expect(input).toBeVisible();
    }
  });

  test('ejecutar la activación: si la baliza no está lista, la aprobación sigue vigente y se reintenta', async ({ page }) => {
    await login(page, 'operator');
    const me = await myId(page);
    const calls = await mockApprovals(page, [approval(3, { requestedBy: { id: me, name: 'E2E operator' }, action: 'batch.activate', params: { batchId: BATCH }, status: 'approved', approvedBy: { id: 900, name: 'X' } })], { execute: 503 });
    await page.goto('/aprobaciones');
    await page.getByRole('tab', { name: /Esperan ejecución/ }).click();
    await page.getByTestId('execute-3').click();
    await expect(page.getByTestId('fail')).toContainText('la aprobación sigue vigente');
    await page.getByTestId('execute-3').click();
    expect(calls.map((c) => c.path)).toEqual(['/approvals/3/execute', '/approvals/3/execute']);
  });

  const CSV = 'codigo\nPROMO-AAAA-BBBB-CCC\n';
  const sha = (s: string) => createHash('sha256').update(s).digest('hex');

  async function deliveryScenario(page: Page, served: { body: string; header: string; status?: number }) {
    await login(page, 'operator');
    const me = await myId(page);
    await mockApprovals(page, [approval(3, { requestedBy: { id: me, name: 'E2E operator' }, status: 'approved', approvedBy: { id: 900, name: 'X' } })], {
      execute: { count: 1, sha256: sha(CSV), downloadUntil: iso(30 * 60_000) },
    });
    let downloads = 0;
    await page.route(`**${API}/approvals/3/download`, (r) => {
      downloads++;
      if (served.status) return r.fulfill({ status: served.status, json: { error: { code: 'gone', message: 'x' } } });
      return r.fulfill({ status: 200, headers: { 'Content-Type': 'text/csv', 'X-Content-SHA256': served.header }, body: served.body });
    });
    await page.goto('/aprobaciones');
    await page.getByRole('tab', { name: /Esperan ejecución/ }).click();
    await page.getByTestId('execute-3').click();
    await expect(page.getByTestId('delivery')).toContainText('1 códigos generados');
    await expect(page.getByTestId('sha-expected')).toHaveText(sha(CSV));
    return () => downloads;
  }

  test('UA-7: la descarga única se comprueba contra el SHA-256 y entrega el archivo', async ({ page }) => {
    await deliveryScenario(page, { body: CSV, header: sha(CSV) });
    const [file] = await Promise.all([page.waitForEvent('download'), page.getByTestId('download-3').click()]);
    await expect(page.getByTestId('verdict')).toContainText('el SHA-256 coincide');
    expect(file.suggestedFilename()).toBe('codigos-3.csv');
    expect(sha(readFileSync((await file.path())!, 'utf8'))).toBe(sha(CSV));
    await expect(page.getByTestId('download-3')).toBeDisabled();
  });

  test('UA-7: un archivo alterado se rechaza y no se entrega', async ({ page }) => {
    await deliveryScenario(page, { body: CSV.replace('AAAA', 'ZZZZ'), header: sha(CSV) });
    let downloaded = false;
    page.on('download', () => { downloaded = true; });
    await page.getByTestId('download-3').click();
    await expect(page.getByTestId('verdict')).toContainText('no lo uses');
    await page.waitForTimeout(500);
    expect(downloaded).toBe(false);
  });

  test('UA-7: la segunda descarga da 410 y la interfaz lo explica', async ({ page }) => {
    await deliveryScenario(page, { body: '', header: '', status: 410 });
    await page.getByTestId('download-3').click();
    await expect(page.getByTestId('verdict')).toContainText('Ya no está disponible');
    await expect(page.getByTestId('verdict')).toContainText('recrear el lote');
  });

  test('el auditor ve las solicitudes sin ningún botón de aprobar ni ejecutar', async ({ page }) => {
    await login(page, 'auditor');
    await mockApprovals(page, [approval(1, {}), approval(3, { status: 'approved', approvedBy: { id: 901, name: 'Y' } })]);
    await page.goto('/aprobaciones');
    await expect(page.getByRole('tab', { name: /Esperan mi aprobación/ })).toHaveCount(0);
    await expect(page.getByRole('tab', { name: /Mis solicitudes/ })).toHaveCount(0);
    await page.getByRole('tab', { name: /Pendientes/ }).click();
    await expect(page.getByTestId('tab-pending').getByTestId('approval')).toHaveCount(1);
    await page.getByRole('tab', { name: /Esperan ejecución/ }).click();
    await expect(page.getByTestId('approval').first()).toBeVisible();
    for (const name of [/Aprobar/, /Ejecutar/, /Descargar/]) await expect(page.getByRole('button', { name })).toHaveCount(0);
    await expect(page.getByTestId('approvals-badge')).toHaveCount(0);
  });

  test('tras solicitar desde otra pantalla se avisa que otra persona debe aprobar', async ({ page }) => {
    await login(page, 'operator');
    await mockApprovals(page, [], { few: true });
    await page.goto('/aprobaciones?solicitada=batch.activate');
    await expect(page.getByTestId('requested')).toContainText('Pídele a otra persona que la apruebe');
    await expect(page.getByTestId('requested')).toContainText('Ahora mismo nadie más puede aprobarla');
  });

  test('UA-9/UA-10: axe y sin desbordes', async ({ page }) => {
    await login(page, 'operator');
    const me = await myId(page);
    await mockApprovals(page, [approval(1, {}), approval(2, { requestedBy: { id: me, name: 'E2E operator' } }), approval(7, { expiresAt: iso(90 * 60_000) })], { few: true });
    for (const width of [1280, 768]) {
      await page.setViewportSize({ width, height: 900 });
      await page.goto('/aprobaciones');
      await expect(page.getByTestId('approval').first()).toBeVisible();
      await page.getByTestId('approve-1').click();
      expect(await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth), `a ${width}px`).toBeLessThanOrEqual(0);
      await page.screenshot({ path: `test-results/aprobaciones-${width}.png`, fullPage: true });
    }
    const res = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa']).analyze();
    expect(res.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical'), JSON.stringify(res.violations.map((v) => [v.id, v.nodes[0]?.html?.slice(0, 140)]))).toEqual([]);
  });
});
