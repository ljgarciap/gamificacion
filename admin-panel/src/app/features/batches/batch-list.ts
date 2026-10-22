import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { MatButtonModule } from '@angular/material/button';
import { Api, ApiError } from '../../core/api';
import { Auth } from '../../core/auth';
import { dateBogota, dateUtc, percent } from '../../core/format';
import { BatchRow, CampaignRow } from '../../core/models';
import { STATE_LABEL } from '../../shared/lifecycle';
import { ErrorState } from '../../shared/state';

/** Lista de lotes (ux-spec §7): filtros por campaña y estado. */
@Component({
  selector: 'app-batch-list',
  imports: [RouterLink, MatButtonModule, ErrorState],
  styles: `
    .head { display: flex; align-items: center; gap: 16px; flex-wrap: wrap; }
    .head h1 { margin-right: auto; }
    label { display: inline-flex; gap: 8px; align-items: center; }
    select { font: inherit; padding: 6px 8px; border-radius: 8px; border: 1px solid #9aa3b5; background: var(--gp-surface); color: inherit; max-width: 220px; }
    table { width: 100%; min-width: 900px; border-collapse: collapse; font-size: 13px; background: var(--gp-surface); margin-top: 12px; }
    th, td { text-align: left; padding: 8px; border-bottom: 1px solid var(--gp-background); vertical-align: top; }
    th { color: var(--gp-muted); font-weight: 600; }
    .table-wrap { overflow-x: auto; }
    .bar { height: 6px; border-radius: 3px; background: #dde2ea; overflow: hidden; min-width: 80px; margin-top: 4px; }
    .bar > span { display: block; height: 100%; background: var(--gp-primary); }
  `,
  template: `
    <div class="head">
      <h1>Lotes</h1>
      <label>Campaña
        <select [value]="campaign()" (change)="campaign.set($any($event.target).value); load()" aria-label="Filtrar por campaña">
          <option value="">Todas</option>
          @for (c of campaigns(); track c.id) { <option [value]="c.id">{{ c.name }}</option> }
        </select>
      </label>
      <label>Estado
        <select [value]="state()" (change)="state.set($any($event.target).value); load()" aria-label="Filtrar por estado">
          <option value="">Todos</option>
          @for (s of states; track s[0]) { <option [value]="s[0]">{{ s[1] }}</option> }
        </select>
      </label>
      @if (auth.can('batches.manage')) { <a mat-flat-button routerLink="/lotes/nuevo" data-testid="new-batch">Nuevo lote</a> }
    </div>

    @if (error()) {
      <app-error-state [message]="error()!" (retry)="load()" />
    } @else if (rows() === null) {
      <p class="gp-muted" role="status">Cargando…</p>
    } @else {
      <div class="table-wrap" tabindex="0" role="region" aria-label="Tabla desplazable">
        <table>
          <caption class="gp-visually-hidden">Lotes</caption>
          <thead><tr><th scope="col">Nombre</th><th scope="col">Campaña</th><th scope="col">Estado</th><th scope="col">Códigos</th><th scope="col">Canjeados</th><th scope="col">Reserva</th><th scope="col">Vigencia</th><th scope="col">Creado</th></tr></thead>
          <tbody>
            @for (b of rows(); track b.id) {
              <tr data-testid="batch-row">
                <td><a [routerLink]="['/lotes', b.id]">{{ b.name }}</a></td>
                <td>{{ campaignName(b.campaignId) }}</td>
                <td>{{ stateLabel[b.state] }}</td>
                <td>{{ b.generatedCodes }} / {{ b.totalCodes }}</td>
                <td>{{ b.redeemed }} / {{ b.totalCodes }}<div class="bar" aria-hidden="true"><span [style.width.%]="pct(b.redeemed, b.totalCodes)"></span></div></td>
                <td>{{ b.reserveSize || '—' }}</td>
                <td [title]="utc(b.redeemableUntil)">{{ b.redeemableUntil ? bogota(b.redeemableUntil) : 'Sin límite' }}</td>
                <td [title]="utc(b.createdAt)">{{ bogota(b.createdAt) }}</td>
              </tr>
            }
          </tbody>
        </table>
      </div>
      @if (rows()!.length === 0) {
        <p class="gp-muted" data-testid="empty">No hay lotes con esos filtros.@if (auth.can('batches.manage')) { Crea uno con «Nuevo lote». }</p>
      }
    }
  `,
})
export class BatchList implements OnInit {
  private readonly api = inject(Api);
  protected readonly auth = inject(Auth);
  protected readonly rows = signal<BatchRow[] | null>(null);
  protected readonly campaigns = signal<CampaignRow[]>([]);
  protected readonly error = signal<string | null>(null);
  protected readonly campaign = signal('');
  protected readonly state = signal('');
  protected readonly states = Object.entries(STATE_LABEL);
  protected readonly stateLabel = STATE_LABEL;
  protected readonly names = computed(() => new Map(this.campaigns().map((c) => [c.id, c.name])));
  protected readonly bogota = dateBogota;
  protected readonly utc = dateUtc;
  protected readonly pct = percent;

  ngOnInit(): void {
    void this.api.get<{ campaigns: CampaignRow[] }>('/campaigns').then((r) => this.campaigns.set(r.campaigns), () => undefined);
    void this.load();
  }

  protected campaignName = (id: string) => this.names().get(id) ?? '—';

  protected async load(): Promise<void> {
    this.error.set(null);
    try {
      this.rows.set((await this.api.get<{ batches: BatchRow[] }>('/batches', { campaignId: this.campaign(), state: this.state() })).batches);
    } catch (e) {
      this.error.set(e instanceof ApiError ? e.message : 'No se pudieron cargar los lotes.');
    }
  }
}
