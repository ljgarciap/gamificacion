import { Component, DestroyRef, OnInit, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { Api, ApiError } from '../../core/api';
import { DemoConfig, DemoMixEntry, DemoRun, DemoState, PRIZE_TYPE_LABEL, PrizeType, canonical, mixTotals, poolSize, previewPurchase, validateDemo } from '../../core/demo';
import { dateBogota } from '../../core/format';
import { GameType } from '../../core/models';
import { ErrorState } from '../../shared/state';
import { GAME_LABEL } from '../campaigns/campaign-list';

type Confirm = 'enable' | 'disable' | 'reset' | null;

const POLL_MS = 2000;

/**
 * Muestra real (SPEC-006, solo superadmin): escenario, reglas de carga, mezcla de premios, estado e interruptor. El servidor valida todo
 * y manda; aquí solo se ayuda (totales en vivo, mensajes). Encender, apagar y reiniciar piden confirmación en la propia página.
 */
@Component({
  selector: 'app-demo',
  imports: [FormsModule, MatButtonModule, ErrorState],
  styles: `
    h2 { font-size: 16px; margin: 0 0 8px; }
    section { background: var(--gp-surface); border-radius: 12px; padding: 16px; margin: 16px 0; }
    .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 12px; }
    label.f { display: flex; flex-direction: column; gap: 4px; font-size: 12px; color: var(--gp-muted); }
    input, select { font: inherit; padding: 6px 8px; border-radius: 8px; border: 1px solid #9aa3b5; background: var(--gp-surface); color: var(--gp-navy); }
    table { width: 100%; border-collapse: collapse; font-size: 13px; }
    th, td { text-align: left; padding: 6px 8px; border-bottom: 1px solid var(--gp-background); vertical-align: middle; }
    th { color: var(--gp-muted); font-weight: 600; }
    .table-wrap { overflow-x: auto; }
    .chip { display: inline-block; padding: 2px 10px; border-radius: 10px; font-weight: 700; background: var(--gp-background); }
    .chip.on { background: var(--gp-navy); color: #fff; }
    .chip.expired { background: var(--gp-warning-surface); color: var(--gp-warning); }
    .actions { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 12px; align-items: center; }
    .game { border: 1px solid var(--gp-background); border-radius: 8px; padding: 12px; margin: 12px 0; }
    .game h3 { margin: 0 0 8px; font-size: 14px; display: flex; gap: 12px; align-items: center; }
    .total { margin-left: auto; font-weight: 400; }
    .errors { color: var(--gp-error); margin: 8px 0 0; padding-left: 18px; }
    .ok { color: var(--gp-navy); font-weight: 600; }
    .confirm { margin-top: 12px; }
    .num { width: 120px; }
    .wide { width: 100%; box-sizing: border-box; }
    dl { display: grid; grid-template-columns: max-content 1fr; gap: 4px 16px; margin: 0; }
    dt { color: var(--gp-muted); }
    dd { margin: 0; }
  `,
  template: `
    <h1>Muestra</h1>
    <p class="gp-muted">Escenario de demostración: un usuario simulado entra, compra en línea, recibe saldo y un código sorteado al azar de un lote cuyo resultado ya está decidido. Solo se enciende desde aquí.</p>
    <div role="status" aria-live="polite">
      @if (message()) { <p class="ok" data-testid="message">{{ message() }}</p> }
      @if (fail()) { <div class="gp-banner error" role="alert" data-testid="fail">{{ fail() }}</div> }
    </div>

    @if (error()) {
      <app-error-state [message]="error()!" (retry)="load()" />
    } @else if (state() === null) {
      <p class="gp-muted" role="status">Cargando…</p>
    } @else {
      @let st = state()!;
      @let d = draft()!;
      <section aria-labelledby="h-estado">
        <h2 id="h-estado">Estado</h2>
        <p>
          <span class="chip" [class.on]="st.status.active" [class.expired]="st.status.enabled && !st.status.active" data-testid="status-chip">{{ chipLabel() }}</span>
          @if (st.status.active && st.status.enabledUntil) { <span class="gp-muted"> Caduca el {{ bogota(st.status.enabledUntil) }}</span> }
        </p>
        @if (!st.status.serverAllowed) {
          <div class="gp-banner warning" role="alert" data-testid="not-allowed">Este servidor no es de demostración (falta GAMIF_DEMO_ALLOWED=true): la muestra no puede sembrarse ni encenderse.</div>
        }
        <dl>
          <dt>Códigos disponibles</dt><dd data-testid="pool-available">{{ st.pool.available }}</dd>
          <dt>Entregados</dt><dd data-testid="pool-assigned">{{ st.pool.assigned }}</dd>
          <dt>Retirados</dt><dd data-testid="pool-retired">{{ st.pool.retired }}</dd>
          <dt>Última corrida</dt>
          <dd data-testid="last-run">{{ runLabel(st.lastRun) }}</dd>
        </dl>
        @if (st.lastRun?.state === 'failed' && st.lastRun?.error) { <p class="gp-banner error" role="alert" data-testid="run-error">{{ st.lastRun!.error }}</p> }
        <div class="actions">
          <button mat-stroked-button type="button" (click)="seed()" [disabled]="!canSeed()" data-testid="seed">Sembrar</button>
          <button mat-stroked-button type="button" (click)="ask('reset')" [disabled]="!canReset()" data-testid="reset">Reiniciar</button>
          @if (st.status.enabled) {
            <button mat-flat-button type="button" (click)="ask('disable')" [disabled]="busy()" data-testid="disable">Apagar</button>
          } @else {
            <button mat-flat-button type="button" (click)="ask('enable')" [disabled]="!canEnable()" data-testid="enable">Encender</button>
          }
        </div>
        @if (dirty()) { <p class="gp-muted" data-testid="dirty-note">Hay cambios sin guardar: guárdalos antes de sembrar, reiniciar o encender.</p> }
        @if (confirm(); as c) {
          <div class="gp-banner warning confirm" role="alertdialog" aria-labelledby="confirm-text" data-testid="confirm">
            <span id="confirm-text">{{ confirmText(c) }}</span>
            <button mat-flat-button type="button" (click)="doConfirm()" [disabled]="busy()" data-testid="confirm-yes">Confirmar</button>
            <button mat-button type="button" (click)="confirm.set(null)" data-testid="confirm-no">Cancelar</button>
          </div>
        }
      </section>

      <form (input)="touch()" (change)="touch()" (submit)="$event.preventDefault()" aria-label="Configuración de la muestra">
        <section aria-labelledby="h-escenario">
          <h2 id="h-escenario">Escenario</h2>
          <div class="grid">
            <label class="f">Nombre del usuario simulado<input name="displayName" [(ngModel)]="d.user.displayName" maxlength="60" data-testid="displayName" /></label>
            <label class="f">Prefijo del id de host<input name="prefix" [(ngModel)]="d.user.hostUserPrefix" maxlength="32" data-testid="prefix" /></label>
            <label class="f">Saldo inicial (COP)<input class="num" type="number" step="1" min="0" name="initialBalance" [(ngModel)]="d.user.initialBalance" data-testid="initialBalance" /></label>
            <label class="f">Caducidad de la muestra (horas, vacío = sin caducar)<input class="num" type="number" step="1" min="1" name="ttl" [ngModel]="d.ttlHours" (ngModelChange)="setTtl($event)" data-testid="ttl" /></label>
          </div>
          <h2 class="gp-muted">Catálogo</h2>
          <div class="table-wrap">
            <table>
              <caption class="gp-visually-hidden">Productos de la tienda de muestra</caption>
              <thead><tr><th scope="col">Id</th><th scope="col">Nombre</th><th scope="col">Precio (COP)</th><th scope="col"><span class="gp-visually-hidden">Acción</span></th></tr></thead>
              <tbody>
                @for (p of d.products; track $index; let i = $index) {
                  <tr data-testid="product-row">
                    <td><input [name]="'pid' + i" [(ngModel)]="p.id" maxlength="32" [attr.aria-label]="'Id del producto ' + (i + 1)" /></td>
                    <td><input [name]="'pname' + i" [(ngModel)]="p.name" maxlength="80" [attr.aria-label]="'Nombre del producto ' + (i + 1)" /></td>
                    <td><input class="num" type="number" step="1" min="1" [name]="'pprice' + i" [(ngModel)]="p.price" [attr.aria-label]="'Precio del producto ' + (i + 1)" /></td>
                    <td><button mat-button type="button" (click)="removeProduct(i)" [disabled]="d.products.length <= 1" [attr.aria-label]="'Quitar el producto ' + (i + 1)">Quitar</button></td>
                  </tr>
                }
              </tbody>
            </table>
          </div>
          <div class="actions"><button mat-stroked-button type="button" (click)="addProduct()" [disabled]="d.products.length >= st.limits.maxProducts" data-testid="add-product">Agregar producto</button></div>
        </section>

        <section aria-labelledby="h-reglas">
          <h2 id="h-reglas">Reglas de carga</h2>
          <div class="grid">
            <label class="f">Saldo cargado (% de la compra)<input class="num" type="number" step="1" min="0" max="100" name="pct" [(ngModel)]="d.credit.balancePercent" data-testid="balancePercent" /></label>
            <label class="f">Compra mínima (COP)<input class="num" type="number" step="1" min="0" name="minPurchase" [(ngModel)]="d.credit.minPurchase" data-testid="minPurchase" /></label>
            <label class="f">Un código por cada (COP)<input class="num" type="number" step="1" min="1" name="step" [(ngModel)]="d.credit.codeStepAmount" data-testid="codeStepAmount" /></label>
            <label class="f">Máximo de códigos por compra<input class="num" type="number" step="1" min="1" [max]="st.limits.maxCodesPerPurchase" name="perPurchase" [(ngModel)]="d.credit.maxCodesPerPurchase" data-testid="maxCodesPerPurchase" /></label>
            <label class="f">Máximo de códigos por sesión<input class="num" type="number" step="1" min="1" max="50" name="perSession" [(ngModel)]="d.credit.maxCodesPerSession" data-testid="maxCodesPerSession" /></label>
          </div>
          <h2 class="gp-muted">Vista previa por producto</h2>
          <div class="table-wrap">
            <table>
              <caption class="gp-visually-hidden">Lo que recibe quien compra cada producto</caption>
              <thead><tr><th scope="col">Producto</th><th scope="col">Precio</th><th scope="col">Saldo cargado</th><th scope="col">Códigos</th></tr></thead>
              <tbody>
                @for (r of preview(); track $index) {
                  <tr data-testid="preview-row">
                    <td>{{ r.name }}</td><td>{{ money(r.price) }}</td>
                    @if (r.allowed) { <td>{{ money(r.balance) }}</td><td>{{ r.codes }}</td> } @else { <td colspan="2" class="gp-muted">Por debajo de la compra mínima</td> }
                  </tr>
                }
              </tbody>
            </table>
          </div>
        </section>

        <section aria-labelledby="h-mezcla">
          <h2 id="h-mezcla">Mezcla de premios</h2>
          <p class="gp-muted">Cada código del pool nace con su resultado ya decidido. La compra sortea uno al azar entre los disponibles.</p>
          @for (g of gameKeys(); track g) {
            @let e = d.mix[g]!;
            <div class="game" data-testid="mix-game" [attr.data-game]="g">
              <h3>{{ gameLabel[g] }}
                <span class="total gp-muted" data-testid="mix-total">{{ totals(e).winners }} con premio + {{ totals(e).none }} sin premio = {{ totals(e).total }} códigos</span>
                <button mat-button type="button" (click)="removeGame(g)" [disabled]="gameKeys().length <= 1" [attr.aria-label]="'Quitar ' + gameLabel[g]">Quitar juego</button>
              </h3>
              <div class="table-wrap">
                <table>
                  <caption class="gp-visually-hidden">Premios de {{ gameLabel[g] }}</caption>
                  <thead><tr><th scope="col">Tipo</th><th scope="col">Monto</th><th scope="col">Cantidad</th><th scope="col"><span class="gp-visually-hidden">Acción</span></th></tr></thead>
                  <tbody>
                    @for (p of e.prizes; track $index; let j = $index) {
                      <tr data-testid="prize-row">
                        <td>
                          <select [name]="g + 'type' + j" [(ngModel)]="p.type" [attr.aria-label]="gameLabel[g] + ' premio ' + (j + 1) + ': tipo'">
                            @for (t of prizeTypes; track t[0]) { <option [value]="t[0]">{{ t[1] }}</option> }
                          </select>
                        </td>
                        <td><input class="num" type="number" step="1" min="0" [name]="g + 'amount' + j" [(ngModel)]="p.amount" [attr.aria-label]="gameLabel[g] + ' premio ' + (j + 1) + ': monto'" /></td>
                        <td><input class="num" type="number" step="1" min="1" [name]="g + 'count' + j" [(ngModel)]="p.count" [attr.aria-label]="gameLabel[g] + ' premio ' + (j + 1) + ': cantidad'" /></td>
                        <td><button mat-button type="button" (click)="removePrize(e, j)" [attr.aria-label]="'Quitar el premio ' + (j + 1) + ' de ' + gameLabel[g]">Quitar</button></td>
                      </tr>
                    }
                  </tbody>
                </table>
              </div>
              <div class="actions">
                <button mat-stroked-button type="button" (click)="addPrize(e)" [attr.data-testid]="'add-prize-' + g">Agregar premio</button>
                <label class="f">Códigos sin premio<input class="num" type="number" step="1" min="0" [name]="g + 'none'" [(ngModel)]="e.noPrizeCount" [attr.data-testid]="'none-' + g" /></label>
              </div>
            </div>
          }
          @if (freeGames().length > 0) {
            <div class="actions">
              <label class="f">Agregar juego
                <select name="newGame" [ngModel]="''" (ngModelChange)="addGame($event)" data-testid="add-game">
                  <option value="">Elegir…</option>
                  @for (g of freeGames(); track g) { <option [value]="g">{{ gameLabel[g] }}</option> }
                </select>
              </label>
            </div>
          }
          <p data-testid="pool-total"><strong>Pool total: {{ size() }}</strong> <span class="gp-muted">de máximo {{ st.limits.maxPool }} códigos</span></p>
        </section>

        @if (problems().length > 0) {
          <ul class="errors" role="alert" data-testid="problems">@for (m of problems(); track m) { <li>{{ m }}</li> }</ul>
        }
        <div class="actions">
          <button mat-flat-button type="button" (click)="save()" [disabled]="!dirty() || problems().length > 0 || busy()" data-testid="save">Guardar configuración</button>
          <button mat-button type="button" (click)="discard()" [disabled]="!dirty() || busy()" data-testid="discard">Descartar cambios</button>
        </div>
      </form>
    }
  `,
})
export class DemoScreen implements OnInit {
  private readonly api = inject(Api);
  protected readonly state = signal<DemoState | null>(null);
  protected readonly draft = signal<DemoConfig | null>(null);
  protected readonly error = signal<string | null>(null);
  protected readonly message = signal<string | null>(null);
  protected readonly fail = signal<string | null>(null);
  protected readonly busy = signal(false);
  protected readonly confirm = signal<Confirm>(null);
  /** Se incrementa en cada edición del formulario: el borrador es un objeto mutable (ngModel) y los cálculos dependen de este contador. */
  private readonly rev = signal(0);
  protected readonly bogota = dateBogota;
  protected readonly gameLabel = GAME_LABEL;
  protected readonly prizeTypes = Object.entries(PRIZE_TYPE_LABEL) as [PrizeType, string][];
  private timer?: ReturnType<typeof setTimeout>;
  private destroyed = false;

  protected readonly gameKeys = computed(() => {
    this.rev();
    const mix = this.draft()?.mix ?? {};
    return (Object.keys(GAME_LABEL) as GameType[]).filter((g) => mix[g] !== undefined);
  });
  protected readonly freeGames = computed(() => {
    this.rev();
    const mix = this.draft()?.mix ?? {};
    return (this.state()?.limits.games ?? []).filter((g) => mix[g] === undefined);
  });
  protected readonly dirty = computed(() => {
    this.rev();
    const s = this.state();
    const d = this.draft();
    return s !== null && d !== null && canonical(d) !== canonical(s.config);
  });
  protected readonly problems = computed(() => {
    this.rev();
    const s = this.state();
    const d = this.draft();
    return s && d ? validateDemo(d, s.limits) : [];
  });
  protected readonly size = computed(() => {
    this.rev();
    const d = this.draft();
    return d ? poolSize(d) : 0;
  });
  protected readonly preview = computed(() => {
    this.rev();
    const d = this.draft();
    return (d?.products ?? []).map((p) => ({ name: p.name, price: p.price, ...previewPurchase(d!.credit, p.price) }));
  });
  private readonly running = computed(() => ['pending', 'running'].includes(this.state()?.lastRun?.state ?? ''));
  protected readonly canSeed = computed(() => {
    const s = this.state();
    return !!s && s.configured && s.status.serverAllowed && !this.dirty() && s.pool.available === 0 && !this.busy() && !this.running();
  });
  protected readonly canReset = computed(() => {
    const s = this.state();
    return !!s && s.configured && s.status.serverAllowed && !this.dirty() && !this.busy() && !this.running();
  });
  protected readonly canEnable = computed(() => {
    const s = this.state();
    return !!s && s.configured && s.status.serverAllowed && !this.dirty() && s.pool.available > 0 && !this.busy() && !this.running();
  });

  constructor() {
    inject(DestroyRef).onDestroy(() => {
      this.destroyed = true;
      clearTimeout(this.timer);
    });
  }

  ngOnInit(): void {
    void this.load();
  }

  protected touch(): void {
    this.rev.update((n) => n + 1);
  }

  protected money(v: number): string {
    return `$ ${v.toLocaleString('es-CO')}`;
  }

  protected totals(e: DemoMixEntry) {
    return mixTotals(e);
  }

  protected chipLabel(): string {
    const s = this.state()!.status;
    return s.active ? 'Encendida' : s.enabled ? 'Caducada' : 'Apagada';
  }

  protected runLabel(r: DemoRun | null): string {
    if (!r) return 'Ninguna';
    const kind = r.kind === 'seed' ? 'Siembra' : 'Reinicio';
    const st = { pending: 'en cola', running: 'en curso', done: 'terminada', failed: 'con error' }[r.state];
    return `${kind} ${st} · ${this.bogota(r.finishedAt ?? r.startedAt ?? r.createdAt)}`;
  }

  protected confirmText(c: Exclude<Confirm, null>): string {
    const s = this.state()!;
    if (c === 'enable') return `Se abrirá el acceso público a la muestra${s.config.ttlHours ? ` por ${s.config.ttlHours} horas` : ' sin caducidad'}. Quien llegue a la app podrá simular compras y recibir códigos. ¿Encender?`;
    if (c === 'disable') return 'La muestra dejará de responder de inmediato: quienes estén en ella perderán la sesión. ¿Apagar?';
    return 'Se apagará la muestra, se retirarán los códigos actuales (también los ya entregados) y se sembrará un pool nuevo. ¿Reiniciar?';
  }

  protected async load(): Promise<void> {
    this.error.set(null);
    try {
      const s = await this.api.get<DemoState>('/demo');
      this.state.set(s);
      this.draft.set(structuredClone(s.config));
      this.touch();
      if (s.lastRun && ['pending', 'running'].includes(s.lastRun.state)) this.poll(s.lastRun.id);
    } catch (e) {
      this.error.set(e instanceof ApiError ? e.message : 'No se pudo cargar la muestra.');
    }
  }

  /** Recarga solo el estado (sin pisar lo que se está editando). */
  private async refreshStatus(): Promise<void> {
    const s = await this.api.get<DemoState>('/demo');
    this.state.set(s);
  }

  protected setTtl(v: number | null | string): void {
    this.draft()!.ttlHours = v === null || v === '' ? null : Number(v);
    this.touch();
  }

  protected addProduct(): void {
    const d = this.draft()!;
    let n = d.products.length + 1;
    while (d.products.some((p) => p.id === `producto-${n}`)) n++;
    d.products.push({ id: `producto-${n}`, name: `Producto ${n}`, price: 10_000 });
    this.touch();
  }

  protected removeProduct(i: number): void {
    this.draft()!.products.splice(i, 1);
    this.touch();
  }

  protected addGame(g: string): void {
    if (!g) return;
    this.draft()!.mix[g as GameType] = { prizes: [{ type: 'money', amount: 5_000, count: 1 }], noPrizeCount: 4 };
    this.touch();
  }

  protected removeGame(g: GameType): void {
    delete this.draft()!.mix[g];
    this.touch();
  }

  protected addPrize(e: DemoMixEntry): void {
    e.prizes.push({ type: 'money', amount: 1_000, count: 1 });
    this.touch();
  }

  protected removePrize(e: DemoMixEntry, j: number): void {
    e.prizes.splice(j, 1);
    this.touch();
  }

  protected discard(): void {
    this.draft.set(structuredClone(this.state()!.config));
    this.touch();
  }

  private async act(ok: string, run: () => Promise<unknown>): Promise<boolean> {
    this.busy.set(true);
    this.message.set(null);
    this.fail.set(null);
    try {
      await run();
      this.message.set(ok);
      return true;
    } catch (e) {
      this.fail.set(e instanceof ApiError ? e.message : 'No se pudo completar la acción.');
      return false;
    } finally {
      this.busy.set(false);
    }
  }

  protected async save(): Promise<void> {
    if (await this.act('Configuración guardada.', () => this.api.put('/demo/config', this.draft()))) await this.load();
  }

  protected async seed(): Promise<void> {
    await this.startRun('Siembra pedida: el worker la procesa en menos de un minuto.', '/demo/seed');
  }

  protected ask(c: Exclude<Confirm, null>): void {
    this.confirm.set(c);
  }

  protected async doConfirm(): Promise<void> {
    const c = this.confirm();
    this.confirm.set(null);
    if (c === 'enable') {
      if (await this.act('Muestra encendida.', () => this.api.post('/demo/enable'))) await this.refreshStatus();
    } else if (c === 'disable') {
      if (await this.act('Muestra apagada.', () => this.api.post('/demo/disable'))) await this.refreshStatus();
    } else if (c === 'reset') {
      await this.startRun('Reinicio pedido: el worker lo procesa en menos de un minuto.', '/demo/reset');
    }
  }

  private async startRun(ok: string, path: string): Promise<void> {
    let id = 0;
    if (!(await this.act(ok, async () => (id = (await this.api.post<{ runId: number }>(path)).runId)))) return;
    await this.refreshStatus();
    this.poll(id);
  }

  /** Consulta la corrida hasta que termine; al terminar recarga el estado (pool y última corrida). */
  private poll(id: number): void {
    clearTimeout(this.timer);
    this.timer = setTimeout(async () => {
      if (this.destroyed) return;
      try {
        const run = await this.api.get<DemoRun>(`/demo/runs/${id}`);
        if (run.state === 'pending' || run.state === 'running') {
          this.poll(id);
          return;
        }
        await this.refreshStatus();
        if (run.state === 'done') this.message.set('La corrida terminó.');
      } catch {
        this.poll(id);
      }
    }, POLL_MS);
  }
}
