import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { MatButtonModule } from '@angular/material/button';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { MatTabsModule } from '@angular/material/tabs';
import { Api, ApiError } from '../../core/api';
import { Auth } from '../../core/auth';
import { dateBogota, dateUtc, percent } from '../../core/format';
import { BatchDetail as Batch, BatchRedemption, Campaign, RedemptionPage } from '../../core/models';
import { Lifecycle, STATE_LABEL } from '../../shared/lifecycle';
import { ErrorState } from '../../shared/state';

type Action = 'commit' | 'close' | 'reveal' | 'generate' | 'activate' | 'export';

const PER_PAGE = 50;

/**
 * Detalle del lote (ux-spec §7). Cada acción irreversible dice qué cambia y qué ya no se podrá cambiar, y las más graves piden escribir el
 * nombre del lote. Generar códigos, activar y exportar son de control dual: aquí solo se SOLICITA y se lleva a Aprobaciones.
 */
@Component({
  selector: 'app-batch-detail',
  imports: [FormsModule, RouterLink, MatButtonModule, MatFormFieldModule, MatInputModule, MatTabsModule, ErrorState, Lifecycle],
  styles: `
    .head { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
    .tab { padding: 16px 0; display: flex; flex-direction: column; gap: 8px; }
    .card { background: var(--gp-surface); border-radius: 12px; padding: 16px; margin: 12px 0; max-width: 760px; }
    .confirm { background: var(--gp-danger-surface); border-radius: 12px; padding: 12px 16px; margin: 12px 0; max-width: 760px; }
    .confirm ul { margin: 4px 0 8px; padding-left: 20px; }
    .actions { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; margin-top: 8px; }
    code { word-break: break-all; font-family: ui-monospace, monospace; font-size: 12px; }
    dl { display: grid; grid-template-columns: 180px 1fr; gap: 6px 12px; margin: 0; }
    dt { color: var(--gp-muted); }
    dd { margin: 0; }
    table { border-collapse: collapse; font-size: 13px; background: var(--gp-surface); width: 100%; }
    th, td { text-align: left; padding: 6px 8px; border-bottom: 1px solid var(--gp-background); }
    th { color: var(--gp-muted); font-weight: 600; }
    .table-wrap { overflow-x: auto; }
    .ok { color: var(--gp-success); font-weight: 600; }
    .bar { height: 8px; border-radius: 4px; background: #dde2ea; overflow: hidden; max-width: 320px; }
    .bar > span { display: block; height: 100%; background: var(--gp-primary); }
  `,
  template: `
    <p><a routerLink="/lotes">← Lotes</a></p>
    @if (error()) {
      <app-error-state [message]="error()!" (retry)="load()" />
    } @else if (!b()) {
      <p class="gp-muted" role="status">Cargando…</p>
    } @else {
      @let x = b()!;
      <div class="head">
        <h1>{{ x.name }}</h1>
        <span class="gp-muted">{{ campaign()?.name }}</span>
      </div>
      <app-lifecycle [state]="x.state" [withCodes]="withCodes()" />

      <div role="status" aria-live="polite">
        @if (message()) { <p class="ok" data-testid="message">{{ message() }}</p> }
        @if (fail()) { <div class="gp-banner error" role="alert" data-testid="fail">{{ fail() }} @if (alreadyPending()) { <a routerLink="/aprobaciones">Ver aprobaciones</a> }</div> }
      </div>

      @if (primary(); as p) {
        @if (!confirming()) {
          <div class="actions"><button mat-flat-button type="button" (click)="ask(p.action)" data-testid="primary-action">{{ p.label }}</button></div>
        }
      } @else if (x.state === 'active' || x.state === 'closed') {
        <p class="gp-muted">{{ auth.can('batches.manage') ? '' : 'Solo lectura: tu rol no puede cambiar el estado del lote.' }}</p>
      }

      @if (confirming(); as a) {
        <div class="confirm" role="alertdialog" aria-label="Confirmar acción" data-testid="confirm">
          <p><strong>{{ copy[a].title }}</strong></p>
          <ul>@for (l of lines(a); track l) { <li>{{ l }}</li> }</ul>
          @if (a === 'generate') {
            <mat-form-field><mat-label>Prefijo de los códigos</mat-label><input matInput name="prefix" [(ngModel)]="prefix" maxlength="12" placeholder="PROMO" /><mat-hint>Letras y números</mat-hint></mat-form-field>
          }
          @if (typed(a)) {
            <mat-form-field><mat-label>Escribe el nombre del lote para confirmar</mat-label><input matInput name="typed" [(ngModel)]="typedName" autocomplete="off" data-testid="typed-name" /></mat-form-field>
          }
          <div class="actions">
            <button mat-flat-button type="button" (click)="run(a)" [disabled]="busy() || (typed(a) && typedName !== x.name) || (a === 'generate' && !prefix.trim())" data-testid="confirm-yes">{{ copy[a].yes }}</button>
            <button mat-button type="button" (click)="cancel()">Cancelar</button>
          </div>
        </div>
      }

      @if (receipt()) {
        <div class="card" data-testid="receipt">
          <h2>Recibo del compromiso</h2>
          <dl>
            <dt>Compromiso</dt><dd><code>{{ receipt()!.commitment }}</code></dd>
            <dt>Ronda de la baliza</dt><dd>{{ receipt()!.beacon_round }}</dd>
          </dl>
          <button mat-stroked-button type="button" (click)="copyReceipt()">{{ copied() ? 'Copiado' : 'Copiar recibo' }}</button>
        </div>
      }

      <mat-tab-group animationDuration="0ms" (selectedTabChange)="tabChanged($event.tab.textLabel)">
        <mat-tab label="Resumen">
          <div class="tab">
            <dl>
              <dt>Códigos</dt><dd>{{ x.generatedCodes }} generados de {{ x.totalCodes }}</dd>
              <dt>Canjeados</dt><dd data-testid="redeemed">{{ x.redeemed }} de {{ x.totalCodes }}<div class="bar" aria-hidden="true"><span [style.width.%]="pct(x.redeemed, x.totalCodes)"></span></div></dd>
              <dt>Vigencia</dt><dd [title]="utc(x.redeemableUntil)">{{ x.redeemableUntil ? bogota(x.redeemableUntil) : 'Sin límite' }}</dd>
              <dt>Creado por</dt><dd>{{ x.createdBy }} · {{ bogota(x.createdAt) }}</dd>
            </dl>
            <h2>Premios del pool</h2>
            <div class="table-wrap" tabindex="0" role="region" aria-label="Tabla desplazable">
              <table data-testid="stock">
                <caption class="gp-visually-hidden">Existencias por premio</caption>
                <thead><tr><th scope="col">Premio</th><th scope="col">En el pool</th><th scope="col">Canjeados</th>@if (!x.reserveSize) { <th scope="col">Restantes</th> }</tr></thead>
                <tbody>
                  @for (e of x.pool; track e.prize_id) {
                    <tr><td>{{ prizeLabel(e.prize_id) }}</td><td>{{ e.quantity }}</td><td>{{ redeemedOf(e.prize_id) }}</td>@if (!x.reserveSize) { <td>{{ e.quantity - redeemedOf(e.prize_id) }}</td> }</tr>
                  }
                </tbody>
              </table>
            </div>
            @if (x.reserveSize) { <p class="gp-muted">Con reserva, el restante exacto por premio no se muestra: los giros extra consumen casillas del mismo mazo.</p> }
          </div>
        </mat-tab>

        <mat-tab label="Compromiso">
          <div class="tab">
            @if (x.commitment) {
              <dl data-testid="commitment">
                <dt>Compromiso</dt><dd><code>{{ x.commitment }}</code></dd>
                <dt>Hash del pool</dt><dd><code>{{ x.poolHash }}</code></dd>
                <dt>Resumen de códigos</dt><dd><code>{{ x.codesDigest }}</code></dd>
                <dt>Cadena de la baliza</dt><dd><code>{{ x.chainHash }}</code></dd>
                <dt>Ronda de la baliza</dt><dd>{{ x.beaconRound }}</dd>
                <dt>Valor de la baliza</dt><dd><code>{{ x.beaconValue }}</code></dd>
                <dt>Semilla</dt><dd>@if (x.seed) { <code data-testid="seed">{{ x.seed }}</code> } @else { Se publica al revelar el lote. }</dd>
              </dl>
              <p class="gp-muted">Con estos datos cualquier persona puede verificar el sorteo. Ver la guía de verificación de terceros (<code>docs/guia-verificacion-terceros.md</code>). Nunca se muestra qué código tiene qué premio.</p>
            } @else {
              <p class="gp-muted" data-testid="no-commitment">El lote todavía no se ha comprometido.</p>
            }
          </div>
        </mat-tab>

        <mat-tab label="Canjes">
          <div class="tab">
            @if (reds() === null) { <p class="gp-muted" role="status">Cargando…</p> } @else {
              <div class="table-wrap" tabindex="0" role="region" aria-label="Tabla desplazable">
                <table>
                  <caption class="gp-visually-hidden">Canjes del lote, del más reciente al más antiguo</caption>
                  <thead><tr><th scope="col">Fecha</th><th scope="col">Usuario</th><th scope="col">Premio</th><th scope="col">Posición</th></tr></thead>
                  <tbody>
                    @for (r of reds()!; track r.id) {
                      <tr data-testid="redemption-row"><td [title]="utc(r.redeemedAt)">{{ bogota(r.redeemedAt) }}</td><td><code>{{ r.userId }}</code></td><td>{{ r.prizeId }}</td><td>{{ r.index !== null ? r.index : 'Giro extra · casilla ' + r.reserveIndex }}</td></tr>
                    }
                  </tbody>
                </table>
              </div>
              @if (reds()!.length === 0) { <p class="gp-muted" data-testid="no-redemptions">Todavía no hay canjes.</p> }
              <div class="actions">
                <button mat-stroked-button type="button" (click)="go(page() - 1)" [disabled]="page() <= 1">Anterior</button>
                <span>Página {{ page() }} de {{ pages() }} · {{ total() }} canjes</span>
                <button mat-stroked-button type="button" (click)="go(page() + 1)" [disabled]="page() >= pages()">Siguiente</button>
              </div>
            }
          </div>
        </mat-tab>

        @if (x.reserveSize) {
          <mat-tab label="Reserva">
            <div class="tab">
              <dl data-testid="reserve">
                <dt>Casillas de reserva</dt><dd>{{ x.reserveSize }}</dd>
                <dt>Consumidas</dt><dd>{{ x.reserveConsumed ?? 0 }}</dd>
                <dt>Sin consumir</dt><dd>{{ x.reserveSize - (x.reserveConsumed ?? 0) }}</dd>
                <dt>Giros jugados</dt><dd>{{ x.spinsPlayed ?? 0 }}</dd>
                <dt>Giros pendientes</dt><dd data-testid="pending-spins">{{ x.pendingSpins ?? 0 }}</dd>
              </dl>
              <div class="gp-banner warning" role="note">Los giros pendientes caducan al cerrar el lote.</div>
            </div>
          </mat-tab>
        }
      </mat-tab-group>
    }
  `,
})
export class BatchDetail implements OnInit {
  private readonly api = inject(Api);
  protected readonly auth = inject(Auth);
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  private readonly id = this.route.snapshot.paramMap.get('id')!;
  protected readonly b = signal<Batch | null>(null);
  protected readonly campaign = signal<Campaign | null>(null);
  protected readonly error = signal<string | null>(null);
  protected readonly message = signal<string | null>(null);
  protected readonly fail = signal<string | null>(null);
  protected readonly alreadyPending = signal(false);
  protected readonly busy = signal(false);
  protected readonly confirming = signal<Action | null>(null);
  protected readonly receipt = signal<{ commitment: string; beacon_round: number } | null>(null);
  protected readonly copied = signal(false);
  protected readonly reds = signal<BatchRedemption[] | null>(null);
  protected readonly page = signal(1);
  protected readonly total = signal(0);
  protected readonly pages = computed(() => Math.max(1, Math.ceil(this.total() / PER_PAGE)));
  protected readonly withCodes = computed(() => !!this.b() && this.b()!.generatedCodes >= this.b()!.totalCodes && this.b()!.totalCodes > 0);
  protected typedName = '';
  protected prefix = '';
  protected readonly bogota = dateBogota;
  protected readonly utc = dateUtc;
  protected readonly pct = percent;

