import { APIRequestContext, Page, expect, test } from '@playwright/test';

// QA de los juegos (SPEC-004 y SPEC-005) en navegador real: la web de Flutter (WEB_URL, por defecto :8080) contra el backend real (API_URL, :8000)
// con códigos de demostración reales. Para cada juego se juega con premio y sin premio y se comprueba:
//  - que lo que decidió el servidor (la respuesta REAL de /v1/codes/redeem, leída en el navegador) es coherente con el premio,
//  - que el saldo cambió exactamente lo que dice el premio (leído de la API, no de la pantalla),
//  - que no hubo errores de página ni de consola.
// Requiere `docker compose up -d db backend web` y `gamification:demo:seed`. Cada corrida gasta 2 códigos por juego.
const WEB = process.env['WEB_URL'] ?? 'http://localhost:8080';
const API = process.env['API_URL'] ?? 'http://localhost:8000';

interface DemoCode { code: string; game: string; description: string }

async function demoCodes(request: APIRequestContext): Promise<DemoCode[]> {
  return (await (await request.get(`${API}/v1/dev/demo-codes`)).json()).codes;
}

async function balance(request: APIRequestContext): Promise<number> {
  const { token } = await (await request.post(`${API}/v1/dev/token`, { data: { userId: 'demo-user' } })).json();
  return (await (await request.get(`${API}/v1/balance`, { headers: { Authorization: `Bearer ${token}` } })).json()).amount;
}

/** El primer código sin canjear de ese juego cuyo premio es dinero (el más barato) o «Sin premio». */
function pick(codes: DemoCode[], prefix: string, kind: 'money' | 'none'): DemoCode {
  const mine = codes.filter((c) => c.code.startsWith(prefix));
  const found =
    kind === 'none'
      ? mine.find((c) => /Sin premio/.test(c.description))
      : mine.filter((c) => /\$ [\d.]+$/.test(c.description)).sort((a, b) => amount(a.description) - amount(b.description))[0];
  if (!found) throw new Error(`No quedan códigos de ${prefix} (${kind}): vuelve a correr gamification:demo:seed`);
  return found;
}

const amount = (description: string): number => Number(/\$ ([\d.]+)$/.exec(description)?.[1]?.replace(/\./g, '') ?? 0);

async function open(page: Page): Promise<string[]> {
  const problems: string[] = [];
  page.on('pageerror', (e) => problems.push(`pageerror: ${e.message}`));
  page.on('console', (m) => {
    if (m.type() === 'error') problems.push(`console: ${m.text().slice(0, 200)}`);
  });
  await page.goto(WEB);
  await page.waitForTimeout(3500);
  // Flutter Web: el árbol de accesibilidad se activa con foco + Enter en el marcador.
  await page.locator('flt-semantics-placeholder').focus().catch(() => undefined);
  await page.keyboard.press('Enter');
  await page.waitForTimeout(1000);
  return problems;
}

async function redeem(page: Page, code: string): Promise<{ prize: { id: string; type: string; amount?: number }; gameResult: Record<string, unknown>; config: Record<string, unknown> }> {
  // La respuesta REAL del servidor se lee dentro de la interceptación (leerla después falla: el navegador descarta el cuerpo).
  let captured: any = null;
  await page.route('**/v1/codes/redeem', async (route) => {
    if (route.request().method() !== 'POST') return route.continue();
    const response = await route.fetch();
    captured = { status: response.status(), body: await response.json() };
    return route.fulfill({ response });
  });
  await page.getByRole('textbox').first().click();
  await page.waitForTimeout(600);
  await page.keyboard.type(code, { delay: 40 });
  await page.getByRole('button', { name: /Validar código/ }).click();
  await expect.poll(() => captured, { timeout: 15_000 }).not.toBeNull();
  expect(captured.status).toBe(200);
  const body = captured.body;
  return { prize: body.outcome.prize, gameResult: body.outcome.gameResult, config: body.campaign.gameConfig };
}

/** Termina la partida (con el botón que cada juego ofrece) y espera la pantalla de resultado. */
async function finish(page: Page, button: RegExp): Promise<void> {
  await page.getByRole('button', { name: button }).first().click();
  await expect(page.getByRole('button', { name: /Continuar/ })).toBeVisible({ timeout: 30_000 });
}

interface Case {
  name: string;
  prefix: string;
  button: RegExp;
  /** Comprobaciones de coherencia entre lo que decidió el servidor y el premio. */
  check: (r: { prize: { id: string; type: string }; gameResult: Record<string, any>; config: Record<string, any> }, wins: boolean) => void;
}

const eights = (faces: string[]) => faces.filter((f) => f === '8').length;

