import { Component, OnDestroy, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router } from '@angular/router';
import { MatButtonModule } from '@angular/material/button';
import { MatCardModule } from '@angular/material/card';
import { MatCheckboxModule } from '@angular/material/checkbox';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { toDataURL } from 'qrcode';
import { ApiError } from '../../core/api';
import { Auth } from '../../core/auth';
import { LoginStep } from '../../core/models';

type Step = 'credentials' | 'password' | 'enroll' | 'recovery' | 'mfa';

/**
 * Acceso por pasos (ux-spec §4). El servidor dice cuál sigue (`next`); aquí solo se dibuja. Un solo mensaje para cualquier fallo de credenciales:
 * nunca se distingue cuenta bloqueada, desactivada o inexistente.
 */
@Component({
  selector: 'app-login',
  imports: [FormsModule, MatCardModule, MatFormFieldModule, MatInputModule, MatButtonModule, MatCheckboxModule],
  styles: `
    :host { display: grid; place-items: center; min-height: 100%; padding: 24px; }
    mat-card { width: 100%; max-width: 440px; padding: 8px; }
    form { display: flex; flex-direction: column; gap: 8px; }
    .qr { align-self: center; width: 200px; height: 200px; }
    code.secret { word-break: break-all; background: var(--gp-background); padding: 8px; border-radius: 8px; }
    ul.codes { columns: 2; list-style: none; padding: 0; font-family: ui-monospace, monospace; }
    .actions { display: flex; gap: 8px; flex-wrap: wrap; }
  `,
  template: `
    <mat-card>
      <mat-card-header>
        <mat-card-title>Acceso al panel</mat-card-title>
        <mat-card-subtitle data-testid="step-label">Paso {{ stepNo() }} de 4 · {{ stepTitle() }}</mat-card-subtitle>
      </mat-card-header>
      <mat-card-content>
        @if (expired()) {
          <div class="gp-banner warning" role="status" data-testid="expired">Tu sesión venció. Entra de nuevo para continuar donde estabas.</div>
        }
        @if (error()) {
          <div class="gp-banner error" role="alert" data-testid="error">
            {{ error() }}@if (wait() > 0) { Espera {{ wait() }} s. }
          </div>
        }

        @switch (step()) {
          @case ('credentials') {
            <form (ngSubmit)="submitCredentials()" aria-label="Formulario de acceso">
              <mat-form-field>
                <mat-label>Correo</mat-label>
                <input matInput name="email" type="email" autocomplete="username" required [(ngModel)]="email" />
              </mat-form-field>
              <mat-form-field>
                <mat-label>Contraseña</mat-label>
                <input matInput name="password" type="password" autocomplete="current-password" required [(ngModel)]="password" />
              </mat-form-field>
              <button mat-flat-button type="submit" [disabled]="busy() || wait() > 0">Entrar</button>
            </form>
          }
          @case ('password') {
            <p class="gp-muted">Debes cambiar tu contraseña. Mínimo 12 caracteres, distinta de tu correo y no común.</p>
            <form (ngSubmit)="submitPassword()" aria-label="Formulario de nueva contraseña">
              <mat-form-field>
                <mat-label>Contraseña actual</mat-label>
                <input matInput name="current" type="password" autocomplete="current-password" required [(ngModel)]="password" />
              </mat-form-field>
              <mat-form-field>
                <mat-label>Contraseña nueva</mat-label>
                <input matInput name="new" type="password" autocomplete="new-password" required [(ngModel)]="newPassword" />
              </mat-form-field>
              <mat-form-field>
                <mat-label>Repite la contraseña nueva</mat-label>
                <input matInput name="repeat" type="password" autocomplete="new-password" required [(ngModel)]="repeat" />
              </mat-form-field>
              <button mat-flat-button type="submit" [disabled]="busy()">Cambiar contraseña</button>
            </form>
          }
          @case ('enroll') {
            <p>Escanea el código con tu app de autenticación o escribe la clave a mano.</p>
            @if (qr()) {
              <img class="qr" [src]="qr()" alt="Código QR para tu app de autenticación" />
            }
            <p>Clave: <code class="secret" data-testid="enroll-secret">{{ secret() }}</code></p>
            <form (ngSubmit)="submitEnroll()" aria-label="Formulario de confirmación">
              <mat-form-field>
                <mat-label>Código de 6 dígitos</mat-label>
                <input matInput name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required [(ngModel)]="code" />
              </mat-form-field>
              <button mat-flat-button type="submit" [disabled]="busy()">Confirmar</button>
            </form>
          }
          @case ('recovery') {
            <div class="gp-banner warning" role="note">Estos códigos no se volverán a mostrar. Cada uno sirve una sola vez si pierdes tu app.</div>
            <ul class="codes" data-testid="recovery-codes">
              @for (c of recovery(); track c) {
                <li>{{ c }}</li>
              }
            </ul>
            <div class="actions">
              <button mat-stroked-button type="button" (click)="copyCodes()">Copiar</button>
              <button mat-stroked-button type="button" (click)="downloadCodes()">Descargar .txt</button>
            </div>
            <mat-checkbox name="saved" [(ngModel)]="saved">Los guardé en un lugar seguro</mat-checkbox>
            <button mat-flat-button type="button" [disabled]="!saved" (click)="finish()">Continuar</button>
          }
          @case ('mfa') {
            <form (ngSubmit)="submitMfa()" aria-label="Formulario de verificación">
              <mat-form-field>
                <mat-label>{{ useRecovery() ? 'Código de recuperación' : 'Código de 6 dígitos' }}</mat-label>
                <input matInput name="code" [attr.inputmode]="useRecovery() ? 'text' : 'numeric'" autocomplete="one-time-code" required [(ngModel)]="code" />
              </mat-form-field>
              <button mat-flat-button type="submit" [disabled]="busy() || wait() > 0">Verificar</button>
              <button mat-button type="button" (click)="toggleRecovery()">
                {{ useRecovery() ? 'Usar el código de mi app' : 'Usar un código de recuperación' }}
              </button>
              @if (useRecovery()) {
                <p class="gp-muted">Cada código de recuperación sirve una sola vez.</p>
              }
            </form>
          }
        }
      </mat-card-content>
    </mat-card>
  `,
})
export class Login implements OnDestroy {
  private readonly auth = inject(Auth);
  private readonly router = inject(Router);
  private readonly route = inject(ActivatedRoute);

