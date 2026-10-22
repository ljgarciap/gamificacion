import { Component, input } from '@angular/core';
import { BatchState } from '../core/models';

export const STATE_LABEL: Record<BatchState, string> = { draft: 'Borrador', committed: 'Comprometido', active: 'Activo', closed: 'Cerrado', revealed: 'Revelado' };

/** Línea de pasos del ciclo de vida del lote (ux-spec §7): el actual resaltado, los siguientes atenuados. «Con códigos» es parte del borrador. */
@Component({
  selector: 'app-lifecycle',
  styles: `
    ol { display: flex; gap: 8px; list-style: none; padding: 0; margin: 12px 0; flex-wrap: wrap; }
    li { padding: 4px 12px; border-radius: 16px; background: var(--gp-background); color: var(--gp-muted); border: 1px solid #c9cfda; }
    li.done { color: var(--gp-navy); }
    li.current { background: var(--gp-primary); color: #fff; border-color: var(--gp-primary); font-weight: 700; }
  `,
  template: `
    <ol aria-label="Ciclo de vida del lote" data-testid="lifecycle">
      @for (s of steps(); track s.key) {
        <li [class.done]="s.done" [class.current]="s.current" [attr.aria-current]="s.current ? 'step' : null">{{ s.label }}@if (s.current) { <span class="gp-visually-hidden"> (estado actual)</span> }</li>
      }
    </ol>
  `,
})
export class Lifecycle {
  readonly state = input.required<BatchState>();
  readonly withCodes = input(false);

  protected steps() {
    const order: { key: string; label: string; at: BatchState; sub?: boolean }[] = [
      { key: 'draft', label: 'Borrador', at: 'draft' },
      { key: 'codes', label: 'Con códigos', at: 'draft', sub: true },
      { key: 'committed', label: 'Comprometido', at: 'committed' },
      { key: 'active', label: 'Activo', at: 'active' },
      { key: 'closed', label: 'Cerrado', at: 'closed' },
      { key: 'revealed', label: 'Revelado', at: 'revealed' },
    ];
    const rank: Record<BatchState, number> = { draft: 0, committed: 1, active: 2, closed: 3, revealed: 4 };
    const here = rank[this.state()];
    return order.map((o) => {
      const current = o.sub ? this.state() === 'draft' && this.withCodes() : o.key === 'draft' ? this.state() === 'draft' && !this.withCodes() : o.at === this.state();
      const done = !current && (o.sub ? here > 0 || this.withCodes() : rank[o.at] < here);
      return { key: o.key, label: o.label, current, done };
    });
  }
}
