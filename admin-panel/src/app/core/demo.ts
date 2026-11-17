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
  /** Solo en el modo por planes (SPEC-007): si el producto cuenta para el monto del plan y da turno de ruleta. Omitido = participa. */
  participates?: boolean;
}

export type DemoMode = 'steps' | 'plans';

/** Plan de premio (SPEC-007): `event` es el de bienvenida/reactivación; `amount` entra por el monto de la compra que participa. */
export interface DemoPlan {
  id: string;
  kind: 'event' | 'amount';
  name: string;
  minAmount?: number;
  /** null = sin tope (el último plan por monto). */
  maxAmount?: number | null;
  maxMoneyPrize: number;
  mix: Partial<Record<GameType, DemoMixEntry>>;
}

export interface DemoTriggers {
  reactivationDays: number;
  turnsPerPurchase: number;
  welcomeOnFirstLogin: boolean;
}

export interface PlanPool {
  id: string;
  name: string;
  available: number;
  assigned: number;
  /** premios / (premios + sin premio) de la mezcla, calculada por el servidor. */
  probability: number;
}

export interface DemoConfig {
  user: { displayName: string; hostUserPrefix: string; initialBalance: number };
  products: DemoProduct[];
  credit: { balancePercent: number; minPurchase: number; codeStepAmount: number; maxCodesPerPurchase: number; maxCodesPerSession: number };
  mix: Partial<Record<GameType, DemoMixEntry>>;
  ttlHours: number | null;
  /** SPEC-007: sin `mode` es el modo anterior («steps»); con `plans` rigen `plans` y `triggers` (y `mix` no se usa). */
  mode?: DemoMode;
  plans?: DemoPlan[];
  triggers?: DemoTriggers;
  /** Cuentas de la muestra (SPEC-006A): retención sin ingresar, vigencia del código de ingreso y tope de cuentas. */
  accounts: DemoAccountsConfig;
}

export interface DemoAccountsConfig {
  retentionDays: number;
  codeTtlMinutes: number;
  maxAccounts: number;
}

export interface DemoAccountRow {
  id: string;
  /** Siempre enmascarado por el servidor (a***@dominio.com): el panel nunca ve el correo completo. */
  email: string;
  balance: number;
  createdAt: string;
  lastLoginAt: string;
}

export interface DemoAccountList {
  items: DemoAccountRow[];
  total: number;
  page: number;
  perPage: number;
}

export interface DemoMailInfo {
  mailer: string;
  host: string | null;
  port: number | null;
  from: string | null;
}

export type ResetAccounts = 'keep' | 'delete';

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
  maxRetentionDays: number;
  maxAccounts: number;
  minCodeTtlMinutes: number;
  maxCodeTtlMinutes: number;
  games: GameType[];
  prizeTypes: PrizeType[];
  modes: DemoMode[];
  welcomePlan: string;
  maxAmountPlans: number;
  maxReactivationDays: number;
  maxPlanName: number;
}

export interface DemoState {
  configured: boolean;
  config: DemoConfig;
  version: number;
  status: { enabled: boolean; enabledUntil: string | null; active: boolean; serverAllowed: boolean };
  pool: { available: number; assigned: number; retired: number };
  /** Existencias y probabilidad por plan (vacío en el modo anterior). */
  poolByPlan: PlanPool[];
  lastRun: DemoRun | null;
  accounts: { total: number; active7d: number };
  mail: DemoMailInfo;
  limits: DemoLimits;
}

/** Cómo se describe el envío de correo en el panel: `log` y `array` no envían nada; `smtp` en el puerto de Mailpit es el modo simulado. */
export function mailMode(m: DemoMailInfo): { label: string; sends: boolean } {
  if (m.mailer === 'smtp') return { label: m.host ? `SMTP (${m.host}${m.port ? `:${m.port}` : ''})` : 'SMTP', sends: true };
  if (m.mailer === 'log' || m.mailer === 'array') return { label: `Sin envío real (${m.mailer}): los códigos solo quedan en el registro del servidor`, sends: false };
  return { label: m.mailer, sends: true };
}

