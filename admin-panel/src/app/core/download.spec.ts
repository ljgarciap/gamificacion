import { describe, expect, it } from 'vitest';
import { remainingText, sha256Hex } from './download';

describe('sha256Hex', () => {
  it('coincide con el vector conocido de «abc»', async () => {
    expect(await sha256Hex(new TextEncoder().encode('abc').buffer as ArrayBuffer)).toBe('ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad');
  });
});

describe('remainingText', () => {
  const now = Date.parse('2026-10-22T12:00:00Z');
  it('formatea horas, minutos y segundos', () => {
    expect(remainingText('2026-10-22T13:59:30Z', now)).toBe('1 h 59 min');
    expect(remainingText('2026-10-22T12:05:10Z', now)).toBe('5 min 10 s');
    expect(remainingText('2026-10-22T12:00:45Z', now)).toBe('45 s');
  });
  it('devuelve null si ya venció', () => {
    expect(remainingText('2026-10-22T11:59:59Z', now)).toBeNull();
  });
});
