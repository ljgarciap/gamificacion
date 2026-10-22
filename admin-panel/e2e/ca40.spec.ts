import { expect, test, Page } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { apiCall, fixtures, login } from './support';
import { freshCode } from './totp';

const API = '/admin/api/v1';
const stamp = () => Date.now().toString(36);

/** Quien aprueba: abre la bandeja, comprueba lo que se le pide y aprueba con un código TOTP NUEVO. */
async function approveAs(boss: Page, title: string, params: string[]): Promise<number> {
  await boss.goto('/aprobaciones');
  const row = boss.getByTestId('tab-mine-to-approve').getByTestId('approval').filter({ hasText: title });
  await expect(row).toHaveCount(1);
  for (const p of params) await expect(row).toContainText(p);
  const id = Number(await row.getAttribute('data-id'));
  await boss.getByTestId(`approve-${id}`).click();
  await expect(boss.getByTestId('approve-form')).toContainText(title);
  await boss.getByTestId('approve-code').fill(await freshCode(fixtures().users.superadmin.secretBase32));
  await boss.getByTestId('approve-submit').click();
  await expect(boss.getByTestId('message')).toContainText('Aprobada');
  return id;
}

/** Quien solicitó ejecuta; si la baliza aún no publica la ronda, la aprobación sigue vigente y se reintenta. */
async function executeAs(op: Page, id: number): Promise<void> {
  await op.goto('/aprobaciones');
  await op.getByRole('tab', { name: /Esperan ejecución/ }).click();
  for (let attempt = 0; attempt < 6; attempt++) {
    await op.getByTestId(`execute-${id}`).click();
    const done = op.getByTestId('message').filter({ hasText: 'Ejecutada' });
    const wait = op.getByTestId('fail').filter({ hasText: 'baliza' });
    await expect(done.or(wait)).toBeVisible();
    if (await done.isVisible()) return;
    await op.waitForTimeout(3000);
  }
  throw new Error('La baliza no publicó la ronda a tiempo');
}

async function typedAction(op: Page, buttonName: string, batchName: string, message: RegExp): Promise<void> {
  await op.getByTestId('primary-action').click();
  await op.getByTestId('typed-name').fill(batchName);
  await op.getByTestId('confirm-yes').click();
  await expect(op.getByTestId('message')).toContainText(message);
}

