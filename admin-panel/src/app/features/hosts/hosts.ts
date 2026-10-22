import { Component, OnInit, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatCardModule } from '@angular/material/card';
import { MatDialog } from '@angular/material/dialog';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { Api, ApiError } from '../../core/api';
import { Host } from '../../core/models';
import { SECRET_DIALOG_CONFIG, SecretDialog } from '../../shared/secret-dialog';
import { ErrorState } from '../../shared/state';

type Pending = { host: Host; kind: 'rotate' | 'retire' | 'toggle' };

const CONSEQUENCE: Record<Pending['kind'], string> = {
  rotate: 'Habrá dos secretos válidos a la vez: el nuevo (que se muestra una sola vez) y el actual, que pasa a «anterior». El host debe configurar el nuevo y después retirar el anterior.',
  retire: 'El secreto anterior dejará de ser válido. Hazlo solo cuando el host ya use el nuevo.',
  toggle: 'Un host desactivado deja de recibir avisos y de consultar saldos. Un host no se borra.',
};

/** Hosts y secretos (RF-49, RF-54, solo superadmin). El secreto nunca vuelve del servidor salvo al crear o rotar, y se muestra una vez. */
@Component({
  selector: 'app-hosts',
  imports: [FormsModule, MatCardModule, MatButtonModule, MatFormFieldModule, MatInputModule, ErrorState],
  styles: `
    mat-card { padding: 4px; margin-bottom: 16px; }
    .inline { display: flex; gap: 12px; flex-wrap: wrap; align-items: flex-start; }
    .inline mat-form-field { width: 260px; }
    table { width: 100%; min-width: 980px; border-collapse: collapse; font-size: 13px; background: var(--gp-surface); }
    th, td { text-align: left; padding: 6px 8px; border-bottom: 1px solid var(--gp-background); vertical-align: top; }
    th { color: var(--gp-muted); font-weight: 600; }
    .table-wrap { overflow-x: auto; }
    .url { word-break: break-all; max-width: 280px; }
    .acts { display: flex; gap: 4px; flex-wrap: wrap; }
    .confirm { background: var(--gp-danger-surface); padding: 8px 12px; border-radius: 8px; margin: 8px 0; }
    .fieldError { color: var(--gp-error); font-size: 12px; margin: 0 0 8px; }
    .ok { color: var(--gp-success); font-weight: 600; }
  `,
  template: `
    <h1>Hosts</h1>
    <mat-card>
      <mat-card-content>
        <form class="inline" (ngSubmit)="create()" aria-label="Alta de host">
          <mat-form-field><mat-label>Emisor</mat-label><input matInput name="issuer" [(ngModel)]="n.issuer" required autocomplete="off" /><mat-hint>No se puede cambiar después</mat-hint></mat-form-field>
          <mat-form-field><mat-label>URL de avisos</mat-label><input matInput name="cb" type="url" [(ngModel)]="n.callbackUrl" required autocomplete="off" /><mat-hint>https y dominio público</mat-hint></mat-form-field>
          <mat-form-field><mat-label>URL de saldo</mat-label><input matInput name="bal" type="url" [(ngModel)]="n.balanceUrl" required autocomplete="off" /><mat-hint>https y dominio público</mat-hint></mat-form-field>
          <button mat-flat-button type="submit" [disabled]="busy()">Crear host</button>
        </form>
        @if (createError()) { <p class="fieldError" role="alert" data-testid="create-error">{{ createError() }}</p> }
      </mat-card-content>
    </mat-card>

    <div role="status" aria-live="polite">
      @if (message()) { <p class="ok" data-testid="message">{{ message() }}</p> }
      @if (fail()) { <div class="gp-banner error" role="alert" data-testid="fail">{{ fail() }}</div> }
    </div>

    @if (error()) {
      <app-error-state [message]="error()!" (retry)="load()" />
    } @else if (hosts() === null) {
      <p class="gp-muted" role="status">Cargando…</p>
    } @else {
      @if (hosts()!.length === 0) { <p class="gp-muted" data-testid="empty">Todavía no hay hosts. Crea el primero con el formulario de arriba.</p> }
      <div class="table-wrap" tabindex="0" role="region" aria-label="Tabla desplazable">
        <table>
          <caption class="gp-visually-hidden">Hosts</caption>
          <thead><tr><th scope="col">Emisor</th><th scope="col">URL de avisos</th><th scope="col">URL de saldo</th><th scope="col">Estado</th><th scope="col">Secreto anterior</th><th scope="col">Acciones</th></tr></thead>
          <tbody>
            @for (h of hosts(); track h.id) {
              <tr data-testid="host-row">
                <td><code>{{ h.issuer }}</code></td>
                <td class="url">{{ h.callbackUrl }}</td>
                <td class="url">{{ h.balanceUrl }}</td>
                <td>{{ h.active ? 'Activo' : 'Desactivado' }}</td>
                <td>{{ h.hasPreviousSecret ? 'Vigente' : 'No hay' }}</td>
                <td>
                  <div class="acts">
                    <button mat-button type="button" (click)="ask({ host: h, kind: 'rotate' })">Rotar secreto<span class="gp-visually-hidden"> de {{ h.issuer }}</span></button>
                    @if (h.hasPreviousSecret) { <button mat-button type="button" (click)="ask({ host: h, kind: 'retire' })">Retirar secreto anterior<span class="gp-visually-hidden"> de {{ h.issuer }}</span></button> }
                    <button mat-button type="button" (click)="ask({ host: h, kind: 'toggle' })">{{ h.active ? 'Desactivar' : 'Activar' }}<span class="gp-visually-hidden"> {{ h.issuer }}</span></button>
                  </div>
                  @if (pending(); as p) {
                    @if (p.host.id === h.id) {
                      <div class="confirm" role="alertdialog" aria-label="Confirmar acción" data-testid="confirm">
                        <p>{{ consequence[p.kind] }}</p>
                        <button mat-flat-button type="button" (click)="confirm()" [disabled]="busy()" data-testid="confirm-yes">Sí, continuar</button>
                        <button mat-button type="button" (click)="pending.set(null)">Cancelar</button>
                      </div>
                    }
                  }
                </td>
              </tr>
            }
          </tbody>
        </table>
      </div>
    }
  `,
})
export class HostsScreen implements OnInit {
  private readonly api = inject(Api);
  private readonly dialog = inject(MatDialog);
  protected readonly hosts = signal<Host[] | null>(null);
  protected readonly error = signal<string | null>(null);
  protected readonly createError = signal<string | null>(null);
  protected readonly message = signal<string | null>(null);
  protected readonly fail = signal<string | null>(null);
  protected readonly busy = signal(false);
  protected readonly pending = signal<Pending | null>(null);
  protected readonly consequence = CONSEQUENCE;
  protected n = { issuer: '', callbackUrl: '', balanceUrl: '' };

