import { createHmac } from 'node:crypto';
import { existsSync, readFileSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';

const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
const PERIOD = 30;

function base32Decode(s: string): Buffer {
  let bits = '';
  for (const c of s.replace(/=+$/, '')) bits += ALPHABET.indexOf(c).toString(2).padStart(5, '0');
  const bytes: number[] = [];
  for (let i = 0; i + 8 <= bits.length; i += 8) bytes.push(parseInt(bits.slice(i, i + 8), 2));
  return Buffer.from(bytes);
}

/** RFC 6238 (SHA-1, 6 dígitos, 30 s). */
export function codeAt(secretBase32: string, step: number): string {
  const counter = Buffer.alloc(8);
  counter.writeBigUInt64BE(BigInt(step));
  const h = createHmac('sha1', base32Decode(secretBase32)).update(counter).digest();
  const o = h[19] & 0x0f;
  const n = ((h[o] & 0x7f) << 24) | (h[o + 1] << 16) | (h[o + 2] << 8) | h[o + 3];
  return String(n % 1_000_000).padStart(6, '0');
}

const STATE = join(process.cwd(), 'e2e', '.totp-state.json');

/**
 * Un código que el servidor acepte: cada paso sirve UNA vez por cuenta, así que se recuerda el último usado (en disco, porque el estado vive
 * entre pruebas). Si el paso actual ya se usó se toma el siguiente (el servidor admite ±1); si tampoco sirve, se espera al reloj.
 */
export async function freshCode(secretBase32: string): Promise<string> {
  const state: Record<string, number> = existsSync(STATE) ? JSON.parse(readFileSync(STATE, 'utf8')) : {};
  for (;;) {
    const now = Math.floor(Date.now() / 1000 / PERIOD);
    const last = state[secretBase32] ?? 0;
    const step = Math.max(now, last + 1);
    if (step <= now + 1) {
      state[secretBase32] = step;
      writeFileSync(STATE, JSON.stringify(state));
      return codeAt(secretBase32, step);
    }
    await new Promise((r) => setTimeout(r, 1000));
  }
}

/** Después de regenerar las cuentas el servidor olvida los pasos usados. */
export function resetTotpState(): void {
  writeFileSync(STATE, '{}');
}
