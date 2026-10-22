import { describe, expect, it } from 'vitest';
import { VerifySegment } from './models';
import { verifyWholeChain } from './verify';

const seg = (o: Partial<VerifySegment>): VerifySegment => ({ checked: 10, brokenAt: null, lastId: 10, lastHash: 'h', done: false, ...o });

describe('verifyWholeChain', () => {
  it('recorre desde 0 y suma los tramos hasta done', async () => {
    const asked: number[] = [];
    const r = await verifyWholeChain(async (after) => {
      asked.push(after);
      return after === 0 ? seg({ lastId: 10 }) : after === 10 ? seg({ lastId: 20 }) : seg({ checked: 5, lastId: 25, done: true, lastHash: 'fin' });
    });
    expect(asked).toEqual([0, 10, 20]);
    expect(r).toEqual({ checked: 25, brokenAt: null, lastId: 25, lastHash: 'fin' });
  });

  it('se detiene en la primera ruptura', async () => {
    let calls = 0;
    const r = await verifyWholeChain(async () => (++calls === 2 ? seg({ brokenAt: 14, lastId: 14 }) : seg({})));
    expect(calls).toBe(2);
    expect(r.brokenAt).toBe(14);
  });

  it('corta si un tramo no avanza', async () => {
    await expect(verifyWholeChain(async () => seg({ lastId: 0 }))).rejects.toThrow(/no avanz/);
  });
});
