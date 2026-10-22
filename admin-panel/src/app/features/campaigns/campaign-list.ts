import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { MatButtonModule } from '@angular/material/button';
import { Api, ApiError } from '../../core/api';
import { Auth } from '../../core/auth';
import { dateBogota, dateUtc } from '../../core/format';
import { CampaignRow, GameType } from '../../core/models';
import { ErrorState } from '../../shared/state';

export const GAME_LABEL: Record<GameType, string> = { roulette: 'Ruleta', scratch: 'Raspa y Gana', memory: 'Concéntrese', dice: 'Dados', bomb: 'Bomba 8' };
const BEHAVIOR: Record<string, string> = { showPrevious: 'Mostrar el resultado anterior', rejectOnly: 'Solo rechazar' };

/** Lista de campañas (ux-spec §6). */
@Component({
  selector: 'app-campaign-list',
  imports: [RouterLink, MatButtonModule, ErrorState],
  styles: `
    .head { display: flex; align-items: center; gap: 16px; flex-wrap: wrap; }
    .head h1 { margin-right: auto; }
    label { display: inline-flex; gap: 8px; align-items: center; }
    select { font: inherit; padding: 6px 8px; border-radius: 8px; border: 1px solid #9aa3b5; background: var(--gp-surface); color: inherit; }
    table { width: 100%; border-collapse: collapse; font-size: 13px; background: var(--gp-surface); margin-top: 12px; }
    th, td { text-align: left; padding: 8px; border-bottom: 1px solid var(--gp-background); }
    th { color: var(--gp-muted); font-weight: 600; }
    .table-wrap { overflow-x: auto; }
    .frozen { color: var(--gp-warning); background: var(--gp-warning-surface); padding: 2px 8px; border-radius: 8px; white-space: nowrap; font-weight: 600; }
  `,
  template: `
    <div class="head">
      <h1>Campañas</h1>
      <label>Juego
        <select [value]="game()" (change)="game.set($any($event.target).value)" aria-label="Filtrar por juego">
          <option value="">Todos</option>
          @for (g of games; track g[0]) { <option [value]="g[0]">{{ g[1] }}</option> }
        </select>
      </label>
      @if (auth.can('campaigns.manage')) { <a mat-flat-button routerLink="/campanas/nueva" data-testid="new-campaign">Nueva campaña</a> }
    </div>

    @if (error()) {
      <app-error-state [message]="error()!" (retry)="load()" />
    } @else if (rows() === null) {
      <p class="gp-muted" role="status">Cargando…</p>
    } @else {
      <div class="table-wrap">
        <table>
          <caption class="gp-visually-hidden">Campañas</caption>
          <thead><tr><th scope="col">Nombre</th><th scope="col">Juego</th><th scope="col">Código ya usado</th><th scope="col">Premios</th><th scope="col">Lotes</th><th scope="col">Estado</th><th scope="col">Actualizada</th></tr></thead>
          <tbody>
            @for (c of shown(); track c.id) {
              <tr data-testid="campaign-row">
                <td><a [routerLink]="['/campanas', c.id]">{{ c.name }}</a></td>
                <td>{{ gameLabel[c.gameType] }}</td>
                <td>{{ behavior[c.usedCodeBehavior] }}</td>
                <td>{{ c.prizeCount }}</td>
                <td>{{ c.batchCount }}</td>
                <td>@if (c.frozen) { <span class="frozen"><span aria-hidden="true">🔒</span> Congelada</span> } @else { Editable }</td>
                <td [title]="utc(c.updatedAt)">{{ bogota(c.updatedAt) }}</td>
              </tr>
            }
          </tbody>
        </table>
      </div>
      @if (shown().length === 0) {
        <p class="gp-muted" data-testid="empty">
          @if (rows()!.length === 0) { Todavía no hay campañas. @if (auth.can('campaigns.manage')) { Crea la primera con «Nueva campaña». } } @else { Ninguna campaña usa ese juego. }
        </p>
      }
    }
  `,
})
export class CampaignList implements OnInit {
  private readonly api = inject(Api);
  protected readonly auth = inject(Auth);
  protected readonly rows = signal<CampaignRow[] | null>(null);
  protected readonly error = signal<string | null>(null);
  protected readonly game = signal('');
  protected readonly shown = computed(() => (this.rows() ?? []).filter((c) => !this.game() || c.gameType === this.game()));
  protected readonly games = Object.entries(GAME_LABEL);
  protected readonly gameLabel = GAME_LABEL;
  protected readonly behavior = BEHAVIOR;
  protected readonly bogota = dateBogota;
  protected readonly utc = dateUtc;

  ngOnInit(): void {
    void this.load();
  }

  protected async load(): Promise<void> {
    this.error.set(null);
    try {
      this.rows.set((await this.api.get<{ campaigns: CampaignRow[] }>('/campaigns')).campaigns);
    } catch (e) {
      this.error.set(e instanceof ApiError ? e.message : 'No se pudieron cargar las campañas.');
    }
  }
}
