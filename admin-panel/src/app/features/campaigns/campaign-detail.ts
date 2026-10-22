import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { MatButtonModule } from '@angular/material/button';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { MatSelectModule } from '@angular/material/select';
import { MatTabsModule } from '@angular/material/tabs';
import { Api, ApiError } from '../../core/api';
import { Auth } from '../../core/auth';
import { cop } from '../../core/format';
import { Campaign, CampaignPrize, GameType, PrizeType } from '../../core/models';
import { ErrorState } from '../../shared/state';
import { GAME_LABEL } from './campaign-list';

export const FROZEN_REASON = 'Tiene un lote comprometido: este campo ya no se puede cambiar.';
const PRIZE_TYPES: [PrizeType, string][] = [['money', 'Dinero'], ['coupon', 'Cupón'], ['points', 'Puntos'], ['extraSpin', 'Giro extra'], ['none', 'Sin premio']];
const THEME_COLORS: [string, string][] = [['primary', 'Principal'], ['navy', 'Azul oscuro'], ['accent', 'Acento'], ['info', 'Información'], ['background', 'Fondo'], ['surface', 'Superficie'], ['error', 'Error'], ['success', 'Éxito']];
const HEX = /^#[0-9a-fA-F]{6}$/;
const EXAMPLES: Record<GameType, unknown> = {
  roulette: { segments: ['p50k', 'none', 'p10k', 'none', 'p50k', 'none', 'p10k', 'none'] },
  scratch: { rows: 3, cols: 3 },
  memory: { pairs: 6, columns: 4, flipBackMs: 900 },
  dice: { rolls: { none: [[1, 2], [1, 3]], p10k: [[2, 2]], p50k: [[1, 1]] } },
};

interface PrizeDraft { prizeId: string; type: PrizeType; label: string; amount: number | null; spins: number; rest: Record<string, unknown> }

