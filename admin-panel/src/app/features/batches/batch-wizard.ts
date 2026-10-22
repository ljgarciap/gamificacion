import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';
import { MatButtonModule } from '@angular/material/button';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { MatSelectModule } from '@angular/material/select';
import { Api, ApiError } from '../../core/api';
import { Campaign, CampaignRow } from '../../core/models';

interface Row { prizeId: string; quantity: number }

/** Giros que concede un premio de giro extra (meta.extra_spins; sin el campo vale 1). */
export function spinsOf(p: { type: string; meta: unknown }): number {
  if (p.type !== 'extraSpin') return 0;
  const v = (p.meta as Record<string, unknown> | null)?.['extra_spins'];
  return typeof v === 'number' ? v : 1;
}

/**
 * Asistente de 3 pasos para crear un borrador (ux-spec §7): campaña y nombre → pool y reserva → revisión. La cuenta de la reserva es la misma
 * regla que impone el servidor al comprometer (Σ cantidad × giros de los premios de giro extra): aquí solo se adelanta el error.
 */
@Component({
  selector: 'app-batch-wizard',
  imports: [FormsModule, RouterLink, MatButtonModule, MatFormFieldModule, MatInputModule, MatSelectModule],
  styles: `
    .tab { max-width: 720px; display: flex; flex-direction: column; gap: 4px; }
    .row { display: flex; gap: 12px; align-items: flex-start; flex-wrap: wrap; }
    .row mat-form-field { width: 220px; }
    .totals { background: var(--gp-surface); padding: 12px 16px; border-radius: 12px; display: flex; gap: 24px; flex-wrap: wrap; margin: 8px 0; }
    .fieldError { color: var(--gp-error); font-size: 13px; margin: 4px 0; }
    .hint { color: var(--gp-muted); font-size: 13px; margin: 4px 0; }
    .actions { display: flex; gap: 8px; margin-top: 12px; flex-wrap: wrap; }
    table { border-collapse: collapse; background: var(--gp-surface); }
    th, td { text-align: left; padding: 6px 12px; border-bottom: 1px solid var(--gp-background); }
  `,
  template: `
    <p><a routerLink="/lotes">← Lotes</a></p>
    <h1>Nuevo lote</h1>
    <p class="gp-muted" data-testid="step-label">Paso {{ step() }} de 3 · {{ titles[step() - 1] }}</p>
    @if (fail()) { <div class="gp-banner error" role="alert" data-testid="fail">{{ fail() }}</div> }

    @switch (step()) {
      @case (1) {
        <form class="tab" (ngSubmit)="toPool()" aria-label="Campaña y nombre">
          <mat-form-field>
            <mat-label>Campaña</mat-label>
            <mat-select name="campaign" [(ngModel)]="campaignId" required data-testid="campaign">@for (c of campaigns(); track c.id) { <mat-option [value]="c.id">{{ c.name }}</mat-option> }</mat-select>
          </mat-form-field>
          <mat-form-field><mat-label>Nombre del lote</mat-label><input matInput name="name" [(ngModel)]="name" maxlength="120" required /></mat-form-field>
          <mat-form-field>
            <mat-label>Vigencia (hora de Bogotá)</mat-label>
            <input matInput name="until" type="datetime-local" [(ngModel)]="until" />
            <mat-hint>Opcional: después de esta fecha los códigos dejan de canjearse</mat-hint>
          </mat-form-field>
          <div class="actions"><button mat-flat-button type="submit" [disabled]="!campaignId || !name.trim()">Siguiente</button></div>
        </form>
      }
      @case (2) {
        <div class="tab">
          @for (r of rows(); track $index; let i = $index) {
            <div class="row">
              <mat-form-field>
                <mat-label>Premio {{ i + 1 }}</mat-label>
                <mat-select [ngModel]="r.prizeId" (ngModelChange)="setPrize(i, $event)" [name]="'p' + i">@for (p of campaign()!.prizes; track p.prizeId) { <mat-option [value]="p.prizeId">{{ p.label }} ({{ p.prizeId }})</mat-option> }</mat-select>
              </mat-form-field>
              <mat-form-field><mat-label>Cantidad {{ i + 1 }}</mat-label><input matInput type="number" min="1" step="1" [ngModel]="r.quantity" (ngModelChange)="setQty(i, $event)" [name]="'q' + i" /></mat-form-field>
              <button mat-button type="button" (click)="removeRow(i)" [disabled]="rows().length === 1">Quitar<span class="gp-visually-hidden"> el premio {{ i + 1 }}</span></button>
            </div>
          }
          <div><button mat-stroked-button type="button" (click)="addRow()">Agregar premio al pool</button></div>

          @if (hasExtra()) {
            <mat-form-field>
              <mat-label>Reserva (R)</mat-label>
              <input matInput type="number" min="0" step="1" name="reserve" [ngModel]="reserve()" (ngModelChange)="reserve.set(+$event)" data-testid="reserve" />
              <mat-hint>Casillas del mazo para los giros extra. Mínimo {{ minReserve() }}</mat-hint>
            </mat-form-field>
            @if (reserve() < minReserve()) { <p class="fieldError" role="alert" data-testid="reserve-error">La reserva no cubre el peor caso: mínimo {{ minReserve() }}.</p> }
            @if (reserve() > minReserve()) { <p class="hint" data-testid="reserve-extra">{{ reserve() - minReserve() }} casillas de reserva quizá nunca se entreguen.</p> }
          }

          <div class="totals" role="status" aria-live="polite" data-testid="totals">
            <span>Códigos <strong data-testid="n-codes">{{ codes() }}</strong></span>
            <span>Casillas de reserva <strong data-testid="n-reserve">{{ reserveUsed() }}</strong></span>
            <span>Posiciones <strong data-testid="n-total">{{ total() }}</strong></span>
          </div>
          @if (poolError()) { <p class="fieldError" role="alert" data-testid="pool-error">{{ poolError() }}</p> }
          <div class="actions">
            <button mat-button type="button" (click)="step.set(1)">Atrás</button>
            <button mat-flat-button type="button" (click)="toReview()" [disabled]="!!poolError()">Siguiente</button>
          </div>
        </div>
      }
      @case (3) {
        <div class="tab">
          <p><strong>{{ name }}</strong> · {{ campaign()!.name }}@if (until) { · vigente hasta {{ until.replace('T', ' ') }} (Bogotá) }</p>
          <table data-testid="review">
            <caption class="gp-visually-hidden">Resumen del pool</caption>
            <thead><tr><th scope="col">Premio</th><th scope="col">Cantidad</th></tr></thead>
            <tbody>@for (r of rows(); track $index) { <tr><td>{{ label(r.prizeId) }}</td><td>{{ r.quantity }}</td></tr> }</tbody>
          </table>
          <div class="totals"><span>Códigos <strong>{{ codes() }}</strong></span><span>Reserva <strong>{{ reserveUsed() }}</strong></span><span>Posiciones <strong>{{ total() }}</strong></span></div>
          <div class="gp-banner warning" role="note">Después de comprometer, el pool, el mazo y el número de códigos no se podrán cambiar.</div>
          <div class="actions">
            <button mat-button type="button" (click)="step.set(2)">Atrás</button>
            <button mat-flat-button type="button" (click)="create()" [disabled]="busy()" data-testid="create-batch">Crear borrador</button>
          </div>
        </div>
      }
    }
  `,
})
export class BatchWizard implements OnInit {
  private readonly api = inject(Api);
  private readonly router = inject(Router);
  protected readonly titles = ['Campaña y nombre', 'Pool y reserva', 'Revisión'];
  protected readonly step = signal(1);
  protected readonly campaigns = signal<CampaignRow[]>([]);
  protected readonly campaign = signal<Campaign | null>(null);
  protected readonly rows = signal<Row[]>([{ prizeId: '', quantity: 1 }]);
  protected readonly reserve = signal(0);
  protected readonly fail = signal<string | null>(null);
  protected readonly busy = signal(false);
  protected campaignId = '';
  protected name = '';
  protected until = '';