  ngOnInit(): void {
    void this.load();
  }

  protected ask(p: Pending): void {
    this.message.set(null);
    this.fail.set(null);
    this.pending.set(p);
  }

  protected async load(): Promise<void> {
    this.error.set(null);
    try {
      this.hosts.set((await this.api.get<{ hosts: Host[] }>('/hosts')).hosts);
    } catch (e) {
      this.error.set(e instanceof ApiError ? e.message : 'No se pudieron cargar los hosts.');
    }
  }

  private secret(issuer: string, secret: string): void {
    this.dialog.open(SecretDialog, {
      ...SECRET_DIALOG_CONFIG,
      data: { title: `Secreto de ${issuer}`, description: 'Entrégalo al host por un canal seguro: con él firma y verifica los mensajes.', secret, filename: `secreto-${issuer}.txt` },
    });
  }

  protected async create(): Promise<void> {
    this.busy.set(true);
    this.createError.set(null);
    try {
      const r = await this.api.post<{ id: number; secret: string }>('/hosts', this.n);
      this.secret(this.n.issuer, r.secret);
      this.n = { issuer: '', callbackUrl: '', balanceUrl: '' };
      await this.load();
    } catch (e) {
      this.createError.set(e instanceof ApiError ? e.message : 'No se pudo crear el host.');
    } finally {
      this.busy.set(false);
    }
  }

  protected async confirm(): Promise<void> {
    const p = this.pending();
    if (!p) return;
    this.busy.set(true);
    this.fail.set(null);
    try {
      const id = p.host.id;
      if (p.kind === 'rotate') {
        const r = await this.api.post<{ secret: string }>(`/hosts/${id}/rotate-secret`);
        this.secret(p.host.issuer, r.secret);
        this.message.set(`Secreto de ${p.host.issuer} rotado: hay dos secretos vigentes.`);
      } else if (p.kind === 'retire') {
        await this.api.post(`/hosts/${id}/retire-previous-secret`);
        this.message.set(`Secreto anterior de ${p.host.issuer} retirado.`);
      } else {
        await this.api.patch(`/hosts/${id}`, { active: !p.host.active });
        this.message.set(`${p.host.issuer} ${p.host.active ? 'desactivado' : 'activado'}.`);
      }
    } catch (e) {
      this.fail.set(e instanceof ApiError ? e.message : 'No se pudo completar la acción.');
    } finally {
      this.pending.set(null);
      this.busy.set(false);
      await this.load();
    }
  }
}