export const PRIZE_TYPE_LABEL: Record<PrizeType, string> = { money: 'Dinero', coupon: 'Cupón', points: 'Puntos' };

/** Totales de la mezcla de un juego: códigos con premio, sin premio y total. */
export function mixTotals(entry: DemoMixEntry): { winners: number; none: number; total: number } {
  const winners = entry.prizes.reduce((n, p) => n + (Number.isFinite(p.count) ? p.count : 0), 0);
  const none = Number.isFinite(entry.noPrizeCount) ? entry.noPrizeCount : 0;
  return { winners, none, total: winners + none };
}

export const isPlans = (c: DemoConfig): boolean => c.mode === 'plans';

const mixSize = (mix: Partial<Record<GameType, DemoMixEntry>>): number => Object.values(mix).reduce((n, e) => n + (e ? mixTotals(e).total : 0), 0);

/** Códigos totales del pool que se sembraría con esta configuración (en el modo por planes, la suma de todos los planes). */
export function poolSize(config: DemoConfig): number {
  return isPlans(config) ? (config.plans ?? []).reduce((n, p) => n + mixSize(p.mix), 0) : mixSize(config.mix);
}

/** Totales de la mezcla de un plan: con premio, sin premio y total (suma de todos sus juegos). */
export function planTotals(plan: DemoPlan): { winners: number; none: number; total: number } {
  return Object.values(plan.mix).reduce(
    (t, e) => {
      if (!e) return t;
      const m = mixTotals(e);
      return { winners: t.winners + m.winners, none: t.none + m.none, total: t.total + m.total };
    },
    { winners: 0, none: 0, total: 0 },
  );
}

/** Probabilidad exacta de premio de un plan = premios / total de su mezcla (0 si está vacío). Misma regla que el servidor. */
export function planProbability(plan: DemoPlan): number {
  const t = planTotals(plan);
  return t.total === 0 ? 0 : t.winners / t.total;
}

/** «15 %», «0,5 %», «100 %»: hasta un decimal, sin ceros de más. */
export function percent(p: number): string {
  const v = Math.round(p * 1000) / 10;
  return `${v.toLocaleString('es-CO', { maximumFractionDigits: 1 })} %`;
}

/** Cada plan por monto empieza donde termina el anterior + 1 (sin huecos ni solapes) y el último no tiene tope. Modifica y devuelve los planes. */
export function relinkPlans(plans: DemoPlan[]): DemoPlan[] {
  const amounts = plans.filter((p) => p.kind === 'amount');
  amounts.forEach((p, i) => {
    if (i > 0) {
      const prev = amounts[i - 1].maxAmount;
      if (typeof prev === 'number') p.minAmount = prev + 1;
    }
    if (i === amounts.length - 1) p.maxAmount = null;
  });
  return plans;
}

/** Plan por monto que corresponde a `amount` (el que participa), o null si no alcanza el primer plan. Misma regla que `DemoPlans::forAmount`. */
export function planForAmount(plans: DemoPlan[], amount: number): DemoPlan | null {
  if (!Number.isFinite(amount) || amount < 1) return null;
  return plans.find((p) => p.kind === 'amount' && amount >= (p.minAmount ?? 0) && (p.maxAmount === null || p.maxAmount === undefined || amount <= p.maxAmount)) ?? null;
}

const roulette = (prizes: DemoPrize[], noPrizeCount: number): Partial<Record<GameType, DemoMixEntry>> => ({ roulette: { prizes, noPrizeCount } });
const money = (amount: number, count: number): DemoPrize => ({ type: 'money', amount, count });
const coupon = (count: number): DemoPrize => ({ type: 'coupon', amount: 0, count });

