import { DemoConfig, DemoLimits, canonical, defaultPlans, mailMode, mixTotals, percent, planForAmount, planProbability, planTotals, poolSize, previewPurchase, relinkPlans, toPlansMode, toStepsMode, validateDemo } from './demo';

const limits: DemoLimits = { maxProducts: 5, maxAmount: 10_000_000, maxCodesPerPurchase: 5, maxPool: 500, maxTtlHours: 168, maxRetentionDays: 365, maxAccounts: 5000, minCodeTtlMinutes: 5, maxCodeTtlMinutes: 30, games: ['roulette', 'scratch'], prizeTypes: ['money', 'coupon', 'points'], modes: ['steps', 'plans'], welcomePlan: 'welcome', maxAmountPlans: 5, maxReactivationDays: 60, maxPlanName: 40 };
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

describe('muestra por planes (SPEC-007): cálculos del panel', () => {
  const plansDraft = (): DemoConfig => toPlansMode(base(), limits.maxProducts);

  it('los planes provisionales suman 400 códigos y sus probabilidades son las de la spec', () => {
    const c = plansDraft();
    expect(poolSize(c)).toBe(400);
    const p = Object.fromEntries(c.plans!.map((x) => [x.id, planProbability(x)]));
    expect(p['welcome']).toBe(1);
    expect(p['bronce']).toBeCloseTo(0.05, 12);
    expect(p['plata']).toBeCloseTo(0.15, 12);
    expect(p['oro']).toBeCloseTo(0.4, 12);
    expect(planTotals(c.plans![3])).toEqual({ winners: 40, none: 60, total: 100 });
  });

  it('un plan vacío tiene probabilidad cero y no divide por cero', () => {
    expect(planProbability({ id: 'x', kind: 'amount', name: 'X', maxMoneyPrize: 1, mix: {} })).toBe(0);
  });

  it('el porcentaje se muestra con un decimal como máximo', () => {
    expect(percent(1)).toBe('100 %');
    expect(percent(0.05)).toBe('5 %');
    expect(percent(0.155)).toContain('15,5');
  });

  it('el plan por monto respeta cada frontera', () => {
    const plans = defaultPlans();
    const at = (n: number) => planForAmount(plans, n)?.id ?? null;
    expect([0, 999, 1_000, 10_000, 10_001, 50_000, 50_001, 10_000_000].map(at)).toEqual([null, null, 'bronce', 'bronce', 'plata', 'plata', 'oro', 'oro']);
    expect(at(Number.NaN)).toBeNull();
  });

  it('al pasar al modo por planes trae planes, disparadores y los productos que no participan', () => {
    const c = plansDraft();
    expect(c.mode).toBe('plans');
    expect(c.triggers).toEqual({ reactivationDays: 7, turnsPerPurchase: 1, welcomeOnFirstLogin: true });
    expect(c.products.map((p) => [p.id, p.participates])).toEqual([['a', true], ['pasa-plata', false], ['saca-plata', false], ['recarga-de-saldo', false]]);
    expect(c.mix).toEqual({});
    expect(validateDemo(c, limits)).toEqual([]);
  });

  it('no agrega productos excluidos si el catálogo ya no tiene espacio ni repite los que ya están', () => {
    const c = base();
    c.products = Array.from({ length: 5 }, (_, i) => ({ id: `p${i}`, name: `P${i}`, price: 1_000 }));
    expect(toPlansMode(c, 5).products).toHaveLength(5);
    const d = base();
    d.products.push({ id: 'pasa-plata', name: 'Pasa Plata', price: 1_000, participates: true });
    const out = toPlansMode(d, 5).products;
    expect(out.filter((p) => p.id === 'pasa-plata')).toHaveLength(1);
    expect(out.find((p) => p.id === 'pasa-plata')!.participates).toBe(true);
  });

  it('volver al modo anterior quita planes, disparadores y «participa» y restaura una mezcla', () => {
    const back = toStepsMode(plansDraft());
    expect(back.mode).toBeUndefined();
    expect('plans' in back).toBe(false);
    expect('triggers' in back).toBe(false);
    expect(back.products.some((p) => 'participates' in p)).toBe(false);
    expect(Object.keys(back.mix)).toEqual(['roulette']);
    expect(validateDemo(back, limits)).toEqual([]);
  });

  it('volver al modo anterior conserva la mezcla clásica si la había', () => {
    const withMix = { ...plansDraft(), mix: base().mix };
    expect(toStepsMode(withMix).mix).toEqual(base().mix);
  });

  it('relinkPlans encadena el mínimo de cada plan con el tope del anterior y deja el último sin tope', () => {
    const plans = defaultPlans();
    plans[1].maxAmount = 20_000;
    plans[3].maxAmount = 99_999;
    relinkPlans(plans);
    expect(plans[2].minAmount).toBe(20_001);
    expect(plans[3].minAmount).toBe(50_001);
    expect(plans[3].maxAmount).toBeNull();
  });

  describe('validación del borrador', () => {
    it('rangos con hueco o solape', () => {
      const c = plansDraft();
      c.plans![2].minAmount = 10_005;
      expect(validateDemo(c, limits).join('|')).toContain('debe ser 10001');
      c.plans![2].minAmount = 10_000;
      expect(validateDemo(c, limits).join('|')).toContain('debe ser 10001');
    });

    it('el último plan por monto sin tope y los demás con tope', () => {
      const c = plansDraft();
      c.plans![3].maxAmount = 99_999;
      expect(validateDemo(c, limits).join('|')).toContain('no tiene tope');
      const d = plansDraft();
      d.plans![1].maxAmount = 500;
      expect(validateDemo(d, limits).join('|')).toContain('monto máximo');
    });

    it('un premio en dinero sobre el tope del plan, pero un cupón no', () => {
      const c = plansDraft();
      c.plans![1].mix.roulette!.prizes[0].amount = 10_001;
      expect(validateDemo(c, limits).join('|')).toContain('supera el máximo del plan');
      const d = plansDraft();
      d.plans![1].mix.roulette!.prizes[1] = { type: 'coupon', amount: 0, count: 4 };
      expect(validateDemo(d, limits)).toEqual([]);
    });

    it('falta el plan de bienvenida y disparadores fuera de rango', () => {
      const c = plansDraft();
      c.plans = c.plans!.filter((p) => p.id !== 'welcome');
      expect(validateDemo(c, limits).join('|')).toContain('falta el plan de bienvenida');
      const d = plansDraft();
      d.triggers = { reactivationDays: 0, turnsPerPurchase: 6, welcomeOnFirstLogin: true };
      const msg = validateDemo(d, limits).join('|');
      expect(msg).toContain('Días sin comprar');
      expect(msg).toContain('Turnos por compra');
    });

    it('el pool total de todos los planes respeta el techo', () => {
      const c = plansDraft();
      c.plans![1].mix.roulette!.noPrizeCount = 195;
      expect(validateDemo(c, limits)).toEqual([]);
      c.plans![1].mix.roulette!.noPrizeCount = 196;
      expect(validateDemo(c, limits).join('|')).toContain('el pool total no puede superar 500');
    });

    it('en el modo por planes no se piden la compra mínima ni el monto por código', () => {
      const c = plansDraft();
      c.credit.minPurchase = -1;
      c.credit.codeStepAmount = 0;
      expect(validateDemo(c, limits)).toEqual([]);
    });

    it('el nombre del plan no puede estar vacío ni pasar del límite', () => {
      const c = plansDraft();
      c.plans![2].name = '  ';
      expect(validateDemo(c, limits).join('|')).toContain('el nombre debe tener');
      c.plans![2].name = 'x'.repeat(41);
      expect(validateDemo(c, limits).join('|')).toContain('el nombre debe tener');
    });
  });
});
