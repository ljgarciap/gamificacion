import { Component, OnInit, inject, signal } from '@angular/core';
import { ActivatedRoute, Router } from '@angular/router';
import { MatButtonModule } from '@angular/material/button';
import { Api, ApiError } from '../../core/api';
import { Auth } from '../../core/auth';
import { dateBogota, dateUtc } from '../../core/format';
import { OutboxItem, OutboxPage } from '../../core/models';
import { ErrorState } from '../../shared/state';

type Filter = '' | 'pending' | 'sent' | 'failed';
const LABEL: Record<string, string> = { pending: 'Pendiente', sent: 'Enviado', failed: 'Fallido' };
const ICON: Record<string, string> = { pending: '⏳', sent: '✔', failed: '⚠' };
const PER_PAGE = 25;

/** Avisos al host (RF-39): estado de la entrega de premios. Reintentar solo en filas fallidas y solo con permiso. */
@Component({
  selector: 'app-outbox',
  imports: [MatButtonModule, ErrorState],
  styles: `
    .counters { display: flex; gap: 8px; flex-wrap: wrap; margin: 12px 0; }
    .counters button { min-width: 150px; }
    table { width: 100%; border-collapse: collapse; font-size: 13px; background: var(--gp-surface); }
    th, td { text-align: left; padding: 6px 8px; border-bottom: 1px solid var(--gp-background); vertical-align: top; }
    th { color: var(--gp-muted); font-weight: 600; }
    .table-wrap { overflow-x: auto; }
    .mono { font-family: ui-monospace, monospace; white-space: nowrap; }
    .badge { white-space: nowrap; font-weight: 600; }
    .badge.failed { color: var(--gp-error); }
    .badge.sent { color: var(--gp-success); }
    .badge.pending { color: var(--gp-warning); }
    .err { max-width: 260px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .err.open { white-space: normal; word-break: break-word; }
    .note { margin: 4px 0 0; }
    .pager { display: flex; gap: 8px; align-items: center; margin-top: 12px; }
  `,
  template: `
    <h1>Avisos al host</h1>
    <p class="gp-muted">Cada premio acreditado se avisa al sistema del host. Los avisos fallidos agotaron sus reintentos automáticos.</p>

    <div class="counters" role="group" aria-label="Filtrar por estado">
      @for (f of filters; track f.value) {
        <button mat-stroked-button type="button" [attr.aria-pressed]="filter() === f.value" [class.active]="filter() === f.value" (click)="choose(f.value)" [attr.data-testid]="'filter-' + (f.value || 'all')">
          {{ f.label }}: {{ f.value ? (page()?.counts?.[f.value] ?? '…') : total() }}
        </button>
      }
    </div>

    @if (message()) { <div class="gp-banner warning" role="status" data-testid="message">{{ message() }}</div> }
    @if (error()) {
      <app-error-state [message]="error()!" (retry)="load()" />
    } @else if (page(); as p) {
      <div class="table-wrap">
        <table>
          <caption class="gp-visually-hidden">Avisos al host, del más nuevo al más antiguo</caption>
          <thead><tr>
            <th scope="col">#</th><th scope="col">Canje</th><th scope="col">Usuario</th><th scope="col">Premio</th><th scope="col">Estado</th>
            <th scope="col">Intentos</th><th scope="col">Próximo intento</th><th scope="col">Último error</th>@if (canRetry()) { <th scope="col">Acción</th> }
          </tr></thead>
          <tbody>
            @for (i of p.items; track i.id) {
              <tr data-testid="outbox-row">
                <td>{{ i.id }}</td>
                <td class="mono" [title]="i.redemptionId"><span aria-hidden="true">{{ i.redemptionId.slice(0, 8) }}…</span><span class="gp-visually-hidden">{{ i.redemptionId }}</span></td>
                <td class="mono">{{ i.userId ?? '—' }}</td>
                <td>{{ i.prizeId ?? '—' }} <span class="gp-muted">{{ i.prizeType }}</span></td>
                <td><span class="badge" [class]="'badge ' + i.status"><span aria-hidden="true">{{ icon[i.status] }}</span> {{ label[i.status] }}</span></td>
                <td>{{ i.attempts }}</td>
                <td [title]="utc(i.nextAttemptAt)">{{ i.status === 'pending' ? bogota(i.nextAttemptAt) : '—' }}</td>
                <td>
                  @if (i.lastError) {
                    <div class="err" [class.open]="open() === i.id" [id]="'e' + i.id">{{ i.lastError }}</div>
                    <button mat-button type="button" [attr.aria-expanded]="open() === i.id" [attr.aria-controls]="'e' + i.id" (click)="open.set(open() === i.id ? null : i.id)">
                      {{ open() === i.id ? 'Ver menos' : 'Ver completo' }}<span class="gp-visually-hidden"> el error del aviso {{ i.id }}</span>
                    </button>
                  } @else { — }
                </td>
                @if (canRetry()) {
                  <td>
                    @if (i.status === 'failed') {
                      <button mat-flat-button type="button" [disabled]="busy() === i.id" (click)="retry(i)" [attr.data-testid]="'retry-' + i.id">Reintentar<span class="gp-visually-hidden"> el aviso {{ i.id }}</span></button>
                      <p class="gp-muted note">El host pudo haber recibido ya el aviso: debe tratarlo como idempotente.</p>
                    }
                  </td>
                }
              </tr>
            }
          </tbody>
        </table>
      </div>
      @if (p.items.length === 0) { <p class="gp-muted" data-testid="empty">No hay avisos con ese estado.</p> }
      <div class="pager">
        <button mat-stroked-button type="button" (click)="go(pageNo() - 1)" [disabled]="pageNo() <= 1">Anterior</button>
        <span>Página {{ pageNo() }} de {{ pages() }}</span>
        <button mat-stroked-button type="button" (click)="go(pageNo() + 1)" [disabled]="pageNo() >= pages()">Siguiente</button>
      </div>
    } @else {
      <p class="gp-muted" role="status">Cargando…</p>
    }
  `,
})
export class OutboxScreen implements OnInit {
  private readonly api = inject(Api);
  private readonly auth = inject(Auth);
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  protected readonly filters: { value: Filter; label: string }[] = [
    { value: '', label: 'Todos' },
    { value: 'pending', label: 'Pendientes' },
    { value: 'sent', label: 'Enviados' },
    { value: 'failed', label: 'Fallidos' },
  ];
  protected readonly page = signal<OutboxPage | null>(null);
  protected readonly filter = signal<Filter>('');
  protected readonly pageNo = signal(1);
  protected readonly error = signal<string | null>(null);
  protected readonly message = signal<string | null>(null);
  protected readonly busy = signal<number | null>(null);
  protected readonly open = signal<number | null>(null);
  protected readonly label = LABEL;
  protected readonly icon = ICON;
  protected readonly bogota = dateBogota;
  protected readonly utc = dateUtc;

