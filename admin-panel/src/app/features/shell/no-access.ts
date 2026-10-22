import { Component } from '@angular/core';
import { RouterLink } from '@angular/router';

@Component({
  selector: 'app-no-access',
  imports: [RouterLink],
  template: `
    <h1>No tienes acceso a esta sección</h1>
    <p class="gp-muted">Si crees que deberías tenerlo, pídeselo a un superadmin.</p>
    <a routerLink="/">Volver al inicio</a>
  `,
})
export class NoAccess {}