  protected readonly copy: Record<Action, { title: string; yes: string }> = {
    generate: { title: 'Solicitar la generación de códigos', yes: 'Solicitar generación' },
    commit: { title: 'Comprometer el lote', yes: 'Sí, comprometer' },
    activate: { title: 'Solicitar la activación', yes: 'Solicitar activación' },
    close: { title: 'Cerrar el lote', yes: 'Sí, cerrar el lote' },
    reveal: { title: 'Revelar el lote', yes: 'Sí, revelar' },
    export: { title: 'Solicitar la exportación de auditoría', yes: 'Solicitar exportación' },
  };

  /** La acción disponible según el estado y los permisos: lo que el rol no puede hacer no se dibuja. */
  protected readonly primary = computed((): { action: Action; label: string } | null => {
    const b = this.b();
    if (!b) return null;
    const manage = this.auth.can('batches.manage');
    if (b.state === 'draft' && manage) return this.withCodes() ? { action: 'commit', label: 'Comprometer' } : { action: 'generate', label: 'Generar códigos' };
    if (b.state === 'committed' && manage) return { action: 'activate', label: 'Solicitar activación' };
    if (b.state === 'active' && manage) return { action: 'close', label: 'Cerrar el lote' };
    if (b.state === 'closed' && manage) return { action: 'reveal', label: 'Revelar el lote' };
    if (b.state === 'revealed' && this.auth.can('audit.export')) return { action: 'export', label: 'Exportar auditoría' };
    return null;
  });

