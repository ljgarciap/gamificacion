import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { MatButtonModule } from '@angular/material/button';
import { Api, ApiError } from '../../core/api';
import { dateBogota, dateUtc } from '../../core/format';
import { Setting, SettingChange } from '../../core/models';
import { ErrorState } from '../../shared/state';

const GROUPS: Record<string, string> = {
  token: 'Token del host', redeem: 'Canje de códigos', host: 'Llamadas al host', outbox: 'Avisos al host', admin: 'Panel y control dual',
};

/**
 * Parámetros (RF-46…RF-48, solo superadmin). Los de seguridad NO se editan aquí: piden una solicitud de cambio que otra persona aprueba
 * (control dual, UA-6). El rango lo valida el cliente para ayudar y el servidor para imponer.
 */
@Component({
  selector: 'app-settings',
  imports: [FormsModule, RouterLink, MatButtonModule, ErrorState],
  styles: `
    h2 { font-size: 16px; margin: 24px 0 8px; }
    table { width: 100%; border-collapse: collapse; font-size: 13px; background: var(--gp-surface); }
    th, td { text-align: left; padding: 6px 8px; border-bottom: 1px solid var(--gp-background); vertical-align: top; }
    th { color: var(--gp-muted); font-weight: 600; }
    .table-wrap { overflow-x: auto; }
    input.num { width: 110px; font: inherit; padding: 6px 8px; border-radius: 8px; border: 1px solid #9aa3b5; background: var(--gp-surface); color: inherit; }
    .badge { padding: 2px 8px; border-radius: 8px; font-weight: 600; white-space: nowrap; background: var(--gp-background); }
    .badge.sec { background: var(--gp-warning-surface); color: var(--gp-warning); }
    .fieldError { color: var(--gp-error); font-size: 12px; margin: 4px 0 0; }
    .ok { color: var(--gp-success); font-weight: 600; }
    ol.history { margin: 4px 0 0; padding-left: 18px; }
  `,
  template: `
    <h1>Parámetros</h1>
    <p class="gp-muted">Los cambios aplican en menos de 1 minuto. Los parámetros de seguridad requieren la aprobación de otra persona.</p>
    <div role="status" aria-live="polite">
      @if (message()) { <p class="ok" data-testid="message">{{ message() }} @if (requested()) { <a routerLink="/aprobaciones">Ver aprobaciones</a> }</p> }
      @if (fail()) { <div class="gp-banner error" role="alert" data-testid="fail">{{ fail() }}</div> }
    </div>

    @if (error()) {
      <app-error-state [message]="error()!" (retry)="load()" />
    } @else if (settings() === null) {
      <p class="gp-muted" role="status">Cargando…</p>
    } @else {
      @for (g of grouped(); track g[0]) {
        <h2>{{ g[0] }}</h2>
        <div class="table-wrap">
          <table>
            <caption class="gp-visually-hidden">{{ g[0] }}</caption>
            <thead><tr><th scope="col">Parámetro</th><th scope="col">Valor</th><th scope="col">Rango</th><th scope="col">Por defecto</th><th scope="col">Estado</th><th scope="col">Acción</th></tr></thead>
            <tbody>
              @for (s of g[1]; track s.key) {
                <tr data-testid="setting-row" [attr.data-key]="s.key">
                  <td>{{ s.label }}<br /><code class="gp-muted">{{ s.key }}</code></td>
                  <td>
                    <input class="num" type="number" step="1" [min]="s.min" [max]="s.max" [attr.aria-label]="(s.security ? 'Nuevo valor propuesto de ' : 'Valor de ') + s.label" [ngModel]="draft()[s.key] ?? s.value" (ngModelChange)="setDraft(s.key, $event)" [name]="s.key" />
                    <span class="gp-muted"> {{ s.unit }}</span>
                    @if (rangeError(s)) { <p class="fieldError" role="alert">{{ rangeError(s) }}</p> }
                  </td>
                  <td>{{ s.min }}–{{ s.max }}</td>
                  <td>{{ s.default }}</td>
                  <td>
                    @if (s.security) { <span class="badge sec"><span aria-hidden="true">🛡</span> Seguridad</span> }
                    @if (s.overridden) { <span class="badge">Modificado</span> }
                  </td>
                  <td>
                    @if (s.security) {
                      <button mat-stroked-button type="button" (click)="request(s)" [disabled]="!changed(s) || !!rangeError(s) || busy()" [attr.data-testid]="'request-' + s.key">Solicitar cambio (requiere aprobación)</button>
                    } @else {
                      <button mat-flat-button type="button" (click)="save(s)" [disabled]="!changed(s) || !!rangeError(s) || busy()" [attr.data-testid]="'save-' + s.key">Guardar</button>
                    }
                    <button mat-button type="button" (click)="toggleHistory(s)" [attr.aria-expanded]="openHistory() === s.key">Historial<span class="gp-visually-hidden"> de {{ s.label }}</span></button>
                    @if (openHistory() === s.key) {
                      @if (history().length === 0) { <p class="gp-muted" data-testid="no-history">Sin cambios registrados.</p> }
                      <ol class="history" data-testid="history">
                        @for (h of history(); track h.id) { <li [title]="utc(h.changedAt)">{{ h.old ?? 'por defecto' }} → {{ h.new }} · {{ h.actor }} · {{ bogota(h.changedAt) }}</li> }
                      </ol>
                    }
                  </td>
                </tr>
              }
            </tbody>
          </table>
        </div>
      }
    }
  `,
})
export class SettingsScreen implements OnInit {
  private readonly api = inject(Api);
  protected readonly settings = signal<Setting[] | null>(null);
  protected readonly draft = signal<Record<string, number>>({});
  protected readonly error = signal<string | null>(null);
  protected readonly message = signal<string | null>(null);
  protected readonly requested = signal(false);
  protected readonly fail = signal<string | null>(null);
  protected readonly busy = signal(false);
  protected readonly openHistory = signal<string | null>(null);
  protected readonly history = signal<SettingChange[]>([]);
  protected readonly bogota = dateBogota;
  protected readonly utc = dateUtc;
  protected readonly grouped = computed(() => {
    const out = new Map<string, Setting[]>();
    for (const s of this.settings() ?? []) {
      const g = GROUPS[s.key.split('.')[0]] ?? 'Otros';
      out.set(g, [...(out.get(g) ?? []), s]);
    }
    return [...out.entries()];
  });

