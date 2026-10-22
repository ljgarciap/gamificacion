import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { MatCardModule } from '@angular/material/card';
import { Api, ApiError } from '../../core/api';
import { dateBogota, dateUtc, percent } from '../../core/format';
import { Dashboard as DashboardData } from '../../core/models';
import { ErrorState } from '../../shared/state';

const STATES: [keyof DashboardData['batches'], string][] = [
  ['draft', 'Borrador'],
  ['committed', 'Comprometido'],
  ['active', 'Activo'],
  ['closed', 'Cerrado'],
  ['revealed', 'Revelado'],
];

/** Resumen (ux-spec §5, RF-42): solo lectura. Nada aquí dice qué código tiene qué premio. */
@Component({
  selector: 'app-dashboard',
  imports: [MatCardModule, RouterLink, ErrorState],
  styles: `
    .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 16px; margin-bottom: 16px; }
    mat-card { padding: 4px; }
    .big { font-size: 28px; font-weight: 700; margin: 4px 0; }
    .row { display: flex; justify-content: space-between; gap: 8px; }
    .bar { height: 8px; border-radius: 4px; background: #dde2ea; overflow: hidden; margin-top: 8px; }
    .bar > span { display: block; height: 100%; background: var(--gp-primary); }
    .bar.low > span { background: var(--gp-warning); }
    .fail { color: var(--gp-error); font-weight: 600; }
    h2 { font-size: 16px; margin: 24px 0 8px; }
    .stock { display: grid; grid-template-columns: minmax(80px, 160px) 1fr 140px; gap: 8px 12px; align-items: center; }
    svg { width: 100%; max-width: 720px; height: auto; }
    svg rect { fill: var(--gp-primary); }
    svg text { fill: var(--gp-muted); font-size: 10px; }
  `,
  template: `
    <h1>Resumen</h1>
    @if (error()) {
      <app-error-state [message]="error()!" (retry)="load()" />
    } @else if (!d()) {
      <p class="gp-muted" role="status">Cargando…</p>
    } @else {
      @let x = d()!;
      <div class="grid">
        <mat-card><mat-card-content>
          <div class="gp-muted">Campañas</div><div class="big" data-testid="campaigns">{{ x.campaigns }}</div>
          <div class="gp-muted">Administradores activos: {{ x.admins }}</div>
        </mat-card-content></mat-card>

        <mat-card><mat-card-content>
          <div class="gp-muted">Lotes por estado</div>
          @for (s of states; track s[0]) {
            <div class="row"><span>{{ s[1] }}</span><strong>{{ x.batches[s[0]] }}</strong></div>
          }
        </mat-card-content></mat-card>

        <mat-card><mat-card-content>
          <div class="gp-muted">Códigos canjeados (lotes activos)</div>
          <div class="big" data-testid="codes">{{ x.activeCodes.redeemed }} / {{ x.activeCodes.total }}</div>
          <div class="bar" role="img" [attr.aria-label]="redeemedPct() + ' % de los códigos activos canjeados'"><span [style.width.%]="redeemedPct()"></span></div>
          <div class="gp-muted">{{ redeemedPct() }} %</div>
        </mat-card-content></mat-card>

        <mat-card><mat-card-content>
          <div class="gp-muted">Canjes</div>
          <div class="big">{{ x.redemptions.total }}</div>
          <div class="gp-muted">Últimas 24 h: {{ x.redemptions.last24h }}</div>
        </mat-card-content></mat-card>

        <mat-card><mat-card-content>
          <div class="gp-muted">Avisos al host</div>
          <div class="row"><span>Pendientes</span><strong>{{ x.outbox.pending }}</strong></div>
          <div class="row"><span>Enviados</span><strong>{{ x.outbox.sent }}</strong></div>
          <div class="row" [class.fail]="x.outbox.failed > 0">
            <span>Fallidos @if (x.outbox.failed > 0) { <span aria-hidden="true">⚠</span> }</span>
            <strong data-testid="outbox-failed">{{ x.outbox.failed }}</strong>
          </div>
          @if (x.outbox.oldestPendingAt) {
            <div class="gp-muted" [title]="utc(x.outbox.oldestPendingAt)">Pendiente más antiguo: {{ bogota(x.outbox.oldestPendingAt) }}</div>
          }
          <a routerLink="/avisos" [queryParams]="x.outbox.failed > 0 ? { estado: 'failed' } : {}">{{ x.outbox.failed > 0 ? 'Ver los fallidos' : 'Ver avisos' }}</a>
        </mat-card-content></mat-card>

        <mat-card><mat-card-content>
          <div class="gp-muted">Aprobaciones</div>
          <div class="row"><span>Pendientes</span><strong data-testid="approvals-pending">{{ x.approvals.pending }}</strong></div>
          <div class="row"><span>Esperando ejecución</span><strong>{{ x.approvals.awaitingExecution }}</strong></div>
          <a routerLink="/aprobaciones">Ver aprobaciones</a>
        </mat-card-content></mat-card>
      </div>

      <h2>Existencias de los lotes activos</h2>
      @if (x.stockByBatch.length === 0) {
        <p class="gp-muted" data-testid="no-stock">No hay lotes activos. Cuando se active uno, aquí verás cuánto queda de cada premio.</p>
      }
      @for (b of x.stockByBatch; track b.batchId) {
        <mat-card><mat-card-content>
          <strong>{{ b.name }}</strong>
          <div class="stock" role="list" [attr.aria-label]="'Existencias de ' + b.name">
            @for (p of b.prizes; track p.prizeId) {
              <span role="listitem">{{ p.prizeId }}</span>
              <div class="bar" [class.low]="p.remaining === 0" aria-hidden="true"><span [style.width.%]="pct(p.remaining, p.total)"></span></div>
              <span class="gp-muted">Quedan {{ p.remaining }} de {{ p.total }}</span>
            }
          </div>
        </mat-card-content></mat-card>
      }

      <h2>Canjes por día (últimos 14 días, hora de Bogotá)</h2>
      <svg viewBox="0 0 720 160" role="img" [attr.aria-label]="chartLabel()" data-testid="by-day">
        @for (r of x.redemptionsByDay; track r.day; let i = $index) {
          <rect [attr.x]="i * 50 + 8" [attr.y]="150 - barH(r.count)" width="34" [attr.height]="barH(r.count)" rx="3"><title>{{ r.day }}: {{ r.count }}</title></rect>
          <text [attr.x]="i * 50 + 25" y="158" text-anchor="middle">{{ r.day.slice(5) }}</text>
        }
      </svg>
      <table class="gp-visually-hidden">
        <caption>Canjes por día</caption>
        <thead><tr><th scope="col">Día</th><th scope="col">Canjes</th></tr></thead>
        <tbody>@for (r of x.redemptionsByDay; track r.day) { <tr><td>{{ r.day }}</td><td>{{ r.count }}</td></tr> }</tbody>
      </table>
    }
  `,
})
export class DashboardPage implements OnInit {
  private readonly api = inject(Api);
  protected readonly d = signal<DashboardData | null>(null);
  protected readonly error = signal<string | null>(null);
  protected readonly states = STATES;
  protected readonly redeemedPct = computed(() => percent(this.d()?.activeCodes.redeemed ?? 0, this.d()?.activeCodes.total ?? 0));
  protected readonly bogota = dateBogota;
  protected readonly utc = dateUtc;
  protected readonly pct = percent;
  private readonly max = computed(() => Math.max(1, ...(this.d()?.redemptionsByDay.map((r) => r.count) ?? [1])));
  protected readonly chartLabel = computed(() => `Canjes por día: ${this.d()?.redemptionsByDay.map((r) => `${r.day} ${r.count}`).join(', ')}`);

  ngOnInit(): void {
    void this.load();
  }

  protected barH(n: number): number {
    return n === 0 ? 1 : Math.max(4, Math.round((n / this.max()) * 130));
  }

  protected async load(): Promise<void> {
    this.error.set(null);
    try {
      this.d.set(await this.api.get<DashboardData>('/dashboard'));
    } catch (e) {
      this.error.set(e instanceof ApiError ? e.message : 'No se pudo cargar el resumen.');
    }
  }
}