/** Detalle y edición de una campaña (ux-spec §6): pestañas General, Tema, Juego y Premios. Lo congelado se deshabilita con su motivo. */
@Component({
  selector: 'app-campaign-detail',
  imports: [FormsModule, RouterLink, MatButtonModule, MatFormFieldModule, MatInputModule, MatSelectModule, MatTabsModule, ErrorState],
  styles: `
    .head { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
    .frozen { color: var(--gp-warning); background: var(--gp-warning-surface); padding: 4px 10px; border-radius: 8px; font-weight: 600; }
    .tab { padding: 16px 0; max-width: 720px; display: flex; flex-direction: column; gap: 4px; }
    .tab.wide { max-width: none; }
    .hint { color: var(--gp-muted); font-size: 12px; margin: -4px 0 8px; }
    .fieldError { color: var(--gp-error); font-size: 12px; margin: 0 0 8px; }
    .theme { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 0 16px; }
    .color { display: flex; gap: 8px; align-items: flex-start; }
    .color input[type='color'] { width: 44px; height: 44px; margin-top: 4px; border: 0; padding: 0; background: none; }
    .swatches { display: flex; gap: 6px; margin: 8px 0; flex-wrap: wrap; }
    .swatches span { width: 32px; height: 32px; border-radius: 8px; border: 1px solid #c9cfda; }
    textarea.json { width: 100%; min-height: 220px; font-family: ui-monospace, monospace; font-size: 12px; padding: 8px; box-sizing: border-box; border-radius: 8px; border: 1px solid #9aa3b5; }
    pre { background: var(--gp-background); padding: 8px; border-radius: 8px; overflow-x: auto; }
    .actions { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; margin-top: 8px; }
    table { width: 100%; border-collapse: collapse; font-size: 13px; background: var(--gp-surface); }
    th, td { text-align: left; padding: 6px 8px; border-bottom: 1px solid var(--gp-background); vertical-align: top; }
    th { color: var(--gp-muted); font-weight: 600; }
    .table-wrap { overflow-x: auto; }
    .inline { display: flex; gap: 8px; flex-wrap: wrap; align-items: flex-start; }
    .inline mat-form-field { width: 210px; }
    .ok { color: var(--gp-success); font-weight: 600; }
  `,
  template: `
    <p><a routerLink="/campanas">← Campañas</a></p>
    @if (error()) {
      <app-error-state [message]="error()!" (retry)="load()" />
    } @else if (!isNew() && !c()) {
      <p class="gp-muted" role="status">Cargando…</p>
    } @else {
      <div class="head">
        <h1>{{ isNew() ? 'Nueva campaña' : name }}</h1>
        @if (c()?.frozen) { <span class="frozen" data-testid="frozen-badge"><span aria-hidden="true">🔒</span> Congelada · tiene un lote comprometido</span> }
        @if (readOnly()) { <span class="gp-muted">Solo lectura</span> }
      </div>
      <div role="status" aria-live="polite">
        @if (saved()) { <p class="ok" data-testid="saved">{{ saved() }}</p> }
        @if (fail()) { <div class="gp-banner error" role="alert" data-testid="fail">{{ fail() }}</div> }
      </div>

      <mat-tab-group animationDuration="0ms">
        <mat-tab label="General">
          <form class="tab" (ngSubmit)="saveGeneral()" aria-label="Datos generales">
            <mat-form-field><mat-label>Nombre</mat-label><input matInput name="name" [(ngModel)]="g.name" [disabled]="readOnly()" maxlength="120" required /></mat-form-field>
            <mat-form-field><mat-label>Título</mat-label><input matInput name="title" [(ngModel)]="g.title" [disabled]="readOnly()" maxlength="120" /></mat-form-field>
            <mat-form-field>
              <mat-label>Juego</mat-label>
              <mat-select name="gameType" [(ngModel)]="g.gameType" [disabled]="readOnly() || frozen()" data-testid="game-type">
                @for (x of games; track x[0]) { <mat-option [value]="x[0]">{{ x[1] }}</mat-option> }
              </mat-select>
            </mat-form-field>
            @if (frozen()) { <p class="hint" data-testid="reason-game">{{ reason }}</p> }
            <mat-form-field>
              <mat-label>Si el código ya se usó</mat-label>
              <mat-select name="behavior" [(ngModel)]="g.usedCodeBehavior" [disabled]="readOnly() || frozen()">
                <mat-option value="rejectOnly">Solo rechazar</mat-option>
                <mat-option value="showPrevious">Mostrar el resultado anterior</mat-option>
              </mat-select>
            </mat-form-field>
            @if (frozen()) { <p class="hint">{{ reason }}</p> }
            @if (!readOnly()) { <div class="actions"><button mat-flat-button type="submit" [disabled]="busy()">{{ isNew() ? 'Crear campaña' : 'Guardar cambios' }}</button></div> }
          </form>
        </mat-tab>

        <mat-tab label="Tema">
          <form class="tab" (ngSubmit)="saveTheme()" aria-label="Tema visual">
            <p class="hint">Deja un campo vacío para usar el color por defecto. Formato #RRGGBB.</p>
            <div class="theme">
              @for (k of colors; track k[0]) {
                <div class="color">
                  <mat-form-field>
                    <mat-label>{{ k[1] }}</mat-label>
                    <input matInput [name]="'c-' + k[0]" [(ngModel)]="theme[k[0]]" [disabled]="readOnly()" placeholder="#RRGGBB" maxlength="7" />
                    @if (themeError(k[0])) { <mat-error>{{ themeError(k[0]) }}</mat-error> }
                  </mat-form-field>
                  <input type="color" [value]="theme[k[0]] || '#000000'" [disabled]="readOnly()" (input)="theme[k[0]] = $any($event.target).value" [attr.aria-label]="'Selector de color: ' + k[1]" />
                </div>
                @if (themeError(k[0])) { <p class="fieldError" role="alert">{{ k[1] }}: {{ themeError(k[0]) }}</p> }
              }
            </div>
            <mat-form-field><mat-label>Tipografía</mat-label><input matInput name="font" [(ngModel)]="theme['fontFamily']" [disabled]="readOnly()" maxlength="80" /></mat-form-field>
            <mat-form-field><mat-label>Dirección del logo</mat-label><input matInput name="logo" [(ngModel)]="theme['logoUrl']" [disabled]="readOnly()" maxlength="300" /></mat-form-field>
            <div class="swatches" aria-hidden="true">@for (k of colors; track k[0]) { <span [style.background]="validHex(theme[k[0]]) ? theme[k[0]] : 'transparent'"></span> }</div>
            <button mat-button type="button" (click)="showJson.set(!showJson())" [attr.aria-expanded]="showJson()">{{ showJson() ? 'Ocultar JSON' : 'Ver JSON' }}</button>
            @if (showJson()) { <pre data-testid="theme-json">{{ themeJson() }}</pre> }
            @if (!readOnly()) { <div class="actions"><button mat-flat-button type="submit" [disabled]="busy()">Guardar tema</button></div> }
          </form>
        </mat-tab>

        <mat-tab label="Juego">
          <form class="tab" (ngSubmit)="saveGame()" aria-label="Configuración del juego">
            <p class="hint">Configuración de {{ gameLabel[g.gameType] }}. El servidor la valida a fondo al comprometer el lote.</p>
            <textarea class="json" name="gameJson" [(ngModel)]="gameJson" [disabled]="readOnly() || frozen()" aria-label="Configuración del juego en JSON" data-testid="game-json" spellcheck="false"></textarea>
            @if (frozen()) { <p class="hint" data-testid="reason-config">{{ reason }}</p> }
            @if (gameError()) { <p class="fieldError" role="alert" data-testid="game-error">{{ gameError() }}</p> }
            @if (!readOnly() && !frozen()) {
              <div class="actions">
                <button mat-stroked-button type="button" (click)="example()">Usar un ejemplo de {{ gameLabel[g.gameType] }}</button>
                <button mat-flat-button type="submit" [disabled]="busy()">Guardar configuración</button>
              </div>
            }
          </form>
        </mat-tab>

        @if (!isNew()) {
          <mat-tab label="Premios">
            <div class="tab wide">
              <div class="table-wrap">
                <table>
                  <caption class="gp-visually-hidden">Premios de la campaña</caption>
                  <thead><tr><th scope="col">Id</th><th scope="col">Tipo</th><th scope="col">Etiqueta</th><th scope="col">Monto</th><th scope="col">Giros</th>@if (!readOnly()) { <th scope="col">Acciones</th> }</tr></thead>
                  <tbody>
                    @for (p of c()!.prizes; track p.prizeId) {
                      <tr data-testid="prize-row">
                        <td><code>{{ p.prizeId }}</code></td>
                        <td>{{ typeLabel(p.type) }}</td>
                        <td>{{ p.label }}</td>
                        <td>{{ p.amount === null ? '—' : cop(p.amount) }}</td>
                        <td>{{ spinsOf(p) || '—' }}</td>
                        @if (!readOnly()) {
                          <td>
                            <button mat-button type="button" (click)="editPrize(p)">Editar<span class="gp-visually-hidden"> el premio {{ p.prizeId }}</span></button>
                            @if (confirmDelete() === p.prizeId) {
                              <button mat-flat-button type="button" (click)="removePrize(p)" [attr.data-testid]="'confirm-delete-' + p.prizeId">Sí, eliminar<span class="gp-visually-hidden"> {{ p.prizeId }}</span></button>
                              <button mat-button type="button" (click)="confirmDelete.set(null)">Cancelar</button>
                            } @else {
                              <button mat-button type="button" (click)="confirmDelete.set(p.prizeId)" [attr.data-testid]="'delete-' + p.prizeId">Eliminar<span class="gp-visually-hidden"> el premio {{ p.prizeId }}</span></button>
                            }
                            @if (rowError()[p.prizeId]) { <p class="fieldError" role="alert" [attr.data-testid]="'row-error-' + p.prizeId">{{ rowError()[p.prizeId] }}</p> }
                          </td>
                        }
                      </tr>
                    }
                  </tbody>
                </table>
              </div>
              @if (c()!.prizes.length === 0) { <p class="gp-muted" data-testid="no-prizes">Esta campaña aún no tiene premios.</p> }

              @if (!readOnly()) {
                <form (ngSubmit)="savePrize()" [attr.aria-label]="editing() ? 'Editar premio ' + editing() : 'Agregar premio'" class="tab wide">
                  <h2>{{ editing() ? 'Editar el premio ' + editing() : 'Agregar premio' }}</h2>
                  <div class="inline">
                    <mat-form-field><mat-label>Id del premio</mat-label><input matInput name="prizeId" [(ngModel)]="d.prizeId" [disabled]="!!editing()" maxlength="64" required /></mat-form-field>
                    <mat-form-field>
                      <mat-label>Tipo</mat-label>
                      <mat-select name="ptype" [(ngModel)]="d.type">@for (t of types; track t[0]) { <mat-option [value]="t[0]">{{ t[1] }}</mat-option> }</mat-select>
                    </mat-form-field>
                    <mat-form-field><mat-label>Etiqueta</mat-label><input matInput name="plabel" [(ngModel)]="d.label" maxlength="120" required /></mat-form-field>
                    @if (d.type === 'money' || d.type === 'points') {
                      <mat-form-field><mat-label>Monto (COP, entero)</mat-label><input matInput name="pamount" type="number" min="0" step="1" [(ngModel)]="d.amount" /></mat-form-field>
                    }
                    @if (d.type === 'extraSpin') {
                      <mat-form-field><mat-label>Giros por premio</mat-label><input matInput name="pspins" type="number" min="1" max="10" step="1" [(ngModel)]="d.spins" /><mat-hint>1 a 10; necesita reserva en el lote</mat-hint></mat-form-field>
                    }
                  </div>
                  @if (prizeError()) { <p class="fieldError" role="alert" data-testid="prize-error">{{ prizeError() }}</p> }
                  <div class="actions">
                    <button mat-flat-button type="submit" [disabled]="busy()">{{ editing() ? 'Guardar premio' : 'Agregar premio' }}</button>
                    @if (editing()) { <button mat-button type="button" (click)="cancelEdit()">Cancelar</button> }
                  </div>
                </form>
              }
            </div>
          </mat-tab>
        }
      </mat-tab-group>
    }
  `,
})
export class CampaignDetail implements OnInit {
  private readonly api = inject(Api);
  private readonly auth = inject(Auth);
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  protected readonly c = signal<Campaign | null>(null);
  protected readonly error = signal<string | null>(null);
  protected readonly saved = signal<string | null>(null);
  protected readonly fail = signal<string | null>(null);
  protected readonly busy = signal(false);
  protected readonly showJson = signal(false);
  protected readonly gameError = signal<string | null>(null);
  protected readonly prizeError = signal<string | null>(null);
  protected readonly rowError = signal<Record<string, string>>({});
  protected readonly editing = signal<string | null>(null);
  protected readonly confirmDelete = signal<string | null>(null);
  protected readonly id = this.route.snapshot.paramMap.get('id');
  protected readonly isNew = () => this.id === null;
  protected readonly frozen = computed(() => this.c()?.frozen === true);
  protected readonly readOnly = computed(() => !this.auth.can('campaigns.manage'));
  protected readonly games = Object.entries(GAME_LABEL);
  protected readonly gameLabel = GAME_LABEL;
  protected readonly types = PRIZE_TYPES;
  protected readonly colors = THEME_COLORS;
  protected readonly reason = FROZEN_REASON;
  protected readonly cop = cop;
  protected name = '';
  protected g: { name: string; title: string; gameType: GameType; usedCodeBehavior: 'showPrevious' | 'rejectOnly' } = { name: '', title: '', gameType: 'roulette', usedCodeBehavior: 'rejectOnly' };
  protected theme: Record<string, string> = {};
  protected gameJson = '{}';
  protected d: PrizeDraft = this.blank();
  protected themeJson(): string { return JSON.stringify(this.cleanTheme(), null, 2); }

