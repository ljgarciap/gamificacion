import { Component, input, output } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';

/** Estado de error con reintento (ux-spec §1.5): nada de pantallas en blanco. */
@Component({
  selector: 'app-error-state',
  imports: [MatButtonModule],
  template: `
    <div class="gp-banner error" role="alert" data-testid="load-error">
      <span>{{ message() }}</span>
      <button mat-button type="button" (click)="retry.emit()">Reintentar</button>
    </div>
  `,
})
export class ErrorState {
  readonly message = input.required<string>();
  readonly retry = output<void>();
}