/** Los cuatro planes PROVISIONALES de la spec (§3.1): el negocio los ajusta desde el panel. */
export function defaultPlans(): DemoPlan[] {
  return [
    { id: 'welcome', kind: 'event', name: 'Bienvenida', maxMoneyPrize: 5_000, mix: roulette([money(2_000, 45), money(3_000, 45), money(5_000, 10)], 0) },
    { id: 'bronce', kind: 'amount', name: 'Bronce', minAmount: 1_000, maxAmount: 10_000, maxMoneyPrize: 10_000, mix: roulette([money(10_000, 1), coupon(4)], 95) },
    { id: 'plata', kind: 'amount', name: 'Plata', minAmount: 10_001, maxAmount: 50_000, maxMoneyPrize: 20_000, mix: roulette([money(20_000, 1), money(10_000, 2), coupon(12)], 85) },
    { id: 'oro', kind: 'amount', name: 'Oro', minAmount: 50_001, maxAmount: null, maxMoneyPrize: 30_000, mix: roulette([money(30_000, 2), money(20_000, 5), money(10_000, 13), coupon(20)], 60) },
  ];
}

export const DEFAULT_TRIGGERS: DemoTriggers = { reactivationDays: 7, turnsPerPurchase: 1, welcomeOnFirstLogin: true };

/** Productos del charter que NO participan; se agregan al catálogo de ejemplo al pasar al modo por planes si caben. */
const EXCLUDED_PRODUCTS: DemoProduct[] = [
  { id: 'pasa-plata', name: 'Pasa Plata', price: 20_000, participates: false },
  { id: 'saca-plata', name: 'Saca Plata', price: 20_000, participates: false },
  { id: 'recarga-de-saldo', name: 'Recarga de Saldo', price: 20_000, participates: false },
];

/** Pasa el borrador al modo por planes: conserva usuario, reglas de saldo y cuentas; trae los planes provisionales y los productos excluidos. */
export function toPlansMode(c: DemoConfig, maxProducts: number): DemoConfig {
  const products: DemoProduct[] = c.products.map((p) => ({ ...p, participates: p.participates ?? true }));
  for (const e of EXCLUDED_PRODUCTS) {
    if (products.length < maxProducts && !products.some((p) => p.id === e.id)) products.push({ ...e });
  }
  return { ...c, mode: 'plans', products, mix: {}, plans: c.plans ?? defaultPlans(), triggers: c.triggers ?? { ...DEFAULT_TRIGGERS } };
}

/** Vuelve al modo anterior: quita planes, disparadores y «participa»; la mezcla clásica vuelve a la ruleta de ejemplo si estaba vacía. */
export function toStepsMode(c: DemoConfig): DemoConfig {
  const { mode: _m, plans: _p, triggers: _t, ...rest } = c;
  const products = c.products.map(({ participates: _x, ...p }) => p);
  const mix = Object.keys(c.mix ?? {}).length > 0 ? c.mix : roulette([money(5_000, 2)], 8);
  return { ...rest, products, mix };
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
  range(c.credit.maxCodesPerSession, 'Códigos por sesión', 1, 50);
  if (isPlans(c)) {
    validatePlansDraft(c, limits, out, range);
  } else {
    range(c.credit.minPurchase, 'Compra mínima', 0, limits.maxAmount);
    range(c.credit.codeStepAmount, 'Monto por código', 1, limits.maxAmount);
    range(c.credit.maxCodesPerPurchase, 'Códigos por compra', 1, limits.maxCodesPerPurchase);
    validateMixDraft(c.mix, 'Mezcla', limits, out, range);
  }
  if (poolSize(c) > limits.maxPool) out.push(`Mezcla: el pool total no puede superar ${limits.maxPool} códigos (hay ${poolSize(c)}).`);
  if (c.ttlHours !== null) range(c.ttlHours, 'Caducidad (horas)', 1, limits.maxTtlHours);
  range(c.accounts.retentionDays, 'Retención de cuentas (días)', 1, limits.maxRetentionDays);
  range(c.accounts.codeTtlMinutes, 'Vigencia del código de ingreso (minutos)', limits.minCodeTtlMinutes, limits.maxCodeTtlMinutes);
  range(c.accounts.maxAccounts, 'Tope de cuentas', 1, limits.maxAccounts);
  return out;
}