  ngOnInit(): void {
    if (this.isNew()) this.gameJson = JSON.stringify(EXAMPLES.roulette, null, 2);
    else void this.load();
  }

  protected typeLabel = (t: string) => PRIZE_TYPES.find((x) => x[0] === t)?.[1] ?? t;
  protected validHex = (v: string | undefined) => !!v && HEX.test(v);
  protected themeError(k: string): string | null { const v = this.theme[k]; return v && !HEX.test(v) ? 'usa el formato #RRGGBB' : null; }
  protected spinsOf(p: CampaignPrize): number { return p.type === 'extraSpin' ? Number((p.meta as Record<string, unknown> | null)?.['extra_spins'] ?? 1) : 0; }
  private blank(): PrizeDraft { return { prizeId: '', type: 'money', label: '', amount: null, spins: 1, rest: {} }; }

  protected async load(): Promise<void> {
    this.error.set(null);
    try {
      const c = await this.api.get<Campaign>(`/campaigns/${this.id}`);
      this.c.set(c);
      this.name = c.name;
      this.g = { name: c.name, title: c.title ?? '', gameType: c.gameType, usedCodeBehavior: c.usedCodeBehavior };
      this.theme = { ...c.theme };
      this.gameJson = JSON.stringify(c.gameConfig, null, 2);
    } catch (e) {
      this.error.set(e instanceof ApiError ? e.message : 'No se pudo cargar la campaña.');
    }
  }

