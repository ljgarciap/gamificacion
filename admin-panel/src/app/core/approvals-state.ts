import { Injectable, computed, inject, signal } from '@angular/core';
import { Api } from './api';
import { Auth } from './auth';
import { Approval } from './models';

/** Lo que el shell necesita saber del control dual: el aviso permanente de «una sola persona puede aprobar» (RF-45) y la insignia de pendientes. */
@Injectable({ providedIn: 'root' })
export class ApprovalsState {
  private readonly api = inject(Api);
  private readonly auth = inject(Auth);
  readonly fewApprovers = signal(false);
  readonly awaitingMe = signal(0);
  readonly hasBadge = computed(() => this.awaitingMe() > 0);

  async refresh(): Promise<Approval[] | null> {
    if (!this.auth.can('approvals.view')) return null;
    try {
      const r = await this.api.get<{ approvals: Approval[]; fewApprovers: boolean }>('/approvals');
      this.update(r.approvals, r.fewApprovers);
      return r.approvals;
    } catch {
      return null;
    }
  }

  update(approvals: Approval[], few: boolean): void {
    this.fewApprovers.set(few);
    const me = this.auth.me()?.id;
    this.awaitingMe.set(this.auth.can('approvals.approve') ? approvals.filter((a) => a.status === 'pending' && a.requestedBy.id !== me).length : 0);
  }
}