  ngOnInit(): void {
    void this.load();
  }

  protected typed = (a: Action) => a === 'close' || a === 'reveal';
  protected prizeLabel = (id: string) => { const p = this.campaign()?.prizes.find((x) => x.prizeId === id); return p ? `${p.label} (${id})` : id; };
  protected redeemedOf = (id: string) => this.b()?.redeemedByPrize.find((x) => x.prizeId === id)?.count ?? 0;

  protected lines(a: Action): string[] {
    const b = this.b()!;
    const n = b.totalCodes;
    switch (a) {
      case 'generate': return [`Se creará un archivo con los ${n} códigos en claro.`, 'Se entrega una sola vez y se destruye tras la descarga o al vencer su plazo.', 'Es la única copia: si se pierde, hay que recrear el lote.', 'Otra persona debe aprobar la solicitud con un código nuevo.'];
      case 'commit': return ['Se fija el mazo con una baliza pública (drand).', 'Ya no se podrán cambiar el pool, la reserva, los premios usados ni la configuración del juego.', 'Si la baliza no está lista, no se pierde nada: se reintenta.'];
      case 'activate': return ['Una segunda persona debe aprobarla con un código nuevo.', 'Al activar, los códigos empiezan a valer.'];
      case 'close': return ['Se detienen los canjes.', `Los giros pendientes caducan: ${b.pendingSpins ?? 0} pendientes ahora mismo.`, 'No se puede deshacer.'];
      case 'reveal': return ['Se publica la semilla: cualquiera podrá verificar el sorteo.', 'No se puede deshacer.'];
      case 'export': return ['Se prepara el archivo de auditoría del verificador independiente.', 'Se entrega una sola vez, con su SHA-256 y el ancla para publicar fuera de la base.', 'Otra persona debe aprobar la solicitud con un código nuevo.'];
    }
  }