  private cleanTheme(): Record<string, string> {
    return Object.fromEntries(Object.entries(this.theme).filter(([, v]) => typeof v === 'string' && v.trim() !== '').map(([k, v]) => [k, v.trim()]));
  }

  private async run(ok: string, action: () => Promise<void>, onError?: (e: ApiError) => void): Promise<void> {
    this.busy.set(true);
    this.saved.set(null);
    this.fail.set(null);
    try {
      await action();
      this.saved.set(ok);
    } catch (e) {
      const err = e instanceof ApiError ? e : new ApiError(0, 'network', 'Error inesperado.');
      if (onError) onError(err); else this.fail.set(err.message);
    } finally {
      this.busy.set(false);
    }
  }

  protected saveGeneral(): Promise<void> {
    const body = { name: this.g.name, title: this.g.title.trim() === '' ? null : this.g.title, gameType: this.g.gameType, usedCodeBehavior: this.g.usedCodeBehavior };
    if (this.isNew()) {
      return this.run('Campaña creada.', async () => {
        const game = this.parseGame();
        if (game === null) throw new ApiError(0, 'client', 'Corrige la configuración del juego antes de crear la campaña.');
        const theme = this.cleanTheme();
        if (this.colors.some((k) => this.themeError(k[0]))) throw new ApiError(0, 'client', 'Corrige los colores del tema antes de crear la campaña.');
        const r = await this.api.post<{ id: string }>('/campaigns', { ...body, gameConfig: game, theme });
        await this.router.navigate(['/campanas', r.id]);
      });
    }
    // Con la campaña congelada no se mandan los campos que la base ya no deja cambiar.
    const send: Record<string, unknown> = this.frozen() ? { name: body.name, title: body.title } : body;
    return this.run('Cambios guardados.', async () => { await this.api.patch(`/campaigns/${this.id}`, send); await this.load(); });
  }

