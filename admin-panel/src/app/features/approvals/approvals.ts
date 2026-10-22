import { HttpClient, HttpResponse } from '@angular/common/http';
import { Component, OnDestroy, OnInit, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute } from '@angular/router';
import { MatButtonModule } from '@angular/material/button';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { MatTabsModule } from '@angular/material/tabs';
import { firstValueFrom } from 'rxjs';
import { ApprovalsState } from '../../core/approvals-state';
import { Api, ApiError } from '../../core/api';
import { Auth } from '../../core/auth';
import { remainingText, sha256Hex } from '../../core/download';
import { dateBogota, dateUtc } from '../../core/format';
import { Approval, BatchRow, Delivery } from '../../core/models';
import { ErrorState } from '../../shared/state';

const STATUS: Record<string, string> = { pending: 'Pendiente', approved: 'Aprobada', executed: 'Ejecutada', expired: 'Caducada' };
const STATUS_ICON: Record<string, string> = { pending: '⏳', approved: '✔', executed: '✔✔', expired: '⌛' };
const PARAM_LABEL: Record<string, string> = { batchId: 'Lote', prefix: 'Prefijo', key: 'Parámetro', value: 'Valor nuevo' };
/** Aviso naranja cuando faltan menos de 2 horas para que caduque (ux-spec §8). */
const WARN_MS = 2 * 3600 * 1000;

type Verdict = { ok: boolean; text: string };

/**
 * Aprobaciones (control dual, RF-43…RF-45). Cada fila muestra los parámetros REALES e inmutables de lo que se aprueba. Aprobar pide un código
 * TOTP nuevo; solo el solicitante ejecuta. Los archivos de una sola entrega (códigos y auditoría) se descargan una vez y se comprueban contra
 * el SHA-256 que dio el servidor al ejecutar.
 */