  private readonly prizes = computed(() => new Map((this.campaign()?.prizes ?? []).map((p) => [p.prizeId, p])));
  protected readonly minReserve = computed(() => this.rows().reduce((a, r) => a + r.quantity * spinsOf(this.prizes().get(r.prizeId) ?? { type: '', meta: null }), 0));
  protected readonly hasExtra = computed(() => this.rows().some((r) => spinsOf(this.prizes().get(r.prizeId) ?? { type: '', meta: null }) > 0));
  protected readonly reserveUsed = computed(() => (this.hasExtra() ? this.reserve() : 0));
  protected readonly total = computed(() => this.rows().reduce((a, r) => a + (Number.isFinite(r.quantity) ? r.quantity : 0), 0));
  protected readonly codes = computed(() => this.total() - this.reserveUsed());
  protected readonly poolError = computed((): string | null => {
    const ids = this.rows().map((r) => r.prizeId);
    if (ids.some((i) => !i)) return 'Elige un premio en cada fila.';
    if (new Set(ids).size !== ids.length) return 'Un premio no puede repetirse en el pool: suma sus cantidades en una sola fila.';
    if (this.rows().some((r) => !Number.isInteger(r.quantity) || r.quantity < 1)) return 'Cada cantidad debe ser un entero de 1 o más.';
    if (this.hasExtra() && this.reserve() < this.minReserve()) return `La reserva no cubre el peor caso: mínimo ${this.minReserve()}.`;
    if (!Number.isInteger(this.reserveUsed()) || this.reserveUsed() < 0) return 'La reserva debe ser un entero de 0 o más.';
    if (this.codes() < 1) return 'Tiene que quedar al menos 1 código además de la reserva.';
    return null;
  });