  protected saveTheme(): Promise<void> {
    if (this.colors.some((k) => this.themeError(k[0]))) { this.fail.set('Corrige los colores marcados.'); return Promise.resolve(); }
    if (this.isNew()) { this.saved.set('El tema se guardará al crear la campaña.'); return Promise.resolve(); }
    return this.run('Tema guardado.', async () => { await this.api.patch(`/campaigns/${this.id}`, { theme: this.cleanTheme() }); await this.load(); });
  }

  private parseGame(): Record<string, unknown> | null {
    this.gameError.set(null);
    try {
      const v = JSON.parse(this.gameJson);
      if (v === null || typeof v !== 'object' || Array.isArray(v)) throw new Error();
      return v as Record<string, unknown>;
    } catch {
      this.gameError.set('Debe ser un objeto JSON válido.');
      return null;
    }
  }

  protected example(): void {
    this.gameJson = JSON.stringify(EXAMPLES[this.g.gameType], null, 2);
    this.gameError.set(null);
  }

  protected saveGame(): Promise<void> {
    const v = this.parseGame();
    if (v === null) return Promise.resolve();
    if (this.isNew()) { this.saved.set('La configuración se guardará al crear la campaña.'); return Promise.resolve(); }
    return this.run('Configuración guardada.', async () => { await this.api.patch(`/campaigns/${this.id}`, { gameConfig: v }); await this.load(); }, (e) => this.gameError.set(e.message));
  }

