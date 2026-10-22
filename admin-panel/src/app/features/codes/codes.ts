import { Component, OnDestroy, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { MatButtonModule } from '@angular/material/button';
import { MatCardModule } from '@angular/material/card';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { Api, ApiError } from '../../core/api';
import { dateBogota, dateUtc } from '../../core/format';
import { CodeLookup } from '../../core/models';

const STATE: Record<string, string> = { draft: 'Borrador', committed: 'Comprometido', active: 'Activo', closed: 'Cerrado', revealed: 'Revelado' };

/**
 * Buscar código (RF-37). Se envía por POST y nunca va a la URL ni al historial. Un código sin canjear NO muestra premio: ni el servidor lo
 * entrega ni la pantalla lo infiere. El texto escrito se borra al recibir el resultado.
 */
@Component({
  selector: 'app-codes',
  imports: [FormsModule, RouterLink, MatCardModule, MatFormFieldModule, MatInputModule, MatButtonModule],
  styles: `
    mat-card { max-width: 640px; padding: 4px; margin-bottom: 16px; }
    form { display: flex; gap: 12px; align-items: flex-start; flex-wrap: wrap; }
    form mat-form-field { flex: 1; min-width: 260px; }
    dl { display: grid; grid-template-columns: 160px 1fr; gap: 6px 12px; margin: 0; }
    dt { color: var(--gp-muted); }
    dd { margin: 0; }
  `,
  template: `
    <h1>Buscar código</h1>
    <p class="gp-muted">Escríbelo como te lo den: se aceptan minúsculas, espacios o guiones. Cada búsqueda queda registrada en la bitácora.</p>
    <mat-card>
      <mat-card-content>
        <form (ngSubmit)="search()" aria-label="Búsqueda de código" autocomplete="off">
          <mat-form-field>
            <mat-label>Código</mat-label>
            <input matInput name="code" [(ngModel)]="text" autocomplete="off" autocapitalize="off" spellcheck="false" maxlength="64" required />
          </mat-form-field>
          <button mat-flat-button type="submit" [disabled]="busy() || wait() > 0 || !text.trim()">Buscar</button>
        </form>
        <div role="status" aria-live="polite" data-testid="lookup-status">
          @if (error()) { <div class="gp-banner error" data-testid="lookup-error">{{ error() }}@if (wait() > 0) { Espera {{ wait() }} s. }</div> }
        </div>
      </mat-card-content>
    </mat-card>

    @if (result(); as r) {
      <mat-card data-testid="lookup-result">
        <mat-card-content>
          <dl>
            <dt>Lote</dt><dd><a [routerLink]="['/lotes']" [queryParams]="{ lote: r.batchId }">{{ r.batchName }}</a></dd>
            <dt>Estado del lote</dt><dd>{{ state[r.batchState] }}</dd>
            <dt>Posición</dt><dd>{{ r.index }}</dd>
            <dt>Canjeado</dt>
            <dd>
              @if (r.redeemed) { Sí, <span [title]="utc(r.redeemedAt)">{{ bogota(r.redeemedAt) }}</span> por <code>{{ r.redeemedBy }}</code> }
              @else { No }
            </dd>
            <dt>Premio</dt>
            <dd data-testid="lookup-prize">{{ r.redeemed ? r.prizeId : 'Premio oculto hasta revelar el lote' }}</dd>
          </dl>
        </mat-card-content>
      </mat-card>
    }
  `,
})
export class CodesScreen implements OnDestroy {
  private readonly api = inject(Api);
  protected text = '';
  protected readonly result = signal<CodeLookup | null>(null);
  protected readonly error = signal<string | null>(null);
  protected readonly busy = signal(false);
  protected readonly wait = signal(0);
  protected readonly state = STATE;
  protected readonly bogota = dateBogota;
  protected readonly utc = dateUtc;
  private timer?: ReturnType<typeof setInterval>;

  ngOnDestroy(): void {
    clearInterval(this.timer);
  }

  protected async search(): Promise<void> {
    this.busy.set(true);
    this.error.set(null);
    this.result.set(null);
    try {
      this.result.set(await this.api.post<CodeLookup>('/codes/lookup', { code: this.text }));
      this.text = '';
    } catch (e) {
      if (e instanceof ApiError && e.status === 429) {
        this.error.set('Demasiadas búsquedas seguidas.');
        this.countdown(e.retryAfter ?? 30);
      } else {
        this.error.set(e instanceof ApiError ? e.message : 'No se pudo buscar el código.');
      }
    } finally {
      this.busy.set(false);
    }
  }

  private countdown(seconds: number): void {
    clearInterval(this.timer);
    this.wait.set(seconds);
    this.timer = setInterval(() => {
      this.wait.update((w) => Math.max(0, w - 1));
      if (this.wait() === 0) clearInterval(this.timer);
    }, 1000);
  }
}