@Component({
  selector: 'app-approvals',
  imports: [FormsModule, MatButtonModule, MatFormFieldModule, MatInputModule, MatTabsModule, ErrorState],
  styles: `
    .tab { padding: 16px 0; display: flex; flex-direction: column; gap: 12px; }
    .item { background: var(--gp-surface); border-radius: 12px; padding: 12px 16px; max-width: 860px; }
    .item h3 { margin: 0 0 4px; font-size: 15px; }
    dl { display: grid; grid-template-columns: 160px 1fr; gap: 4px 12px; margin: 8px 0; }
    dt { color: var(--gp-muted); }
    dd { margin: 0; word-break: break-all; }
    .badge { font-weight: 600; white-space: nowrap; }
    .soon { color: var(--gp-warning); background: var(--gp-warning-surface); padding: 2px 8px; border-radius: 8px; font-weight: 600; }
    .actions { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; margin-top: 8px; }
    .confirm { background: var(--gp-warning-surface); border-radius: 12px; padding: 12px 16px; margin-top: 8px; }
    .delivery { background: var(--gp-warning-surface); border-radius: 12px; padding: 12px 16px; margin: 12px 0; max-width: 860px; }
    .delivery h2 { margin: 0 0 4px; font-size: 15px; }
    .ok { color: var(--gp-success); font-weight: 600; }
    .bad { color: var(--gp-error); font-weight: 700; }
    code { word-break: break-all; font-family: ui-monospace, monospace; font-size: 12px; }
  `,
  template: `
    <h1>Aprobaciones</h1>
    <p class="gp-muted">Control dual: una persona solicita, otra distinta aprueba con un código nuevo, y quien solicitó ejecuta.</p>
    <div role="status" aria-live="polite">
      @if (requested()) {
        <div class="gp-banner warning" data-testid="requested">Solicitud creada. Pídele a otra persona que la apruebe.@if (state.fewApprovers()) { <strong> Ahora mismo nadie más puede aprobarla.</strong> }</div>
      }
      @if (message()) { <p class="ok" data-testid="message">{{ message() }}</p> }
      @if (fail()) { <div class="gp-banner error" role="alert" data-testid="fail">{{ fail() }}</div> }
    </div>

    @for (a of delivered(); track a.id) {
      @if (deliveries()[a.id]; as d) {
        <section class="delivery" aria-label="Entrega única" data-testid="delivery" [attr.data-id]="a.id">
          <h2>{{ title(a) }}</h2>
          <p><strong>{{ a.action === 'codes.generate' ? d.count + ' códigos generados' : d.entries + ' entradas de la bitácora' }}.</strong> Se entrega UNA sola vez: guarda el archivo.</p>
          <dl>
            <dt>SHA-256 esperado</dt><dd><code data-testid="sha-expected">{{ d.sha256 }}</code></dd>
            <dt>Disponible</dt><dd [title]="utc(d.downloadUntil)">{{ untilText(d) }}</dd>
            @if (d.anchor) { <dt>Ancla</dt><dd><code data-testid="anchor">{{ json(d.anchor) }}</code><br /><span class="gp-muted">Publícala fuera de la base de datos.</span></dd> }
          </dl>
          <div class="actions"><button mat-flat-button type="button" (click)="download(a, d)" [disabled]="busy() || verdicts()[a.id]?.ok === true" [attr.data-testid]="'download-' + a.id">Descargar</button></div>
          @if (verdicts()[a.id]; as v) {
            <p [class]="v.ok ? 'ok' : 'bad'" role="status" data-testid="verdict"><span aria-hidden="true">{{ v.ok ? '✓' : '✗' }}</span> {{ v.text }}</p>
          }
        </section>
      }
    }

    @if (error()) {
      <app-error-state [message]="error()!" (retry)="load()" />
    } @else if (all() === null) {
      <p class="gp-muted" role="status">Cargando…</p>
    } @else {
      <mat-tab-group animationDuration="0ms">
        @for (t of tabs(); track t.key) {
          <mat-tab [label]="t.label + ' (' + list(t.key).length + ')'">
            <div class="tab" [attr.data-testid]="'tab-' + t.key">
              @for (a of list(t.key); track a.id) {
                <article class="item" data-testid="approval" [attr.data-id]="a.id" [attr.data-status]="a.status" [attr.aria-label]="'Solicitud ' + a.id">
                  <h3>{{ title(a) }}</h3>
                  <span class="badge"><span aria-hidden="true">{{ icon[a.status] }}</span> {{ label[a.status] ?? a.status }}</span>
                  @if (isOpen(a)) {
                    <span [class.soon]="expiringSoon(a)" [title]="utc(a.expiresAt)"> · caduca en {{ left(a) }}@if (expiringSoon(a)) { <span aria-hidden="true"> ⚠</span> }</span>
                  }
                  <dl>
                    <dt>Solicitó</dt><dd>{{ a.requestedBy.name }}{{ a.requestedBy.id === me() ? ' (tú)' : '' }} · <span [title]="utc(a.requestedAt)">{{ bogota(a.requestedAt) }}</span></dd>
                    @if (a.approvedBy) { <dt>Aprobó</dt><dd>{{ a.approvedBy.name }}@if (a.approvedAt) { · {{ bogota(a.approvedAt) }} }</dd> }
                    @for (p of entries(a); track p[0]) { <dt>{{ paramLabel[p[0]] ?? p[0] }}</dt><dd [attr.data-testid]="'param-' + p[0]">{{ p[1] }}</dd> }
                  </dl>

                  @if (a.status === 'pending') {
                    @if (a.requestedBy.id === me()) {
                      <p class="gp-muted" data-testid="needs-other">Otra persona debe aprobarla: tú no puedes aprobar tu propia solicitud.</p>
                    } @else if (auth.can('approvals.approve')) {
                      @if (approving() !== a.id) {
                        <div class="actions"><button mat-flat-button type="button" (click)="startApprove(a)" [attr.data-testid]="'approve-' + a.id">Aprobar<span class="gp-visually-hidden"> la solicitud {{ a.id }}</span></button></div>
                      } @else {
                        <form class="confirm" (ngSubmit)="approve(a)" role="alertdialog" aria-label="Aprobar la solicitud" data-testid="approve-form">
                          <p><strong>Vas a aprobar:</strong> {{ title(a) }}</p>
                          <ul>@for (p of entries(a); track p[0]) { <li>{{ paramLabel[p[0]] ?? p[0] }}: <code>{{ p[1] }}</code></li> }</ul>
                          <p class="gp-muted">Usa un código que no hayas usado para entrar (espera al siguiente si es el mismo).</p>
                          <mat-form-field>
                            <mat-label>Código de 6 dígitos</mat-label>
                            <input matInput name="totp" inputmode="numeric" autocomplete="one-time-code" maxlength="6" [(ngModel)]="totp" data-testid="approve-code" />
                          </mat-form-field>
                          <div class="actions">
                            <button mat-flat-button type="submit" [disabled]="busy() || totp.length !== 6" data-testid="approve-submit">Confirmar aprobación</button>
                            <button mat-button type="button" (click)="approving.set(null)">Cancelar</button>
                          </div>
                        </form>
                      }
                    }
                  }

                  @if (a.status === 'approved' && a.requestedBy.id === me()) {
                    <div class="actions">
                      <button mat-flat-button type="button" (click)="execute(a)" [disabled]="busy()" [attr.data-testid]="'execute-' + a.id">Ejecutar<span class="gp-visually-hidden"> la solicitud {{ a.id }}</span></button>
                      @if (retryHint() === a.id) { <span class="gp-muted">La aprobación sigue vigente: reintenta en unos segundos.</span> }
                    </div>
                  } @else if (a.status === 'approved') {
                    <p class="gp-muted">La ejecuta quien la solicitó ({{ a.requestedBy.name }}).</p>
                  }

                </article>
              }
              @if (list(t.key).length === 0) { <p class="gp-muted" [attr.data-testid]="'empty-' + t.key">{{ t.empty }}</p> }
            </div>
          </mat-tab>
        }
      </mat-tab-group>
    }
  `,
})
export class ApprovalsScreen implements OnInit, OnDestroy {
  private readonly api = inject(Api);
  private readonly http = inject(HttpClient);
  protected readonly auth = inject(Auth);
  protected readonly state = inject(ApprovalsState);
  private readonly route = inject(ActivatedRoute);
  protected readonly all = signal<Approval[] | null>(null);
  protected readonly names = signal<Map<string, string>>(new Map());
  protected readonly error = signal<string | null>(null);
  protected readonly message = signal<string | null>(null);
  protected readonly fail = signal<string | null>(null);
  protected readonly busy = signal(false);
  protected readonly approving = signal<number | null>(null);
  protected readonly retryHint = signal<number | null>(null);
  protected readonly deliveries = signal<Record<number, Delivery>>({});
  protected readonly verdicts = signal<Record<number, Verdict>>({});
  protected readonly requested = signal(false);
  protected readonly now = signal(Date.now());
  protected totp = '';
  protected readonly me = () => this.auth.me()?.id;
  protected readonly label = STATUS;
  protected readonly icon = STATUS_ICON;
  protected readonly paramLabel = PARAM_LABEL;
  protected readonly bogota = dateBogota;
  protected readonly utc = dateUtc;
  private timer?: ReturnType<typeof setInterval>;

