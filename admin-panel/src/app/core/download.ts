/** SHA-256 en hexadecimal, calculado en el navegador (el archivo no sale de aquí). */
export async function sha256Hex(data: ArrayBuffer): Promise<string> {
  const digest = await crypto.subtle.digest('SHA-256', data);
  return [...new Uint8Array(digest)].map((b) => b.toString(16).padStart(2, '0')).join('');
}

/** Tiempo restante legible («1 h 59 min», «45 s») hasta una fecha; null si ya pasó. */
export function remainingText(iso: string, now = Date.now()): string | null {
  const ms = new Date(iso).getTime() - now;
  if (!Number.isFinite(ms) || ms <= 0) return null;
  const s = Math.floor(ms / 1000);
  if (s >= 3600) return `${Math.floor(s / 3600)} h ${Math.floor((s % 3600) / 60)} min`;
  if (s >= 60) return `${Math.floor(s / 60)} min ${s % 60} s`;
  return `${s} s`;
}
