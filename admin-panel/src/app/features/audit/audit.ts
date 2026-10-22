import { Component, OnInit, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatCardModule } from '@angular/material/card';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { Api, ApiError } from '../../core/api';
import { dateBogota, dateUtc } from '../../core/format';
import { Anchor, AuditEntry, AuditPage, VerifySegment } from '../../core/models';
import { ChainResult, verifyWholeChain } from '../../core/verify';
import { ErrorState } from '../../shared/state';

const PER_PAGE = 50;

/** Bitácora (RF-38): listado filtrable de solo lectura, verificación de TODA la cadena y ancla para publicar fuera de la base. */
@Component({
  selector: 'app-audit',
  imports: [FormsModule, MatCardModule, MatFormFieldModule, MatInputModule, MatButtonModule, ErrorState],
  styles: `
    mat-card { margin-bottom: 16px; padding: 4px; }
    .filters { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 0 12px; }
    table { width: 100%; border-collapse: collapse; font-size: 13px; background: var(--gp-surface); }
    th, td { text-align: left; padding: 6px 8px; border-bottom: 1px solid var(--gp-background); vertical-align: top; }
    th { color: var(--gp-muted); font-weight: 600; }
    td.mono, pre, code { font-family: ui-monospace, monospace; }
    pre { margin: 4px 0 0; white-space: pre-wrap; word-break: break-all; background: var(--gp-background); padding: 8px; border-radius: 8px; }
    .actions { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
    .ok { color: var(--gp-success); font-weight: 600; }
    .bad { color: var(--gp-error); font-weight: 600; }
    .hash { word-break: break-all; }
    .table-wrap { overflow-x: auto; }
  `,
  template: `
    <h1>Bitácora</h1>
    <p class="gp-muted">Registro encadenado: cada entrada incluye el hash de la anterior. Solo lectura.</p>

    <mat-card>
      <mat-card-header><mat-card-title>Integridad</mat-card-title></mat-card-header>
      <mat-card-content>
        <p class="gp-muted">
          La verificación recorre la cadena completa desde la primera entrada. No detecta que se recorten las últimas entradas: para eso
          publica el ancla fuera de la base de datos.
        </p>
        <div class="actions">
          <button mat-flat-button type="button" (click)="verify()" [disabled]="verifying()" data-testid="verify">
            {{ verifying() ? 'Verificando… ' + progress() + ' entradas' : 'Verificar la cadena completa' }}
          </button>
        </div>
        <div role="status" aria-live="polite" data-testid="verify-result">
          @if (chain(); as c) {
            @if (c.brokenAt === null) {
              <p class="ok">✔ Cadena íntegra: {{ c.checked }} entradas verificadas.</p>
            } @else {
              <p class="bad">✖ La cadena se rompe en la entrada #{{ c.brokenAt }}. Se verificaron {{ c.checked }} entradas.</p>
            }
          }
          @if (verifyError()) { <p class="bad">{{ verifyError() }}</p> }
        </div>

        <h2>Ancla</h2>
        @if (anchor() === undefined) {
          <p class="gp-muted">Cargando…</p>
        } @else if (anchor() === null) {
          <p class="gp-muted" data-testid="no-anchor">Todavía no hay ancla: aún no se ha registrado ninguna entrada.</p>
        } @else {
          <p class="gp-muted">Publica este último hash fuera de la base (por ejemplo en un acta). Quien la conserve podrá detectar un recorte posterior de la bitácora.</p>
          <dl data-testid="anchor">
            <dt>Entrada</dt><dd>#{{ anchor()!.head_id }} · versión {{ anchor()!.version }}</dd>
            <dt>Hash</dt><dd class="hash"><code>{{ anchor()!.head_hash }}</code></dd>
            <dt>Generada</dt><dd [title]="utc(anchor()!.created_at)">{{ bogota(anchor()!.created_at) }}</dd>
          </dl>
          <button mat-stroked-button type="button" (click)="copyAnchor()">{{ copied() ? 'Copiado' : 'Copiar ancla' }}</button>
        }
      </mat-card-content>
    </mat-card>

    <mat-card>
      <mat-card-content>
        <form class="filters" (ngSubmit)="search()" aria-label="Filtros de la bitácora">
          <mat-form-field><mat-label>Acción</mat-label><input matInput name="action" [(ngModel)]="f.action" placeholder="code.redeemed" /></mat-form-field>
          <mat-form-field><mat-label>Tipo de sujeto</mat-label><input matInput name="subjectType" [(ngModel)]="f.subjectType" placeholder="batch" /></mat-form-field>
          <mat-form-field><mat-label>Sujeto</mat-label><input matInput name="subjectId" [(ngModel)]="f.subjectId" /></mat-form-field>
          <mat-form-field><mat-label>Actor</mat-label><input matInput name="actor" [(ngModel)]="f.actor" placeholder="admin:1" /></mat-form-field>
          <mat-form-field><mat-label>Desde</mat-label><input matInput name="from" type="date" [(ngModel)]="f.from" /></mat-form-field>
          <mat-form-field><mat-label>Hasta</mat-label><input matInput name="to" type="date" [(ngModel)]="f.to" /></mat-form-field>
          <div class="actions">
            <button mat-flat-button type="submit" [disabled]="loading()">Filtrar</button>
            <button mat-button type="button" (click)="clear()">Limpiar</button>
          </div>
        </form>
      </mat-card-content>
    </mat-card>

    @if (error()) {
      <app-error-state [message]="error()!" (retry)="search()" />
    } @else {
      <div class="table-wrap">
        <table>
          <caption class="gp-visually-hidden">Entradas de la bitácora, de la más nueva a la más antigua</caption>
          <thead><tr><th scope="col">#</th><th scope="col">Fecha</th><th scope="col">Actor</th><th scope="col">Acción</th><th scope="col">Sujeto</th><th scope="col">Detalle</th></tr></thead>
          <tbody>
            @for (e of items(); track e.id) {
              <tr data-testid="audit-row">
                <td>{{ e.id }}</td>
                <td [title]="utc(e.occurredAt)">{{ bogota(e.occurredAt) }}</td>
                <td class="mono">{{ e.actor }}</td>
                <td class="mono">{{ e.action }}</td>
                <td class="mono">{{ e.subjectType }} {{ e.subjectId }}</td>
                <td>
                  <button mat-button type="button" [attr.aria-expanded]="open() === e.id" [attr.aria-controls]="'d' + e.id" (click)="toggle(e.id)">
                    {{ open() === e.id ? 'Ocultar' : 'Ver' }}<span class="gp-visually-hidden"> detalle de la entrada {{ e.id }}</span>
                  </button>
                  @if (open() === e.id) { <pre [id]="'d' + e.id">{{ pretty(e) }}</pre> }
                </td>
              </tr>
            }
          </tbody>
        </table>
      </div>
      @if (!loading() && items().length === 0) {
        <p class="gp-muted" data-testid="empty">No hay entradas con esos filtros.</p>
      }
      @if (next() !== null) {
        <p><button mat-stroked-button type="button" (click)="more()" [disabled]="loading()">Cargar más antiguas</button></p>
      }
    }
  `,
})
export class AuditScreen implements OnInit {
  private readonly api = inject(Api);
  protected readonly items = signal<AuditEntry[]>([]);
  protected readonly next = signal<number | null>(null);
  protected readonly loading = signal(false);
  protected readonly error = signal<string | null>(null);
  protected readonly open = signal<number | null>(null);
  protected readonly anchor = signal<Anchor | null | undefined>(undefined);
  protected readonly copied = signal(false);
  protected readonly verifying = signal(false);
  protected readonly progress = signal(0);
  protected readonly chain = signal<ChainResult | null>(null);
  protected readonly verifyError = signal<string | null>(null);
  protected readonly bogota = dateBogota;
  protected readonly utc = dateUtc;
  protected f = { action: '', subjectType: '', subjectId: '', actor: '', from: '', to: '' };

