import { HttpErrorResponse, HttpInterceptorFn } from '@angular/common/http';
import { Injector, inject } from '@angular/core';
import { Router } from '@angular/router';
import { tap } from 'rxjs';
import { Auth } from './auth';

const PRE_AUTH = /\/auth\/(csrf|login|password|mfa)/;

/**
 * Sesión vencida o revocada (401 fuera del inicio de sesión) → se limpia el estado y se vuelve al acceso recordando la ruta. Cada respuesta
 * correcta cuenta como actividad (el servidor renueva la sesión por inactividad en cada petición).
 */
export const sessionInterceptor: HttpInterceptorFn = (req, next) => {
  const injector = inject(Injector);
  return next(req).pipe(
    tap({
      next: (event) => {
        if ('status' in event && event.status < 400) injector.get(Auth).touch();
      },
      error: (e) => {
        if (e instanceof HttpErrorResponse && e.status === 401 && !PRE_AUTH.test(req.url)) {
          const auth = injector.get(Auth);
          if (auth.me()) {
            auth.me.set(null);
            const router = injector.get(Router);
            void router.navigate(['/acceso'], { queryParams: { volver: router.url, vencida: 1 } });
          }
        }
      },
    }),
  );
};
