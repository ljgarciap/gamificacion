import { GameType } from './models';

/** Tipos de la API de la muestra (SPEC-006, `docs/openapi-admin.yaml`). Los límites llegan del servidor en `limits`; aquí solo se usan para ayudar. */
export type PrizeType = 'money' | 'coupon' | 'points';

export interface DemoPrize {
  type: PrizeType;
  amount: number;
  count: number;
}

export interface DemoMixEntry {
  prizes: DemoPrize[];
  noPrizeCount: number;
}

export interface DemoProduct {
  id: string;
  name: string;
  price: number;
}

export interface DemoConfig {
  user: { displayName: string; hostUserPrefix: string; initialBalance: number };
  products: DemoProduct[];
  credit: { balancePercent: number; minPurchase: number; codeStepAmount: number; maxCodesPerPurchase: number; maxCodesPerSession: number };
  mix: Partial<Record<GameType, DemoMixEntry>>;
  ttlHours: number | null;
}

export interface DemoRun {
  id: number;
  kind: 'seed' | 'reset';
  state: 'pending' | 'running' | 'done' | 'failed';
  error: string | null;
  requestedBy: string;
  createdAt: string;
  startedAt: string | null;
  finishedAt: string | null;
}

export interface DemoLimits {
  maxProducts: number;
  maxAmount: number;
  maxCodesPerPurchase: number;
  maxPool: number;
  maxTtlHours: number;
  games: GameType[];
  prizeTypes: PrizeType[];
}

export interface DemoState {
  configured: boolean;
  config: DemoConfig;
  version: number;
  status: { enabled: boolean; enabledUntil: string | null; active: boolean; serverAllowed: boolean };
  pool: { available: number; assigned: number; retired: number };
  lastRun: DemoRun | null;
  limits: DemoLimits;
}

export const PRIZE_TYPE_LABEL: Record<PrizeType, string> = { money: 'Dinero', coupon: 'Cupón', points: 'Puntos' };

/** Totales de la mezcla de un juego: códigos con premio, sin premio y total. */
export function mixTotals(entry: DemoMixEntry): { winners: number; none: number; total: number } {
  const winners = entry.prizes.reduce((n, p) => n + (Number.isFinite(p.count) ? p.count : 0), 0);
  const none = Number.isFinite(entry.noPrizeCount) ? entry.noPrizeCount : 0;
  return { winners, none, total: winners + none };
}

/** Códigos totales del pool que se sembraría con esta configuración. */
export function poolSize(config: DemoConfig): number {
  return Object.values(config.mix).reduce((n, e) => n + (e ? mixTotals(e).total : 0), 0);
}

/** Lo que recibe quien compra `total` pesos con las reglas de carga (misma aritmética que `DemoShop`: enteros, división hacia abajo). */
export function previewPurchase(credit: DemoConfig['credit'], total: number): { allowed: boolean; balance: number; codes: number } {
  if (total < credit.minPurchase || total < 1) return { allowed: false, balance: 0, codes: 0 };
  return {
    allowed: true,
    balance: Math.floor((total * credit.balancePercent) / 100),
    codes: Math.min(Math.floor(total / credit.codeStepAmount), credit.maxCodesPerPurchase),
  };
}

const isInt = (v: unknown): v is number => typeof v === 'number' && Number.isInteger(v);

/**
 * Validación de ayuda en el cliente (el servidor impone la misma y manda). Devuelve los mensajes en español, vacío si todo está bien.
 * No intenta reproducir `DemoGameTemplates::assertPlayable`: esa regla por juego (segmentos, ganadores) solo la dice el servidor al guardar.
 */
export function validateDemo(c: DemoConfig, limits: DemoLimits): string[] {
  const out: string[] = [];
  const range = (v: unknown, name: string, min: number, max: number) => {
    if (!isInt(v) || v < min || v > max) out.push(`${name}: entero entre ${min} y ${max}.`);
  };
  if (c.user.displayName.trim().length < 1 || c.user.displayName.length > 60) out.push('Usuario: el nombre debe tener de 1 a 60 caracteres.');
  if (!/^[a-z0-9-]{1,32}$/.test(c.user.hostUserPrefix)) out.push('Usuario: el prefijo admite minúsculas, dígitos y guiones (hasta 32).');
  range(c.user.initialBalance, 'Saldo inicial', 0, limits.maxAmount);

  if (c.products.length < 1 || c.products.length > limits.maxProducts) out.push(`Catálogo: de 1 a ${limits.maxProducts} productos.`);
  const ids = new Set<string>();
  c.products.forEach((p, i) => {
    if (!/^[a-z0-9_-]{1,32}$/.test(p.id)) out.push(`Producto ${i + 1}: el identificador admite minúsculas, dígitos, guion y guion bajo.`);
    if (ids.has(p.id)) out.push(`Producto ${i + 1}: identificador repetido.`);
    ids.add(p.id);
    if (p.name.trim().length < 1 || p.name.length > 80) out.push(`Producto ${i + 1}: el nombre debe tener de 1 a 80 caracteres.`);
    range(p.price, `Producto ${i + 1}: precio`, 1, limits.maxAmount);
  });

  range(c.credit.balancePercent, 'Porcentaje de saldo', 0, 100);
  range(c.credit.minPurchase, 'Compra mínima', 0, limits.maxAmount);
  range(c.credit.codeStepAmount, 'Monto por código', 1, limits.maxAmount);
  range(c.credit.maxCodesPerPurchase, 'Códigos por compra', 1, limits.maxCodesPerPurchase);
  range(c.credit.maxCodesPerSession, 'Códigos por sesión', 1, 50);

  const games = Object.entries(c.mix) as [GameType, DemoMixEntry][];
  if (games.length === 0) out.push('Mezcla: agrega al menos un juego.');
  for (const [game, e] of games) {
    e.prizes.forEach((p, j) => {
      range(p.amount, `${game} premio ${j + 1}: monto`, p.type === 'coupon' ? 0 : 1, limits.maxAmount);
      range(p.count, `${game} premio ${j + 1}: cantidad`, 1, limits.maxPool);
    });
    range(e.noPrizeCount, `${game}: códigos sin premio`, 0, limits.maxPool);
    if (e.prizes.length === 0 && e.noPrizeCount === 0) out.push(`${game}: sin premios ni códigos sin premio.`);
  }
  if (poolSize(c) > limits.maxPool) out.push(`Mezcla: el pool total no puede superar ${limits.maxPool} códigos (hay ${poolSize(c)}).`);
  if (c.ttlHours !== null) range(c.ttlHours, 'Caducidad (horas)', 1, limits.maxTtlHours);
  return out;
}

/** Serialización con claves ordenadas: el servidor guarda la configuración en jsonb (reordena claves), así que comparar texto crudo daría «cambios» falsos. */
export function canonical(v: unknown): string {
  if (Array.isArray(v)) return `[${v.map(canonical).join(',')}]`;
  if (v !== null && typeof v === 'object') {
    const o = v as Record<string, unknown>;
    return `{${Object.keys(o).sort().map((k) => `${JSON.stringify(k)}:${canonical(o[k])}`).join(',')}}`;
  }
  return JSON.stringify(v);
}