  ngOnInit(): void {
    void this.search();
    void this.loadAnchor();
  }

  protected toggle(id: number): void {
    this.open.update((o) => (o === id ? null : id));
  }

  protected pretty(e: AuditEntry): string {
    return JSON.stringify(e.data, null, 2);
  }

  protected clear(): void {
    this.f = { action: '', subjectType: '', subjectId: '', actor: '', from: '', to: '' };
    void this.search();
  }

  protected async search(): Promise<void> {
    this.items.set([]);
    this.next.set(null);
    await this.fetch(undefined);
  }

  protected async more(): Promise<void> {
    const before = this.next();
    if (before !== null) await this.fetch(before);
  }

  private async fetch(beforeId: number | undefined): Promise<void> {
    this.loading.set(true);
    this.error.set(null);
    try {
      const page = await this.api.get<AuditPage>('/audit', { ...this.f, beforeId, perPage: PER_PAGE });
      this.items.update((cur) => [...cur, ...page.items]);
      this.next.set(page.nextBeforeId);
    } catch (e) {
      this.error.set(e instanceof ApiError ? e.message : 'No se pudo cargar la bitácora.');
    } finally {
      this.loading.set(false);
    }
  }

  private async loadAnchor(): Promise<void> {
    try {
      this.anchor.set((await this.api.get<{ anchor: Anchor | null }>('/audit/anchor')).anchor);
    } catch {
      this.anchor.set(null);
    }
  }

  protected async copyAnchor(): Promise<void> {
    const a = this.anchor();
    if (!a) return;
    try {
      await navigator.clipboard.writeText(`gamif-audit-anchor v${a.version} head_id=${a.head_id} head_hash=${a.head_hash} created_at=${a.created_at}`);
      this.copied.set(true);
    } catch {
      this.copied.set(false);
    }
  }

  protected async verify(): Promise<void> {
    this.verifying.set(true);
    this.chain.set(null);
    this.verifyError.set(null);
    this.progress.set(0);
    try {
      this.chain.set(await verifyWholeChain((after) => this.api.get<VerifySegment>('/audit/verify', { afterId: after, limit: 1000 }), (n) => this.progress.set(n)));
    } catch (e) {
      this.verifyError.set(
        e instanceof ApiError && e.status === 429 ? `Demasiadas verificaciones seguidas. Espera ${e.retryAfter ?? 60} s e inténtalo de nuevo.` : e instanceof ApiError ? e.message : 'No se pudo completar la verificación.',
      );
    } finally {
      this.verifying.set(false);
    }
  }
}
