/** Formatos de ux-spec-003-panel §13: fechas en hora de Bogotá (la zona se ve; el UTC, al pasar el cursor). */
const BOGOTA = new Intl.DateTimeFormat('es-CO', { timeZone: 'America/Bogota', dateStyle: 'short', timeStyle: 'medium' });
const COP = new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 });

export function dateBogota(iso: string | null | undefined): string {
  if (!iso) return '—';
  const d = new Date(iso);
  return Number.isNaN(d.getTime()) ? iso : `${BOGOTA.format(d)} (Bogotá)`;
}

export function dateUtc(iso: string | null | undefined): string {
  if (!iso) return '';
  const d = new Date(iso);
  return Number.isNaN(d.getTime()) ? '' : d.toISOString().replace('T', ' ').replace(/\.\d+Z$/, ' UTC');
}

export const cop = (n: number): string => COP.format(n).replace(/\s/g, ' ');

export const percent = (part: number, total: number): number => (total > 0 ? Math.round((part / total) * 100) : 0);
