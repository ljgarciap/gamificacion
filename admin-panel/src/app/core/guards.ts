import { inject } from '@angular/core';
import { CanActivateFn, Router } from '@angular/router';
import { Auth } from './auth';

/** Exige sesión completa; si no la hay, lleva a /acceso recordando a dónde iba. */
export const authGuard: CanActivateFn = async (_route, state) => {
  const auth = inject(Auth);
  const router = inject(Router);
  if (auth.me() || (await auth.load())) return true;
  return router.createUrlTree(['/acceso'], { queryParams: { volver: state.url } });
};

/** Exige el permiso de `data.permission`; sin él, pantalla de «sin acceso» (no revela si la sección existe). */
export const permissionGuard: CanActivateFn = (route) => {
  const auth = inject(Auth);
  const needed = route.data['permission'] as string | undefined;
  return !needed || auth.can(needed) ? true : inject(Router).createUrlTree(['/sin-acceso']);
};