  protected ask(a: Action): void {
    this.message.set(null);
    this.fail.set(null);
    this.typedName = '';
    this.confirming.set(a);
  }

  protected cancel(): void {
    this.confirming.set(null);
  }

  protected async load(): Promise<void> {
    this.error.set(null);
    try {
      const b = await this.api.get<Batch>(`/batches/${this.id}`);
      this.b.set(b);
      if (!this.campaign() || this.campaign()!.id !== b.campaignId) void this.api.get<Campaign>(`/campaigns/${b.campaignId}`).then((c) => this.campaign.set(c), () => undefined);
    } catch (e) {
      this.error.set(e instanceof ApiError ? e.message : 'No se pudo cargar el lote.');
    }
  }

  protected tabChanged(label: string): void {
    if (label === 'Canjes' && this.reds() === null) void this.loadReds();
  }

  protected go(n: number): void {
    this.page.set(n);
    void this.loadReds();
  }

  private async loadReds(): Promise<void> {
    try {
      const r = await this.api.get<RedemptionPage>(`/batches/${this.id}/redemptions`, { page: this.page(), perPage: PER_PAGE });
      this.reds.set(r.items);
      this.total.set(r.total);
    } catch (e) {
      this.reds.set([]);
      this.fail.set(e instanceof ApiError ? e.message : 'No se pudieron cargar los canjes.');
    }
  }

  protected async copyReceipt(): Promise<void> {
    const r = this.receipt();
    if (!r) return;
    try {
      await navigator.clipboard.writeText(`compromiso=${r.commitment} ronda=${r.beacon_round}`);
      this.copied.set(true);
    } catch {
      this.copied.set(false);
    }
  }

  protected async run(a: Action): Promise<void> {
    this.busy.set(true);
    this.message.set(null);
    this.fail.set(null);
    this.alreadyPending.set(false);
    try {
      const id = this.id;
      if (a === 'commit') {
        const r = await this.api.post<{ commitment: string; beacon_round: number }>(`/batches/${id}/commit`);
        this.receipt.set({ commitment: r.commitment, beacon_round: r.beacon_round });
        this.message.set('Lote comprometido.');
      } else if (a === 'close') {
        await this.api.post(`/batches/${id}/close`);
        this.message.set('Lote cerrado: los canjes se detuvieron y los giros pendientes caducaron.');
      } else if (a === 'reveal') {
        await this.api.post(`/batches/${id}/reveal`);
        this.message.set('Lote revelado: la semilla ya es pública.');
      } else {
        const action = a === 'generate' ? 'codes.generate' : a === 'activate' ? 'batch.activate' : 'audit.export';
        await this.api.post('/approvals', { action, params: a === 'generate' ? { batchId: id, prefix: this.prefix.trim() } : { batchId: id } });
        await this.router.navigate(['/aprobaciones'], { queryParams: { solicitada: action } });
        return;
      }
      this.confirming.set(null);
      await this.load();
    } catch (e) {
      const err = e instanceof ApiError ? e : null;
      this.fail.set(err?.code === 'beaconUnavailable' ? 'La baliza no responde. No se perdió nada: reintenta en unos segundos.' : (err?.message ?? 'No se pudo completar la acción.'));
      this.alreadyPending.set(err?.code === 'alreadyPending');
      this.confirming.set(null);
    } finally {
      this.busy.set(false);
    }
  }
}