  ngOnInit(): void {
    void this.api.get<{ campaigns: CampaignRow[] }>('/campaigns').then((r) => this.campaigns.set(r.campaigns), (e) => this.fail.set(e instanceof ApiError ? e.message : 'No se pudieron cargar las campañas.'));
  }

  protected label = (id: string) => { const p = this.prizes().get(id); return p ? `${p.label} (${id})` : id; };
  protected setPrize(i: number, prizeId: string): void { this.rows.update((r) => r.map((x, k) => (k === i ? { ...x, prizeId } : x))); }
  protected setQty(i: number, q: number): void { this.rows.update((r) => r.map((x, k) => (k === i ? { ...x, quantity: Number(q) } : x))); }
  protected addRow(): void { this.rows.update((r) => [...r, { prizeId: '', quantity: 1 }]); }
  protected removeRow(i: number): void { this.rows.update((r) => r.filter((_, k) => k !== i)); }

  protected async toPool(): Promise<void> {
    this.fail.set(null);
    try {
      this.campaign.set(await this.api.get<Campaign>(`/campaigns/${this.campaignId}`));
      this.rows.set([{ prizeId: '', quantity: 1 }]);
      this.reserve.set(0);
      this.step.set(2);
    } catch (e) {
      this.fail.set(e instanceof ApiError ? e.message : 'No se pudo cargar la campaña.');
    }
  }

  protected toReview(): void {
    if (!this.poolError()) this.step.set(3);
  }

  protected async create(): Promise<void> {
    this.busy.set(true);
    this.fail.set(null);
    try {
      const body: Record<string, unknown> = {
        campaignId: this.campaignId, name: this.name.trim(), pool: this.rows().map((r) => ({ prizeId: r.prizeId, quantity: r.quantity })), reserve: this.reserveUsed(),
      };
      if (this.until) body['redeemableUntil'] = `${this.until}:00-05:00`;
      const r = await this.api.post<{ id: string }>('/batches', body);
      await this.router.navigate(['/lotes', r.id]);
    } catch (e) {
      this.fail.set(e instanceof ApiError ? e.message : 'No se pudo crear el lote.');
    } finally {
      this.busy.set(false);
    }
  }
}
