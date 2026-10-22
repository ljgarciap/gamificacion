import { VerifySegment } from './models';

export interface ChainResult {
  checked: number;
  /** Primera entrada que no cuadra, o null si toda la cadena cuadra. */
  brokenAt: number | null;
  lastId: number;
  lastHash: string;
}

/**
 * Recorre la cadena de la bitácora desde el principio, tramo a tramo (docs/admin-api-v1.md): un tramo suelto NO prueba la bitácora entera,
 * solo el recorrido completo desde afterId=0 hasta `done`. Si un tramo no avanza (respuesta incoherente) se corta en vez de repetir sin fin.
 */
export async function verifyWholeChain(fetchSegment: (afterId: number) => Promise<VerifySegment>, onProgress?: (checked: number) => void): Promise<ChainResult> {
  let afterId = 0;
  let checked = 0;
  let lastHash = '';
  for (;;) {
    const seg = await fetchSegment(afterId);
    checked += seg.checked;
    lastHash = seg.lastHash;
    onProgress?.(checked);
    if (seg.brokenAt !== null) return { checked, brokenAt: seg.brokenAt, lastId: seg.lastId, lastHash };
    if (seg.done) return { checked, brokenAt: null, lastId: seg.lastId, lastHash };
    if (seg.lastId <= afterId) throw new Error('El servidor no avanzó en la verificación.');
    afterId = seg.lastId;
  }
}
