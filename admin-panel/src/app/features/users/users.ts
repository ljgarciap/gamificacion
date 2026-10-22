import { Component, OnInit, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatCardModule } from '@angular/material/card';
import { MatDialog } from '@angular/material/dialog';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { MatSelectModule } from '@angular/material/select';
import { Api, ApiError } from '../../core/api';
import { Auth } from '../../core/auth';
import { dateBogota, dateUtc } from '../../core/format';
import { AdminUser, Role } from '../../core/models';
import { SECRET_DIALOG_CONFIG, SecretDialog } from '../../shared/secret-dialog';
import { ErrorState } from '../../shared/state';

const ROLES: [Role, string][] = [['superadmin', 'Superadmin'], ['operator', 'Operador'], ['auditor', 'Auditor']];
type Pending = { user: AdminUser; kind: 'deactivate' | 'activate' | 'reset-mfa' | 'reset-password' | 'role'; role?: Role };

const CONSEQUENCE: Record<Pending['kind'], string> = {
  deactivate: 'Se cerrarán todas sus sesiones y no podrá entrar hasta que lo actives de nuevo.',
  activate: 'Podrá volver a entrar con su contraseña y su segundo factor.',
  'reset-mfa': 'Se quitará su segundo factor y sus códigos de recuperación; tendrá que enrolarse de nuevo al entrar.',
  'reset-password': 'Se le dará una contraseña temporal (se muestra una sola vez), se levantará su bloqueo y se cerrarán sus sesiones.',
  role: 'Se cerrarán todas sus sesiones para que el nuevo rol aplique desde cero.',
};