  ngOnInit(): void {
    void this.load();
  }

  protected setDraft(key: string, v: number): void {
    this.draft.update((d) => ({ ...d, [key]: v }));
  }

  private value(s: Setting): number {
    return this.draft()[s.key] ?? s.value;
  }

  protected changed(s: Setting): boolean {
    return this.draft()[s.key] !== undefined && this.draft()[s.key] !== s.value;
  }

  protected rangeError(s: Setting): string | null {
    const v = this.value(s);
    if (typeof v !== 'number' || !Number.isInteger(v)) return 'Debe ser un número entero.';
    return v < s.min || v > s.max ? `Debe estar entre ${s.min} y ${s.max}.` : null;
  }

  protected async load(): Promise<void> {
    this.error.set(null);
    try {
      this.settings.set((await this.api.get<{ settings: Setting[] }>('/settings')).settings);
      this.draft.set({});
    } catch (e) {
      this.error.set(e instanceof ApiError ? e.message : 'No se pudieron cargar los parámetros.');
    }
  }

  protected async toggleHistory(s: Setting): Promise<void> {
    if (this.openHistory() === s.key) { this.openHistory.set(null); return; }
    this.history.set([]);
    this.openHistory.set(s.key);
    try {
      this.history.set((await this.api.get<{ history: SettingChange[] }>(`/settings/${s.key}/history`)).history);
    } catch (e) {
      this.fail.set(e instanceof ApiError ? e.message : 'No se pudo cargar el historial.');
    }
  }

  private async act(ok: string, requested: boolean, run: () => Promise<unknown>): Promise<void> {
    this.busy.set(true);
    this.message.set(null);
    this.fail.set(null);
    try {
      await run();
      this.message.set(ok);
      this.requested.set(requested);
    } catch (e) {
      this.fail.set(e instanceof ApiError ? e.message : 'No se pudo completar la acción.');
    } finally {
      this.busy.set(false);
    }
  }

  protected async save(s: Setting): Promise<void> {
    await this.act(`«${s.label}» guardado. Aplica en menos de 1 minuto.`, false, () => this.api.put(`/settings/${s.key}`, { value: this.value(s) }));
    await this.load();
  }

  protected async request(s: Setting): Promise<void> {
    await this.act(`Solicitud enviada. Pídele a otra persona que la apruebe: ${s.label} pasará a ${this.value(s)}.`, true, () =>
      this.api.post('/approvals', { action: 'setting.change', params: { key: s.key, value: this.value(s) } }),
    );
  }
}