const GAMES: Case[] = [
  { name: 'Ruleta', prefix: 'RULETA', button: /GIRAR/, check: (r) => expect(r.gameResult['segmentIndex']).toBeGreaterThanOrEqual(0) },
  { name: 'Raspa y Gana', prefix: 'RASPA', button: /Revelar todo/, check: (r, wins) => expect(r.gameResult['grid'].filter((s: string) => s === r.prize.id).length).toBe(wins ? 3 : 0) },
  {
    name: 'Concéntrese',
    prefix: 'CONCENTRESE',
    button: /Revelar todo/,
    check: (r, wins) => expect(r.gameResult['cards'].filter((c: string) => c === r.prize.id).length).toBe(wins ? 2 : 0),
  },
  {
    name: 'Dados',
    prefix: 'DADOS',
    button: /TIRAR/,
    check: (r, wins) => {
      const [a, b] = r.gameResult['dice'] as number[];
      expect(a === b).toBe(wins); // en el demo solo pagan los dobles
    },
  },
  {
    name: 'Bomba 8',
    prefix: 'BOMBA',
    button: /Revelar todo/,
    check: (r, wins) => {
      const n = eights(r.gameResult['faces']);
      expect(wins ? n === 3 : n < 3).toBe(true);
      if (wins) expect(r.gameResult['faces'].lastIndexOf('8')).toBeGreaterThanOrEqual(r.gameResult['faces'].length - 4); // el tercero va al final
    },
  },
  {
    name: 'Bingo en vivo',
    prefix: 'BINGO',
    button: /Revelar todo/,
    check: (r, wins) => {
      expect(r.gameResult['balls']).toHaveLength(r.config['draws']);
      expect(new Set(r.gameResult['balls']).size).toBe(r.gameResult['balls'].length);
      expect(r.gameResult['pattern'] !== null).toBe(wins);
      expect(r.gameResult['winAt'] !== null).toBe(wins);
      if (wins) expect(r.config['legend'].find((l: { pattern: string }) => l.pattern === r.gameResult['pattern']).prize.id).toBe(r.prize.id);
    },
  },
  {
    name: 'Póker del crupier',
    prefix: 'POKER',
    button: /Revelar todo/,
    check: (r, wins) => {
      const cards = [...r.gameResult['hole'], ...r.gameResult['board']] as string[];
      expect(new Set(cards).size).toBe(7);
      expect(r.gameResult['hand'] !== null).toBe(wins);
      expect(r.gameResult['stages'][2]).toBe(r.gameResult['category']);
      if (wins) expect(r.config['legend'].find((l: { hand: string }) => l.hand === r.gameResult['hand']).prize.id).toBe(r.prize.id);
    },
  },
];

test.describe.configure({ mode: 'serial' });
test.use({ viewport: { width: 420, height: 1000 } });

for (const g of GAMES) {
  for (const kind of ['money', 'none'] as const) {
    test(`${g.name}: ${kind === 'money' ? 'con premio' : 'sin premio'} — lo decidido por el servidor, lo mostrado y el saldo coinciden`, async ({ page, request }) => {
      const codes = await demoCodes(request);
      const demo = pick(codes, g.prefix, kind);
      const expected = kind === 'money' ? amount(demo.description) : 0;
      const before = await balance(request);

      const problems = await open(page);
      const r = await redeem(page, demo.code);
      g.check(r, kind === 'money');
      expect(r.prize.type === 'none').toBe(kind === 'none');
      await page.screenshot({ path: `test-results/juegos-${g.prefix.toLowerCase()}-${kind}-juego.png` });

      await finish(page, g.button);
      await page.screenshot({ path: `test-results/juegos-${g.prefix.toLowerCase()}-${kind}-resultado.png` });

      await expect.poll(() => balance(request), { timeout: 10_000 }).toBe(before + expected);
      expect(problems).toEqual([]);
    });
  }
}

test('reutilizar un código (showPrevious) muestra la misma presentación y no vuelve a acreditar', async ({ page, request }) => {
  const before = await balance(request);
  const problems = await open(page);
  // BOMBA usa «showPrevious»: reutilizar un código ya jugado muestra el resultado anterior, sin acreditar de nuevo (ver RF-63).
  const codes = await demoCodes(request);
  const unused = pick(codes, 'BOMBA', 'money');
  const first = await redeem(page, unused.code);
  expect(first.prize.type).toBe('money');
  await finish(page, /Revelar todo/);
  const after = await balance(request);
  expect(after).toBe(before + amount(unused.description));

  await page.reload();
  await page.waitForTimeout(3500);
  await page.locator('flt-semantics-placeholder').focus().catch(() => undefined);
  await page.keyboard.press('Enter');
  await page.waitForTimeout(1000);
  const again = await redeem(page, unused.code);
  expect(again.gameResult['faces']).toEqual(first.gameResult['faces']); // la misma presentación, byte a byte
  expect(await balance(request)).toBe(after);
  expect(problems.filter((p) => !/409|alreadyUsed/.test(p))).toEqual([]);
});
