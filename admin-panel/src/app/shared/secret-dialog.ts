import { Component, inject } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { MAT_DIALOG_DATA, MatDialogModule, MatDialogRef } from '@angular/material/dialog';
import { MatButtonModule } from '@angular/material/button';
import { MatCheckboxModule } from '@angular/material/checkbox';

export interface SecretDialogData {
  title: string;
  /** Qué es y cómo se usa; siempre se avisa que no se volverá a mostrar. */
  description: string;
  secret: string;
  filename: string;
}

/**
 * Diálogo de secreto de una vez (ux-spec §1.2, UA-4): el secreto vive solo en los datos del diálogo (memoria), no se puede cerrar sin marcar
 * «Ya lo guardé» ni con Esc o clic fuera, y al cerrarse no queda en ningún estado de la pantalla que lo abrió.
 */
@Component({
  selector: 'app-secret-dialog',
  imports: [FormsModule, MatDialogModule, MatButtonModule, MatCheckboxModule],
  styles: `
    code { display: block; word-break: break-all; background: var(--gp-background); padding: 12px; border-radius: 8px; font-size: 15px; user-select: all; }
    .actions { display: flex; gap: 8px; margin: 12px 0; flex-wrap: wrap; }
  `,
  template: `
    <h2 mat-dialog-title>{{ data.title }}</h2>
    <mat-dialog-content>
      <div class="gp-banner warning" role="alert">Este valor se muestra una sola vez y no se podrá volver a ver. {{ data.description }}</div>
      <code data-testid="secret-value">{{ data.secret }}</code>
      <div class="actions">
        <button mat-stroked-button type="button" (click)="copy()">{{ copied ? 'Copiado' : 'Copiar' }}</button>
        <button mat-stroked-button type="button" (click)="download()">Descargar .txt</button>
      </div>
      <mat-checkbox [(ngModel)]="saved" data-testid="secret-saved">Ya lo guardé en un lugar seguro</mat-checkbox>
    </mat-dialog-content>
    <mat-dialog-actions align="end">
      <button mat-flat-button type="button" [disabled]="!saved" (click)="close()" data-testid="secret-close">Cerrar</button>
    </mat-dialog-actions>
  `,
})
export class SecretDialog {
  protected readonly data = inject<SecretDialogData>(MAT_DIALOG_DATA);
  private readonly ref = inject<MatDialogRef<SecretDialog>>(MatDialogRef);
  protected saved = false;
  protected copied = false;

  protected async copy(): Promise<void> {
    try {
      await navigator.clipboard.writeText(this.data.secret);
      this.copied = true;
    } catch {
      this.copied = false;
    }
  }

  protected download(): void {
    const url = URL.createObjectURL(new Blob([this.data.secret + '\n'], { type: 'text/plain' }));
    const a = document.createElement('a');
    a.href = url;
    a.download = this.data.filename;
    a.click();
    URL.revokeObjectURL(url);
  }

  protected close(): void {
    this.data.secret = '';
    this.ref.close();
  }
}

export const SECRET_DIALOG_CONFIG = { disableClose: true, maxWidth: '560px', width: '92vw', autoFocus: 'first-tabbable' } as const;
