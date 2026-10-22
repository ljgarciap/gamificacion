import { Injectable, computed, inject, signal } from '@angular/core';
import { Api, ApiError } from './api';
import { LoginStep, Me, MeResponse } from './models';

/** Estado de la sesión del panel y los pasos del inicio de sesión (docs/admin-api-v1.md). El servidor decide el paso siguiente (`next`). */
@Injectable({ providedIn: 'root' })
export class Auth {
  private readonly api = inject(Api);

  readonly me = signal<MeResponse | null>(null);
  readonly permissions = computed(() => new Set(this.me()?.permissions ?? []));
  /** Marca de la última actividad con el servidor, para avisar antes de que venza la sesión por inactividad. */
  readonly lastActivity = signal(Date.now());

  can(permission: string): boolean {
    return this.permissions().has(permission);
  }

  /** Carga la sesión vigente; null si no hay sesión completa. */
  async load(): Promise<MeResponse | null> {
    try {
      const me = await this.api.get<MeResponse>('/auth/me');
      this.me.set(me);
      this.touch();
      return me;
    } catch (e) {
      if (e instanceof ApiError && (e.status === 401 || e.status === 419)) {
        this.me.set(null);
        return null;
      }
      throw e;
    }
  }

  /** Marca actividad (cada respuesta del servidor renueva la sesión). */
  touch(): void {
    this.lastActivity.set(Date.now());
  }

  async csrf(): Promise<void> {
    await this.api.get<void>('/auth/csrf');
  }

  async login(email: string, password: string): Promise<LoginStep> {
    await this.csrf();
    return (await this.api.post<{ next: LoginStep }>('/auth/login', { email, password })).next;
  }

  async changePassword(current: string, next: string): Promise<LoginStep> {
    return (await this.api.post<{ next: LoginStep }>('/auth/password', { current, new: next })).next;
  }

  enrollStart(): Promise<{ secret: string; uri: string }> {
    return this.api.post('/auth/mfa/enroll');
  }

  async enrollConfirm(code: string): Promise<string[]> {
    const r = await this.api.post<{ recoveryCodes: string[]; user: Me }>('/auth/mfa/enroll/confirm', { code });
    return r.recoveryCodes;
  }

  async mfa(code: string): Promise<void> {
    await this.api.post('/auth/mfa', { code });
  }

  async logout(): Promise<void> {
    try {
      await this.api.post('/auth/logout');
    } finally {
      this.me.set(null);
    }
  }
}