  /** Las pestañas que le sirven al rol: quien no puede aprobar ni solicitar (auditor) ve las pendientes, las de ejecución y el historial. */
  protected readonly tabs = computed(() => {
    const all = [
      { key: 'mine-to-approve', label: 'Esperan mi aprobación', empty: 'Nada espera tu aprobación.', show: this.auth.can('approvals.approve') },
      { key: 'pending', label: 'Pendientes', empty: 'No hay solicitudes pendientes.', show: true },
      { key: 'requested', label: 'Mis solicitudes', empty: 'Todavía no has pedido nada.', show: this.auth.can('approvals.request') },
      { key: 'to-execute', label: 'Esperan ejecución', empty: 'Nada espera ejecución.', show: true },
      { key: 'history', label: 'Historial', empty: 'Todavía no hay solicitudes cerradas.', show: true },
    ];
    return all.filter((t) => t.show);
  });

  private readonly groups = computed(() => {
    const me = this.me();
    const a = this.all() ?? [];
    return {
      'mine-to-approve': a.filter((x) => x.status === 'pending' && x.requestedBy.id !== me && this.auth.can('approvals.approve')),
      pending: a.filter((x) => x.status === 'pending'),
      requested: a.filter((x) => x.requestedBy.id === me),
      'to-execute': a.filter((x) => x.status === 'approved'),
      history: a.filter((x) => x.status === 'executed' || x.status === 'expired'),
    } as Record<string, Approval[]>;
  });

  ngOnInit(): void {
    this.requested.set(this.route.snapshot.queryParamMap.has('solicitada'));
    this.timer = setInterval(() => this.now.set(Date.now()), 1000);
    void this.load();
    void this.api.get<{ batches: BatchRow[] }>('/batches').then((r) => this.names.set(new Map(r.batches.map((b) => [b.id, b.name]))), () => undefined);
  }

  ngOnDestroy(): void {
    clearInterval(this.timer);
  }

  /** Las solicitudes cuya entrega única sigue en pantalla: vive aparte porque, al ejecutarse, la solicitud pasa al historial. */
  protected readonly delivered = computed(() => (this.all() ?? []).filter((a) => this.deliveries()[a.id] !== undefined));

  protected list = (key: string) => this.groups()[key] ?? [];
  protected isOpen = (a: Approval) => a.status === 'pending' || a.status === 'approved';
  protected expiringSoon = (a: Approval) => new Date(a.expiresAt).getTime() - this.now() < WARN_MS;
  protected left = (a: Approval) => remainingText(a.expiresAt, this.now()) ?? 'menos de 1 s';
  protected untilText = (d: Delivery) => { const r = remainingText(d.downloadUntil, this.now()); return r ? `quedan ${r}` : 'venció'; };
  protected json = (v: unknown) => JSON.stringify(v);
  protected entries = (a: Approval) => Object.entries(a.params).map(([k, v]) => [k, k === 'batchId' ? `${this.names().get(String(v)) ?? '(lote)'} · ${v}` : String(v)] as [string, string]);

