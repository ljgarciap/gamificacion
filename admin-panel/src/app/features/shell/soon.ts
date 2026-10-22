import { Component, inject } from '@angular/core';
import { ActivatedRoute } from '@angular/router';

/** Marcador de las pantallas de P8: el esqueleto ya aplica permisos, menú y título. */
@Component({
  selector: 'app-soon',
  template: `<h1>{{ title }}</h1><p class="gp-muted">Esta pantalla se construye en el siguiente bloque.</p>`,
})
export class Soon {
  protected readonly title = inject(ActivatedRoute).snapshot.data['title'] as string;
}