test('UA-1: un operador completa el recorrido de CA-40 desde el navegador, con una segunda persona que aprueba', async ({ browser, page: op, baseURL }) => {
  test.setTimeout(10 * 60_000);
  const f = fixtures();
  await login(op, 'operator');
  const bossCtx = await browser.newContext({ baseURL });
  const boss = await bossCtx.newPage();
  await login(boss, 'superadmin');

  // 1. Campaña con un premio de giro extra, creada desde la interfaz.
  const campaign = `E2E CA-40 ${stamp()}`;
  await op.goto('/campanas/nueva');
  await op.getByLabel('Nombre', { exact: true }).fill(campaign);
  await op.getByRole('tab', { name: 'Juego' }).click();
  await op.getByTestId('game-json').fill(JSON.stringify({ segments: ['none', 'p50k', 'extra', 'none', 'p50k', 'none', 'extra', 'none'] }));
  await op.getByRole('tab', { name: 'General' }).click();
  await op.getByRole('button', { name: 'Crear campaña' }).click();
  await expect(op).toHaveURL(/\/campanas\/[0-9a-f-]{36}$/);
  await op.getByRole('tab', { name: 'Premios' }).click();
  for (const [id, label, type, extra] of [['none', 'Sin premio', 'Sin premio', ''], ['p50k', '$ 50.000', 'Dinero', '50000'], ['extra', 'Gira otra vez', 'Giro extra', '2']] as const) {
    await op.getByLabel('Id del premio').fill(id);
    await op.getByRole('combobox', { name: 'Tipo' }).click();
    await op.getByRole('option', { name: type, exact: true }).click();
    await op.getByLabel('Etiqueta', { exact: true }).fill(label);
    if (type === 'Dinero') await op.getByLabel('Monto (COP, entero)').fill(extra);
    if (type === 'Giro extra') await op.getByLabel('Giros por premio').fill(extra);
    await op.getByRole('button', { name: 'Agregar premio' }).click();
    await expect(op.getByTestId('prize-row').filter({ hasText: id })).toHaveCount(1);
  }

  // 2. Lote con reserva: 1 premio de giro extra × 2 giros = reserva mínima 2.
  const batch = `Lote CA-40 ${stamp()}`;
  await op.goto('/lotes/nuevo');
  await op.getByRole('combobox', { name: 'Campaña' }).click();
  await op.getByRole('option', { name: campaign }).click();
  await op.getByLabel('Nombre del lote').fill(batch);
  await op.getByRole('button', { name: 'Siguiente' }).click();
  for (const [i, prize, qty] of [[0, /Sin premio/, 8], [1, /\$ 50\.000/, 2], [2, /Gira otra vez/, 1]] as const) {
    if (i > 0) await op.getByRole('button', { name: 'Agregar premio al pool' }).click();
    await op.getByRole('combobox', { name: `Premio ${i + 1}` }).click();
    await op.getByRole('option', { name: prize }).click();
    await op.getByLabel(`Cantidad ${i + 1}`).fill(String(qty));
  }
  await op.getByTestId('reserve').fill('2');
  await expect(op.getByTestId('n-codes')).toHaveText('9');
  await op.getByRole('button', { name: 'Siguiente' }).click();
  await op.getByTestId('create-batch').click();
  await expect(op).toHaveURL(/\/lotes\/[0-9a-f-]{36}$/);
  const batchUrl = op.url();
  const batchId = batchUrl.split('/').pop()!;

  // 3. Generar códigos: control dual. El operador solicita; no puede aprobar lo suyo (UA-2).
  await op.getByTestId('primary-action').click();
  await op.getByLabel('Prefijo de los códigos').fill('CATE');
  await op.getByTestId('confirm-yes').click();
  await expect(op).toHaveURL(/\/aprobaciones/);
  await expect(op.getByTestId('requested')).toContainText('Pídele a otra persona que la apruebe');
  await op.getByRole('tab', { name: /Mis solicitudes/ }).click();
  const mine = op.getByTestId('tab-requested').getByTestId('approval').filter({ hasText: batch });
  await expect(mine.getByTestId('needs-other')).toBeVisible();
  await expect(mine.getByRole('button', { name: /Aprobar/ })).toHaveCount(0);
  const genId = Number(await mine.getAttribute('data-id'));
  // UA-2: aunque lo intentara por la API con un código válido, el servidor lo rechaza y la solicitud sigue pendiente.
  const self = await op.request.fetch(`${API}/approvals/${genId}/approve`, {
    method: 'POST', data: { code: await freshCode(f.users.operator.secretBase32) },
    headers: { 'X-XSRF-TOKEN': decodeURIComponent((await op.context().cookies()).find((c) => c.name === 'XSRF-TOKEN')!.value) },
  });
  expect(self.status()).toBe(403);
  expect((await self.json()).error.code).toBe('selfApproval');
  await op.reload();
  await op.getByRole('tab', { name: /Mis solicitudes/ }).click();
  await expect(op.getByTestId('tab-requested').locator(`[data-id="${genId}"]`)).toHaveAttribute('data-status', 'pending');

  // El superadmin ve la solicitud con sus parámetros reales, se equivoca de código y luego aprueba bien.
  await boss.goto('/aprobaciones');
  await boss.getByTestId(`approve-${genId}`).click();
  await boss.getByTestId('approve-code').fill('000000');
  await boss.getByTestId('approve-submit').click();
  await expect(boss.getByTestId('fail')).toContainText('El código no es válido');
  await boss.getByTestId('approve-code').fill(await freshCode(f.users.superadmin.secretBase32));
  await boss.getByTestId('approve-submit').click();
  await expect(boss.getByTestId('message')).toContainText('Aprobada');

  // 4. El operador ejecuta: descarga única de los 9 códigos, verificada por SHA-256 (UA-7).
  await op.goto('/aprobaciones');
  await op.getByRole('tab', { name: /Esperan ejecución/ }).click();
  await op.getByTestId(`execute-${genId}`).click();
  await expect(op.getByTestId('delivery')).toContainText('9 códigos generados');
  const expected = (await op.getByTestId('sha-expected').innerText()).trim();
  const [csv] = await Promise.all([op.waitForEvent('download'), op.getByTestId(`download-${genId}`).click()]);
  await expect(op.getByTestId('verdict')).toContainText('el SHA-256 coincide');
  const bytes = readFileSync((await csv.path())!);
  expect(createHash('sha256').update(bytes).digest('hex')).toBe(expected);
  expect(bytes.toString('utf8').match(/CATE-/g)?.length).toBe(9);
  // La segunda descarga ya no existe (410).
  expect((await op.request.get(`${API}/approvals/${genId}/download`)).status()).toBe(410);

  // 5. Comprometer (con recibo).
  await op.goto(batchUrl);
  await expect(op.getByTestId('lifecycle').locator('[aria-current=step]')).toHaveText(/Con códigos/);
  await op.getByTestId('primary-action').click();
  await op.getByTestId('confirm-yes').click();
  await expect(op.getByTestId('receipt')).toBeVisible();
  await expect(op.getByTestId('lifecycle').locator('[aria-current=step]')).toHaveText(/Comprometido/);

  // 6. Activar: solicitud → aprobación de la otra persona → ejecución.
  await op.getByTestId('primary-action').click();
  await op.getByTestId('confirm-yes').click();
  await expect(op).toHaveURL(/\/aprobaciones/);
  const actId = await approveAs(boss, `Activar el lote «${batch}»`, [batchId]);
  await executeAs(op, actId);
  await op.goto(batchUrl);
  await expect(op.getByTestId('lifecycle').locator('[aria-current=step]')).toHaveText(/Activo/);

  // 7. Cerrar y revelar (confirmación escrita). La semilla solo aparece tras revelar.
  await op.getByRole('tab', { name: 'Compromiso' }).click();
  await expect(op.getByTestId('seed')).toHaveCount(0);
  await typedAction(op, 'Cerrar el lote', batch, /Lote cerrado/);
  await expect(op.getByTestId('lifecycle').locator('[aria-current=step]')).toHaveText(/Cerrado/);
  await typedAction(op, 'Revelar el lote', batch, /Lote revelado/);
  await expect(op.getByTestId('lifecycle').locator('[aria-current=step]')).toHaveText(/Revelado/);
  await op.getByRole('tab', { name: 'Compromiso' }).click();
  await expect(op.getByTestId('seed')).toHaveText(/^[0-9a-f]{64}$/);

  // 8. Exportar la auditoría (otra aprobación) y comprobarla con el verificador independiente.
  await op.getByTestId('primary-action').click();
  await op.getByTestId('confirm-yes').click();
  const expId = await approveAs(boss, `Exportar la auditoría del lote «${batch}»`, [batchId]);
  await executeAs(op, expId);
  await expect(op.getByTestId('anchor')).toBeVisible();
  const [audit] = await Promise.all([op.waitForEvent('download'), op.getByTestId(`download-${expId}`).click()]);
  await expect(op.getByTestId('verdict')).toContainText('el SHA-256 coincide');
  const file = (await audit.path())!;
  const out = execFileSync('python3', ['-I', join(process.cwd(), '..', 'verifier', 'gamif_verify.py'), 'verify', file], { encoding: 'utf8' });
  expect(out).toContain('OK: el compromiso se reproduce, la baraja coincide con las asignaciones y los conteos son el stock.');

  // Estado final en el servidor.
  const final = await apiCall<{ state: string; totalCodes: number; reserveSize: number; seed: string }>(op, 'GET', `/batches/${batchId}`);
  expect(final).toMatchObject({ state: 'revealed', totalCodes: 9, reserveSize: 2 });
  await bossCtx.close();
});
