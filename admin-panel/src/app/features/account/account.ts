import { Component, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatCardModule } from '@angular/material/card';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { ApiError } from '../../core/api';
import { Auth } from '../../core/auth';

const ROLE_LABEL: Record<string, string> = { superadmin: 'Superadmin', operator: 'Operador', auditor: 'Auditor' };

/** Mi cuenta (ux-spec §4): nombre y rol en lectura y cambio de contraseña. Cambiarla cierra las demás sesiones, y se dice antes. */
@Component({
  selector: 'app-account',
  imports: [FormsModule, MatCardModule, MatButtonModule, MatFormFieldModule, MatInputModule],
  styles: `
    mat-card { padding: 4px; margin-bottom: 16px; max-width: 520px; }
    dl { display: grid; grid-template-columns: 120px 1fr; gap: 6px 12px; margin: 0; }
    dt { color: var(--gp-muted); }
    dd { margin: 0; }
    form { display: flex; flex-direction: column; }
    .fieldError { color: var(--gp-error); font-size: 13px; margin: 0 0 8px; }
    .ok { color: var(--gp-success); font-weight: 600; }
  `,
  template: `
    <h1>Mi cuenta</h1>
    <mat-card>
      <mat-card-content>
        <dl>
          <dt>Nombre</dt><dd data-testid="account-name">{{ me()?.name }}</dd>
          <dt>Correo</dt><dd data-testid="account-email">{{ me()?.email }}</dd>
          <dt>Rol</dt><dd data-testid="account-role">{{ role() }}</dd>
        </dl>
      </mat-card-content>
    </mat-card>
    <mat-card>
      <mat-card-content>
        <h2>Cambiar contraseña</h2>
        <p class="gp-muted">Mínimo 12 caracteres, distinta de tu correo y no común. Al cambiarla se cierran tus sesiones en otros dispositivos; esta sigue abierta.</p>
        <form (ngSubmit)="submit()" aria-label="Cambiar contraseña">
          <mat-form-field>
            <mat-label>Contraseña actual</mat-label>
            <input matInput name="current" type="password" autocomplete="current-password" required [(ngModel)]="current" />
          </mat-form-field>
          <mat-form-field>
            <mat-label>Contraseña nueva</mat-label>
            <input matInput name="new" type="password" autocomplete="new-password" required [(ngModel)]="next" />
          </mat-form-field>
          <mat-form-field>
            <mat-label>Repite la contraseña nueva</mat-label>
            <input matInput name="repeat" type="password" autocomplete="new-password" required [(ngModel)]="repeat" />
          </mat-form-field>
          @if (error()) { <p class="fieldError" role="alert" data-testid="error">{{ error() }}</p> }
          @if (done()) { <p class="ok" role="status" data-testid="done">Contraseña cambiada. Se cerraron tus otras sesiones.</p> }
          <div><button mat-flat-button type="submit" [disabled]="busy()">Cambiar contraseña</button></div>
        </form>
      </mat-card-content>
    </mat-card>
  `,
})
export class AccountPage {
  private readonly auth = inject(Auth);

  protected readonly me = this.auth.me;
  protected readonly role = computed(() => ROLE_LABEL[this.me()?.role ?? ''] ?? '');
  protected readonly busy = signal(false);
  protected readonly error = signal('');
  protected readonly done = signal(false);
  protected current = '';
  protected next = '';
  protected repeat = '';

  protected async submit(): Promise<void> {
    this.error.set('');
    this.done.set(false);
    if (this.next !== this.repeat) {
      this.error.set('Las contraseñas nuevas no coinciden.');
      return;
    }
    this.busy.set(true);
    try {
      await this.auth.changeOwnPassword(this.current, this.next);
      this.current = this.next = this.repeat = '';
      this.done.set(true);
    } catch (e) {
      this.error.set(e instanceof ApiError ? e.message : 'No se pudo cambiar la contraseña. Inténtalo de nuevo.');
    } finally {
      this.busy.set(false);
    }
  }
}