  protected editPrize(p: CampaignPrize): void {
    const { extra_spins, ...rest } = (p.meta && !Array.isArray(p.meta) ? p.meta : {}) as Record<string, unknown>;
    this.d = { prizeId: p.prizeId, type: p.type, label: p.label, amount: p.amount, spins: Number(extra_spins ?? 1), rest };
    this.editing.set(p.prizeId);
    this.prizeError.set(null);
  }

  protected cancelEdit(): void {
    this.editing.set(null);
    this.d = this.blank();
    this.prizeError.set(null);
  }

  protected savePrize(): Promise<void> {
    this.prizeError.set(null);
    const meta: Record<string, unknown> = { ...this.d.rest, ...(this.d.type === 'extraSpin' ? { extra_spins: Number(this.d.spins) } : {}) };
    const hasAmount = this.d.type === 'money' || this.d.type === 'points';
    if (hasAmount && (this.d.amount === null || `${this.d.amount}` === '' || !Number.isInteger(Number(this.d.amount)) || Number(this.d.amount) < 0)) {
      this.prizeError.set('Indica el monto en pesos: un número entero, 0 o más.');
      return Promise.resolve();
    }
    const body = { type: this.d.type, label: this.d.label, amount: hasAmount && this.d.amount !== null && `${this.d.amount}` !== '' ? Number(this.d.amount) : null, meta };
    const editing = this.editing();
    return this.run(editing ? 'Premio guardado.' : 'Premio agregado.', async () => {
      if (editing) await this.api.patch(`/campaigns/${this.id}/prizes/${encodeURIComponent(editing)}`, body);
      else await this.api.post(`/campaigns/${this.id}/prizes`, { prizeId: this.d.prizeId, ...body });
      this.cancelEdit();
      await this.load();
    }, (e) => this.prizeError.set(e.message));
  }

  protected removePrize(p: CampaignPrize): Promise<void> {
    this.rowError.update((r) => ({ ...r, [p.prizeId]: '' }));
    return this.run('Premio eliminado.', async () => {
      await this.api.delete(`/campaigns/${this.id}/prizes/${encodeURIComponent(p.prizeId)}`);
      this.confirmDelete.set(null);
      await this.load();
    }, (e) => { this.confirmDelete.set(null); this.rowError.update((r) => ({ ...r, [p.prizeId]: e.message })); });
  }
}
