import { DemoConfig, DemoLimits, canonical, mailMode, mixTotals, poolSize, previewPurchase, validateDemo } from './demo';

const limits: DemoLimits = { maxProducts: 5, maxAmount: 10_000_000, maxCodesPerPurchase: 5, maxPool: 500, maxTtlHours: 168, maxRetentionDays: 365, maxAccounts: 5000, minCodeTtlMinutes: 5, maxCodeTtlMinutes: 30, games: ['roulette', 'scratch'], prizeTypes: ['money', 'coupon', 'points'] };
const base = (): DemoConfig => ({
  user: { displayName: 'Cliente', hostUserPrefix: 'muestra', initialBalance: 0 },
  products: [{ id: 'a', name: 'Recarga', price: 20_000 }],
  credit: { balancePercent: 10, minPurchase: 10_000, codeStepAmount: 20_000, maxCodesPerPurchase: 3, maxCodesPerSession: 10 },
  mix: { roulette: { prizes: [{ type: 'money', amount: 5_000, count: 2 }], noPrizeCount: 8 } },
  ttlHours: 24,
  accounts: { retentionDays: 30, codeTtlMinutes: 10, maxAccounts: 500 },
});

describe('muestra: cálculos del panel', () => {
  it('suma ganadores, sin premio y total de un juego y del pool', () => {
    const c = base();
    c.mix.scratch = { prizes: [{ type: 'points', amount: 10, count: 3 }, { type: 'coupon', amount: 0, count: 1 }], noPrizeCount: 6 };
    expect(mixTotals(c.mix.roulette!)).toEqual({ winners: 2, none: 8, total: 10 });
    expect(mixTotals(c.mix.scratch)).toEqual({ winners: 4, none: 6, total: 10 });
    expect(poolSize(c)).toBe(20);
  });

  it('la vista previa de compra usa enteros hacia abajo y respeta mínimo y topes', () => {
    const credit = base().credit;
    expect(previewPurchase(credit, 9_999)).toEqual({ allowed: false, balance: 0, codes: 0 });
    expect(previewPurchase(credit, 10_000)).toEqual({ allowed: true, balance: 1_000, codes: 0 });
    expect(previewPurchase(credit, 50_000)).toEqual({ allowed: true, balance: 5_000, codes: 2 });
    expect(previewPurchase(credit, 999_999)).toEqual({ allowed: true, balance: 99_999, codes: 3 });
    expect(previewPurchase({ ...credit, balancePercent: 33 }, 10_001).balance).toBe(3_300);
  });

  it('la configuración por defecto es válida', () => {
    expect(validateDemo(base(), limits)).toEqual([]);
  });

  it('señala cada regla incumplida con un mensaje', () => {
    const c = base();
    c.user.hostUserPrefix = 'Mayús';
    c.products = [{ id: 'a', name: '', price: 0 }, { id: 'a', name: 'x', price: 1 }];
    c.credit.balancePercent = 101;
    c.credit.maxCodesPerPurchase = 6;
    c.mix.roulette!.prizes[0].count = 0;
    c.ttlHours = 999;
    const m = validateDemo(c, limits).join('|');
    for (const piece of ['prefijo', 'repetido', 'nombre', 'precio', 'Porcentaje de saldo', 'Códigos por compra', 'cantidad', 'Caducidad']) expect(m).toContain(piece);
  });

  it('rechaza un pool mayor al techo y una mezcla vacía', () => {
    const c = base();
    c.mix.roulette!.noPrizeCount = 500;
    expect(validateDemo(c, limits).join('|')).toContain('pool total');
    c.mix = {};
    expect(validateDemo(c, limits).join('|')).toContain('al menos un juego');
  });

  it('un cupón admite monto 0 y el dinero no', () => {
    const c = base();
    c.mix.roulette!.prizes = [{ type: 'coupon', amount: 0, count: 1 }];
    expect(validateDemo(c, limits)).toEqual([]);
    c.mix.roulette!.prizes = [{ type: 'money', amount: 0, count: 1 }];
    expect(validateDemo(c, limits).join('|')).toContain('monto');
  });

  it('la caducidad puede ser nula (sin vencimiento)', () => {
    const c = base();
    c.ttlHours = null;
    expect(validateDemo(c, limits)).toEqual([]);
  });

  it('canonical ignora el orden de las claves pero no el de las listas', () => {
    expect(canonical({ b: 1, a: { d: [1, 2], c: null } })).toBe(canonical({ a: { c: null, d: [1, 2] }, b: 1 }));
    expect(canonical({ a: [1, 2] })).not.toBe(canonical({ a: [2, 1] }));
    expect(canonical({ a: 1 })).not.toBe(canonical({ a: '1' }));
  });

  it('valida los parámetros de las cuentas contra los techos del servidor', () => {
    const c = base();
    c.accounts = { retentionDays: 0, codeTtlMinutes: 4, maxAccounts: 5001 };
    const m = validateDemo(c, limits).join('|');
    expect(m).toContain('Retención de cuentas');
    expect(m).toContain('Vigencia del código');
    expect(m).toContain('Tope de cuentas');
    c.accounts = { retentionDays: 365, codeTtlMinutes: 30, maxAccounts: 5000 };
    expect(validateDemo(c, limits)).toEqual([]);
    c.accounts = { retentionDays: 1.5, codeTtlMinutes: 5, maxAccounts: 1 };
    expect(validateDemo(c, limits).join('|')).toContain('Retención de cuentas');
  });

  it('describe el modo de envío del correo y avisa cuando no sale ninguno', () => {
    expect(mailMode({ mailer: 'smtp', host: 'mailpit', port: 1025, from: 'a@b.co' })).toEqual({ label: 'SMTP (mailpit:1025)', sends: true });
    expect(mailMode({ mailer: 'smtp', host: null, port: null, from: null })).toEqual({ label: 'SMTP', sends: true });
    expect(mailMode({ mailer: 'log', host: null, port: null, from: null }).sends).toBe(false);
    expect(mailMode({ mailer: 'array', host: null, port: null, from: null }).sends).toBe(false);
  });
});