  protected title(a: Approval): string {
    const batch = this.names().get(String(a.params['batchId'])) ?? 'un lote';
    switch (a.action) {
      case 'batch.activate': return `Activar el lote «${batch}»`;
      case 'codes.generate': return `Generar los códigos del lote «${batch}»`;
      case 'audit.export': return `Exportar la auditoría del lote «${batch}»`;
      case 'setting.change': return `Cambiar el parámetro ${a.params['key']} a ${a.params['value']}`;
      default: return a.action;
    }
  }

  protected async load(): Promise<void> {
    this.error.set(null);
    try {
      const r = await this.api.get<{ approvals: Approval[]; fewApprovers: boolean }>('/approvals');
      this.all.set(r.approvals);
      this.state.update(r.approvals, r.fewApprovers);
    } catch (e) {
      this.error.set(e instanceof ApiError ? e.message : 'No se pudieron cargar las aprobaciones.');
    }
  }

  protected startApprove(a: Approval): void {
    this.message.set(null);
    this.fail.set(null);
    this.totp = '';
    this.approving.set(a.id);
  }

  private async act(run: () => Promise<void>, onError?: (e: ApiError) => string | null): Promise<void> {
    this.busy.set(true);
    this.message.set(null);
    this.fail.set(null);
    this.requested.set(false);
    try {
      await run();
    } catch (e) {
      const err = e instanceof ApiError ? e : new ApiError(0, 'network', 'Error inesperado.');
      this.fail.set(onError?.(err) ?? err.message);
    } finally {
      this.busy.set(false);
    }
  }

  protected async approve(a: Approval): Promise<void> {
    const code = this.totp;
    this.totp = '';
    await this.act(
      async () => {
        await this.api.post(`/approvals/${a.id}/approve`, { code });
        this.approving.set(null);
        this.message.set(`Aprobada: ${this.title(a)}. Ahora la ejecuta ${a.requestedBy.name}.`);
        await this.load();
      },
      (e) => ({ selfApproval: 'No puedes aprobar tu propia solicitud.', invalidCode: 'El código no es válido. Usa un código nuevo, distinto al de tu inicio de sesión.', expired: 'La solicitud caducó: hay que pedirla de nuevo.' } as Record<string, string>)[e.code] ?? null,
    );
  }

  protected async execute(a: Approval): Promise<void> {
    this.retryHint.set(null);
    await this.act(
      async () => {
        const r = await this.api.post<Delivery | undefined>(`/approvals/${a.id}/execute`);
        if (r && typeof r === 'object' && 'sha256' in r) this.deliveries.update((d) => ({ ...d, [a.id]: r }));
        this.message.set(`Ejecutada: ${this.title(a)}.`);
        await this.load();
      },
      (e) => {
        if (e.code === 'beaconNotReady') {
          this.retryHint.set(a.id);
          return 'La baliza aún no publica la ronda; la aprobación sigue vigente. Reintenta en unos segundos.';
        }
        return e.code === 'expired' ? 'La solicitud caducó: hay que pedirla de nuevo.' : null;
      },
    );
  }

  /** Descarga única: pide el archivo, comprueba su SHA-256 contra el que dio el servidor y solo entonces lo entrega al navegador. */
  protected async download(a: Approval, d: Delivery): Promise<void> {
    this.busy.set(true);
    this.fail.set(null);
    try {
      const res: HttpResponse<ArrayBuffer> = await firstValueFrom(this.http.get(`${Api.BASE}/approvals/${a.id}/download`, { observe: 'response', responseType: 'arraybuffer' }));
      const bytes = res.body as ArrayBuffer;
      const actual = await sha256Hex(bytes);
      const headerHash = res.headers.get('X-Content-SHA256');
      if (actual !== d.sha256 || (headerHash !== null && headerHash !== d.sha256)) {
        this.verdicts.update((v) => ({ ...v, [a.id]: { ok: false, text: 'El archivo no coincide con el SHA-256 esperado: no lo uses.' } }));
        return;
      }
      const url = URL.createObjectURL(new Blob([bytes], { type: res.headers.get('Content-Type') ?? 'application/octet-stream' }));
      const link = document.createElement('a');
      link.href = url;
      link.download = a.action === 'codes.generate' ? `codigos-${a.id}.csv` : `auditoria-${a.id}.json`;
      link.click();
      URL.revokeObjectURL(url);
      this.verdicts.update((v) => ({ ...v, [a.id]: { ok: true, text: 'Descargado y verificado: el SHA-256 coincide. Esta fue la única descarga.' } }));
    } catch (e) {
      const status = (e as { status?: number }).status;
      const text = status === 410
        ? 'Ya no está disponible. Si lo que pediste eran códigos, hay que recrear el lote.'
        : status === 500 ? 'El servidor detectó que el archivo no coincide con el registro: no lo uses.' : 'No se pudo descargar el archivo.';
      this.verdicts.update((v) => ({ ...v, [a.id]: { ok: false, text } }));
    } finally {
      this.busy.set(false);
    }
  }
}