type RangeCheck = (v: unknown, name: string, min: number, max: number) => void;

function validateMixDraft(mix: Partial<Record<GameType, DemoMixEntry>>, label: string, limits: DemoLimits, out: string[], range: RangeCheck): void {
  const games = Object.entries(mix) as [GameType, DemoMixEntry][];
  if (games.length === 0) out.push(`${label}: agrega al menos un juego.`);
  for (const [game, e] of games) {
    e.prizes.forEach((p, j) => {
      range(p.amount, `${label} · ${game} premio ${j + 1}: monto`, p.type === 'coupon' ? 0 : 1, limits.maxAmount);
      range(p.count, `${label} · ${game} premio ${j + 1}: cantidad`, 1, limits.maxPool);
    });
    range(e.noPrizeCount, `${label} · ${game}: códigos sin premio`, 0, limits.maxPool);
    if (e.prizes.length === 0 && e.noPrizeCount === 0) out.push(`${label} · ${game}: sin premios ni códigos sin premio.`);
  }
}

/** Ayuda del cliente para el modo por planes (el servidor impone lo mismo y manda). */
function validatePlansDraft(c: DemoConfig, limits: DemoLimits, out: string[], range: RangeCheck): void {
  const plans = c.plans ?? [];
  const amounts = plans.filter((p) => p.kind === 'amount');
  if (plans.filter((p) => p.kind === 'event' && p.id === limits.welcomePlan).length !== 1) out.push('Planes: falta el plan de bienvenida.');
  if (amounts.length < 1 || amounts.length > limits.maxAmountPlans) out.push(`Planes: de 1 a ${limits.maxAmountPlans} planes por monto.`);
  let expectedMin: number | null = null;
  amounts.forEach((p, i) => {
    const last = i === amounts.length - 1;
    range(p.minAmount, `${p.name}: monto mínimo`, 1, limits.maxAmount);
    if (expectedMin !== null && p.minAmount !== expectedMin) out.push(`${p.name}: el monto mínimo debe ser ${expectedMin} (tope del plan anterior + 1), sin huecos ni solapes.`);
    if (last) {
      if (p.maxAmount !== null) out.push(`${p.name}: el último plan por monto no tiene tope.`);
    } else {
      range(p.maxAmount, `${p.name}: monto máximo`, typeof p.minAmount === 'number' ? p.minAmount : 1, limits.maxAmount);
      expectedMin = typeof p.maxAmount === 'number' ? p.maxAmount + 1 : null;
    }
  });
  for (const p of plans) {
    if (p.name.trim().length < 1 || p.name.length > limits.maxPlanName) out.push(`${p.name || p.id}: el nombre debe tener de 1 a ${limits.maxPlanName} caracteres.`);
    range(p.maxMoneyPrize, `${p.name}: premio máximo en dinero`, 1, limits.maxAmount);
    for (const e of Object.values(p.mix)) {
      for (const pr of e?.prizes ?? []) {
        if (pr.type === 'money' && Number.isInteger(pr.amount) && pr.amount > p.maxMoneyPrize) out.push(`${p.name}: un premio en dinero (${pr.amount}) supera el máximo del plan (${p.maxMoneyPrize}).`);
      }
    }
    validateMixDraft(p.mix, p.name, limits, out, range);
  }
  const t = c.triggers;
  if (!t) {
    out.push('Disparadores: faltan los valores.');
    return;
  }
  range(t.reactivationDays, 'Días sin comprar para la reactivación', 1, limits.maxReactivationDays);
  range(t.turnsPerPurchase, 'Turnos por compra', 1, limits.maxCodesPerPurchase);
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