/** Usuarios (RF-41, solo superadmin). Ninguna contraseña temporal queda en pantalla: solo en el diálogo de una vez. */
@Component({
  selector: 'app-users',
  imports: [FormsModule, MatCardModule, MatButtonModule, MatFormFieldModule, MatInputModule, MatSelectModule, ErrorState],
  styles: `
    mat-card { padding: 4px; margin-bottom: 16px; }
    .inline { display: flex; gap: 12px; flex-wrap: wrap; align-items: flex-start; }
    .inline mat-form-field { width: 240px; }
    .role-field { width: 150px; }
    table { width: 100%; min-width: 980px; border-collapse: collapse; font-size: 13px; background: var(--gp-surface); }
    th, td { text-align: left; padding: 6px 8px; border-bottom: 1px solid var(--gp-background); vertical-align: top; }
    th { color: var(--gp-muted); font-weight: 600; }
    .table-wrap { overflow-x: auto; }
    .acts { display: flex; gap: 4px; flex-wrap: wrap; }
    .confirm { background: var(--gp-danger-surface); padding: 8px 12px; border-radius: 8px; margin: 8px 0; }
    .fieldError { color: var(--gp-error); font-size: 12px; margin: 0 0 8px; }
    .ok { color: var(--gp-success); font-weight: 600; }
  `,
  template: `
    <h1>Usuarios</h1>
    <mat-card>
      <mat-card-content>
        <form class="inline" (ngSubmit)="create()" aria-label="Alta de usuario">
          <mat-form-field><mat-label>Correo</mat-label><input matInput name="email" type="email" [(ngModel)]="n.email" required autocomplete="off" /></mat-form-field>
          <mat-form-field><mat-label>Nombre</mat-label><input matInput name="name" [(ngModel)]="n.name" required autocomplete="off" /></mat-form-field>
          <mat-form-field><mat-label>Rol</mat-label><mat-select name="role" [(ngModel)]="n.role">@for (r of roles; track r[0]) { <mat-option [value]="r[0]">{{ r[1] }}</mat-option> }</mat-select></mat-form-field>
          <button mat-flat-button type="submit" [disabled]="busy()">Crear usuario</button>
        </form>
        @if (createError()) { <p class="fieldError" role="alert" data-testid="create-error">{{ createError() }}</p> }
        <p class="gp-muted">Se le dará una contraseña temporal que se muestra una sola vez; al entrar deberá cambiarla y enrolar su segundo factor.</p>
      </mat-card-content>
    </mat-card>

    <div role="status" aria-live="polite">
      @if (message()) { <p class="ok" data-testid="message">{{ message() }}</p> }
      @if (fail()) { <div class="gp-banner error" role="alert" data-testid="fail">{{ fail() }}</div> }
    </div>

    @if (error()) {
      <app-error-state [message]="error()!" (retry)="load()" />
    } @else if (users() === null) {
      <p class="gp-muted" role="status">Cargando…</p>
    } @else {
      <div class="table-wrap" tabindex="0" role="region" aria-label="Tabla desplazable">
        <table>
          <caption class="gp-visually-hidden">Usuarios del panel</caption>
          <thead><tr><th scope="col">Correo</th><th scope="col">Nombre</th><th scope="col">Rol</th><th scope="col">Estado</th><th scope="col">2FA</th><th scope="col">Último acceso</th><th scope="col">Acciones</th></tr></thead>
          <tbody>
            @for (u of users(); track u.id) {
              <tr data-testid="user-row" [attr.data-email]="u.email">
                <td>{{ u.email }}@if (u.id === me()) { <span class="gp-muted"> (tú)</span> }</td>
                <td>{{ u.name }}</td>
                <td>{{ roleLabel(u.role) }}</td>
                <td>{{ u.active ? 'Activo' : 'Desactivado' }}@if (u.mustChangePassword) { <br /><span class="gp-muted">Debe cambiar la contraseña</span> }</td>
                <td>{{ u.mfaEnrolled ? 'Enrolado' : 'Sin enrolar' }}</td>
                <td [title]="utc(u.lastLoginAt)">{{ bogota(u.lastLoginAt) }}</td>
                <td>
                  @if (u.id === me()) {
                    <span class="gp-muted">No puedes cambiar tu propio rol ni desactivarte desde aquí.</span>
                  } @else {
                    <div class="acts">
                      <mat-form-field subscriptSizing="dynamic" class="role-field">
                        <mat-label>Rol de {{ u.email }}</mat-label>
                        <mat-select [value]="u.role" (selectionChange)="proposeRole(u, $event.value, $event.source)">@for (r of roles; track r[0]) { <mat-option [value]="r[0]">{{ r[1] }}</mat-option> }</mat-select>
                      </mat-form-field>
                      <button mat-button type="button" (click)="ask({ user: u, kind: u.active ? 'deactivate' : 'activate' })">{{ u.active ? 'Desactivar' : 'Activar' }}<span class="gp-visually-hidden"> a {{ u.email }}</span></button>
                      <button mat-button type="button" (click)="ask({ user: u, kind: 'reset-mfa' })">Restablecer 2FA<span class="gp-visually-hidden"> de {{ u.email }}</span></button>
                      <button mat-button type="button" (click)="ask({ user: u, kind: 'reset-password' })">Restablecer contraseña<span class="gp-visually-hidden"> de {{ u.email }}</span></button>
                    </div>
                  }
                  @if (pending(); as p) {
                    @if (p.user.id === u.id) {
                      <div class="confirm" role="alertdialog" aria-label="Confirmar acción" data-testid="confirm">
                        <p><strong>{{ title(p) }}</strong></p>
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
export class UsersScreen implements OnInit {
  private readonly api = inject(Api);
  private readonly auth = inject(Auth);
  private readonly dialog = inject(MatDialog);
  protected readonly users = signal<AdminUser[] | null>(null);
  protected readonly error = signal<string | null>(null);
  protected readonly createError = signal<string | null>(null);
  protected readonly message = signal<string | null>(null);
  protected readonly fail = signal<string | null>(null);
  protected readonly busy = signal(false);
  protected readonly pending = signal<Pending | null>(null);
  protected readonly roles = ROLES;
  protected readonly consequence = CONSEQUENCE;
  protected readonly bogota = dateBogota;
  protected readonly utc = dateUtc;
  protected n: { email: string; name: string; role: Role } = { email: '', name: '', role: 'operator' };
  protected readonly me = () => this.auth.me()?.id;

  ngOnInit(): void {
    void this.load();
  }

  protected roleLabel = (r: Role) => ROLES.find((x) => x[0] === r)?.[1] ?? r;

  protected title(p: Pending): string {
    const who = p.user.email;
    return { deactivate: `Desactivar a ${who}`, activate: `Activar a ${who}`, 'reset-mfa': `Restablecer el 2FA de ${who}`, 'reset-password': `Restablecer la contraseña de ${who}`, role: `Cambiar el rol de ${who} a ${this.roleLabel(p.role ?? p.user.role)}` }[p.kind];
  }

  /** El selector vuelve al rol vigente: el cambio solo se ve después de confirmar (y de que el servidor lo acepte). */
  protected proposeRole(user: AdminUser, role: Role, select: { value: Role }): void {
    select.value = user.role;
    this.ask({ user, kind: 'role', role });
  }

  protected ask(p: Pending): void {
    this.message.set(null);
    this.fail.set(null);
    this.pending.set(p.kind === 'role' && p.role === p.user.role ? null : p);
  }

  protected async load(): Promise<void> {
    this.error.set(null);
    try {
      this.users.set((await this.api.get<{ users: AdminUser[] }>('/users')).users);
    } catch (e) {
      this.error.set(e instanceof ApiError ? e.message : 'No se pudieron cargar los usuarios.');
    }
  }

  private secret(title: string, description: string, secret: string, filename: string): void {
    this.dialog.open(SecretDialog, { ...SECRET_DIALOG_CONFIG, data: { title, description, secret, filename } });
  }

  protected async create(): Promise<void> {
    this.busy.set(true);
    this.createError.set(null);
    try {
      const r = await this.api.post<{ id: number; tempPassword: string }>('/users', this.n);
      this.secret('Contraseña temporal', `Entrégasela a ${this.n.name} por un canal seguro: la tendrá que cambiar al entrar.`, r.tempPassword, 'contrasena-temporal.txt');
      this.n = { email: '', name: '', role: 'operator' };
      await this.load();
    } catch (e) {
      this.createError.set(e instanceof ApiError ? e.message : 'No se pudo crear el usuario.');
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
      const id = p.user.id;
      if (p.kind === 'reset-password') {
        const r = await this.api.post<{ tempPassword: string }>(`/users/${id}/reset-password`);
        this.secret('Contraseña temporal', `Entrégasela a ${p.user.name} por un canal seguro: la tendrá que cambiar al entrar.`, r.tempPassword, 'contrasena-temporal.txt');
      } else if (p.kind === 'reset-mfa') {
        await this.api.post(`/users/${id}/reset-mfa`);
      } else {
        await this.api.patch(`/users/${id}`, p.kind === 'role' ? { role: p.role } : { active: p.kind === 'activate' });
      }
      this.message.set(`${this.title(p)}: hecho.`);
      this.pending.set(null);
    } catch (e) {
      this.fail.set(e instanceof ApiError ? e.message : 'No se pudo completar la acción.');
      this.pending.set(null);
    } finally {
      this.busy.set(false);
      await this.load();
    }
  }
}
