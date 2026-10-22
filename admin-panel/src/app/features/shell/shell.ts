import { Component, OnDestroy, OnInit, computed, inject, signal } from '@angular/core';
import { Router, RouterLink, RouterLinkActive, RouterOutlet } from '@angular/router';
import { MatButtonModule } from '@angular/material/button';
import { MatIconModule } from '@angular/material/icon';
import { MatListModule } from '@angular/material/list';
import { MatSidenavModule } from '@angular/material/sidenav';
import { MatToolbarModule } from '@angular/material/toolbar';
import { Auth } from '../../core/auth';

interface Entry {
  label: string;
  path: string;
  permission: string;
}

/** Menú de docs/ux-spec-003-panel.md §3: cada entrada solo se dibuja si el rol tiene el permiso (el servidor igual lo impone). */
const MENU: Entry[] = [
  { label: 'Resumen', path: '/', permission: 'dashboard.view' },
  { label: 'Campañas', path: '/campanas', permission: 'campaigns.view' },
  { label: 'Lotes', path: '/lotes', permission: 'batches.view' },
  { label: 'Aprobaciones', path: '/aprobaciones', permission: 'approvals.view' },
  { label: 'Avisos al host', path: '/avisos', permission: 'outbox.view' },
  { label: 'Buscar código', path: '/codigos', permission: 'batches.view' },
  { label: 'Bitácora', path: '/auditoria', permission: 'audit.view' },
  { label: 'Hosts', path: '/hosts', permission: 'hosts.manage' },
  { label: 'Parámetros', path: '/parametros', permission: 'settings.manage' },
  { label: 'Usuarios', path: '/usuarios', permission: 'users.manage' },
  { label: 'Mi cuenta', path: '/cuenta', permission: 'account.self' },
];

const ROLE_LABEL: Record<string, string> = { superadmin: 'Superadmin', operator: 'Operador', auditor: 'Auditor' };

/** Aviso previo al vencimiento por inactividad (ux-spec §3): 2 minutos antes. */
const WARN_SECONDS = 120;

@Component({
  selector: 'app-shell',
  imports: [RouterOutlet, RouterLink, RouterLinkActive, MatSidenavModule, MatToolbarModule, MatListModule, MatButtonModule, MatIconModule],
  styles: `
    :host { display: block; height: 100%; }
    mat-sidenav-container { height: 100%; background: var(--gp-background); }
    mat-sidenav { width: 232px; background: var(--gp-surface); }
    .brand { padding: 16px; font-weight: 700; color: var(--gp-navy); }
    main { padding: 24px; max-width: 1200px; }
    .who { margin-left: auto; display: flex; align-items: center; gap: 12px; }
    .gp-banner { margin: 16px 24px 0; }
  `,
  template: `
    <mat-sidenav-container>
      <mat-sidenav mode="side" opened role="navigation" aria-label="Secciones del panel">
        <div class="brand">Gamificación · Panel</div>
        <mat-nav-list>
          @for (e of menu(); track e.path) {
            <a mat-list-item [routerLink]="e.path" routerLinkActive="active" [routerLinkActiveOptions]="{ exact: e.path === '/' }" #rla="routerLinkActive" [attr.aria-current]="rla.isActive ? 'page' : null" [activated]="rla.isActive">
              {{ e.label }}
            </a>
          }
        </mat-nav-list>
      </mat-sidenav>
      <mat-sidenav-content>
        <mat-toolbar>
          <span class="who">
            <span data-testid="who">{{ auth.me()?.name }} · {{ role() }}</span>
            <button mat-stroked-button type="button" (click)="logout()">Cerrar sesión</button>
          </span>
        </mat-toolbar>
        @if (remaining() !== null) {
          <div class="gp-banner warning" role="alert" data-testid="idle-warning">
            <span>Tu sesión vence por inactividad en {{ remaining() }} s.</span>
            <button mat-button type="button" (click)="keepAlive()">Seguir conectado</button>
          </div>
        }
        <main><router-outlet /></main>
      </mat-sidenav-content>
    </mat-sidenav-container>
  `,
})
export class Shell implements OnInit, OnDestroy {
  protected readonly auth = inject(Auth);
  private readonly router = inject(Router);
  private timer?: ReturnType<typeof setInterval>;

  protected readonly menu = computed(() => MENU.filter((e) => this.auth.can(e.permission)));
  protected readonly role = computed(() => ROLE_LABEL[this.auth.me()?.role ?? ''] ?? '');
  /** Segundos que faltan para el vencimiento por inactividad, solo dentro de la ventana de aviso. */
  protected readonly remaining = signal<number | null>(null);

  ngOnInit(): void {
    this.timer = setInterval(() => this.tick(), 1000);
  }

  ngOnDestroy(): void {
    clearInterval(this.timer);
  }

  private tick(): void {
    const me = this.auth.me();
    if (!me) return;
    const left = Math.ceil(me.session.idleMinutes * 60 - (Date.now() - this.auth.lastActivity()) / 1000);
    if (left <= 0) {
      this.remaining.set(null);
      this.auth.me.set(null);
      void this.router.navigate(['/acceso'], { queryParams: { volver: this.router.url, vencida: 1 } });
    } else {
      this.remaining.set(left <= WARN_SECONDS ? left : null);
    }
  }

  protected async keepAlive(): Promise<void> {
    await this.auth.load();
    this.remaining.set(null);
  }

  protected async logout(): Promise<void> {
    await this.auth.logout();
    await this.router.navigate(['/acceso']);
  }
}