  protected canRetry = () => this.auth.can('outbox.retry');
  protected total = () => (this.page() ? Object.values(this.page()!.counts).reduce((a, b) => a + b, 0) : '…');
  protected pages = () => Math.max(1, Math.ceil((this.page()?.total ?? 0) / PER_PAGE));

  ngOnInit(): void {
    const s = this.route.snapshot.queryParamMap.get('estado');
    if (s === 'pending' || s === 'sent' || s === 'failed') this.filter.set(s);
    void this.load();
  }

  protected choose(f: Filter): void {
    this.filter.set(f);
    this.pageNo.set(1);
    void this.router.navigate([], { queryParams: { estado: f || null }, replaceUrl: true });
    void this.load();
  }

  protected go(n: number): void {
    this.pageNo.set(n);
    void this.load();
  }

  protected async load(): Promise<void> {
    this.error.set(null);
    try {
      this.page.set(await this.api.get<OutboxPage>('/outbox', { status: this.filter(), page: this.pageNo(), perPage: PER_PAGE }));
    } catch (e) {
      this.error.set(e instanceof ApiError ? e.message : 'No se pudieron cargar los avisos.');
    }
  }

  protected async retry(i: OutboxItem): Promise<void> {
    this.busy.set(i.id);
    this.message.set(null);
    try {
      await this.api.post(`/outbox/${i.id}/retry`);
      this.message.set(`El aviso ${i.id} volvió a la cola y se enviará en el próximo ciclo.`);
    } catch (e) {
      this.message.set(e instanceof ApiError ? e.message : 'No se pudo reintentar.');
    } finally {
      this.busy.set(null);
      await this.load();
    }
  }
}