  protected readonly step = signal<Step>('credentials');
  protected readonly busy = signal(false);
  protected readonly error = signal('');
  protected readonly wait = signal(0);
  protected readonly secret = signal('');
  protected readonly qr = signal('');
  protected readonly recovery = signal<string[]>([]);
  protected readonly useRecovery = signal(false);
  protected readonly expired = signal(this.route.snapshot.queryParamMap.get('vencida') === '1');

  protected email = '';
  protected password = '';
  protected newPassword = '';
  protected repeat = '';
  protected code = '';
  protected saved = false;
  private countdown?: ReturnType<typeof setInterval>;

  ngOnDestroy(): void {
    clearInterval(this.countdown);
  }

  protected stepNo(): number {
    return { credentials: 1, password: 2, enroll: 3, recovery: 3, mfa: 4 }[this.step()];
  }

  protected stepTitle(): string {
    return {
      credentials: 'Correo y contraseña',
      password: 'Nueva contraseña',
      enroll: 'Segundo factor',
      recovery: 'Códigos de recuperación',
      mfa: 'Código de verificación',
    }[this.step()];
  }

  protected submitCredentials(): Promise<void> {
    return this.run(async () => this.goto(await this.auth.login(this.email, this.password)));
  }

  protected submitPassword(): Promise<void> {
    if (this.newPassword !== this.repeat) {
      this.error.set('Las contraseñas nuevas no coinciden.');
      return Promise.resolve();
    }
    return this.run(async () => {
      this.goto(await this.auth.changePassword(this.password, this.newPassword));
      this.password = this.newPassword = this.repeat = '';
    });
  }

  protected submitEnroll(): Promise<void> {
    return this.run(async () => {
      this.recovery.set(await this.auth.enrollConfirm(this.code.trim()));
      this.code = '';
      this.secret.set('');
      this.qr.set('');
      this.step.set('recovery');
    });
  }

  protected submitMfa(): Promise<void> {
    return this.run(async () => {
      await this.auth.mfa(this.code.trim());
      this.code = '';
      await this.finish();
    });
  }

  protected toggleRecovery(): void {
    this.useRecovery.update((v) => !v);
    this.code = '';
  }

  protected async copyCodes(): Promise<void> {
    await navigator.clipboard?.writeText(this.recovery().join('\n'));
  }

  protected downloadCodes(): void {
    const url = URL.createObjectURL(new Blob([this.recovery().join('\n') + '\n'], { type: 'text/plain' }));
    const a = document.createElement('a');
    a.href = url;
    a.download = 'codigos-de-recuperacion.txt';
    a.click();
    URL.revokeObjectURL(url);
  }

  /** La sesión ya está completa: se carga el usuario y se vuelve a donde iba (solo rutas internas). */
  protected async finish(): Promise<void> {
    this.recovery.set([]);
    await this.auth.load();
    const back = this.route.snapshot.queryParamMap.get('volver') ?? '/';
    await this.router.navigateByUrl(back.startsWith('/') && !back.startsWith('//') ? back : '/');
  }

  private goto(next: LoginStep): void {
    if (next === 'enroll') {
      this.step.set('enroll');
      void this.startEnroll();
    } else {
      this.step.set(next);
    }
  }

  private async startEnroll(): Promise<void> {
    await this.run(async () => {
      const e = await this.auth.enrollStart();
      this.secret.set(e.secret);
      this.qr.set(await toDataURL(e.uri, { margin: 1, width: 200 })); // local: el secreto no sale a ningún servicio
    });
  }

  private async run(action: () => Promise<void>): Promise<void> {
    this.busy.set(true);
    this.error.set('');
    try {
      await action();
    } catch (e) {
      if (e instanceof ApiError) {
        this.error.set(e.message);
        if (e.status === 429 && e.retryAfter) this.startCountdown(e.retryAfter);
      } else {
        this.error.set('Error inesperado.');
      }
    } finally {
      this.busy.set(false);
    }
  }

  private startCountdown(seconds: number): void {
    clearInterval(this.countdown);
    this.wait.set(seconds);
    this.countdown = setInterval(() => {
      this.wait.update((w) => Math.max(0, w - 1));
      if (this.wait() === 0) clearInterval(this.countdown);
    }, 1000);
  }
}
