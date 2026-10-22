import { TestBed } from '@angular/core/testing';
import { ActivatedRouteSnapshot, Router, RouterStateSnapshot, UrlTree, provideRouter } from '@angular/router';
import { Auth } from './auth';
import { authGuard, permissionGuard } from './guards';
import { MeResponse } from './models';

const me = (permissions: string[]): MeResponse => ({ id: 1, email: 'a@b.c', name: 'A', role: 'auditor', permissions, session: { idleMinutes: 30 } });

describe('guardas', () => {
  const run = (guard: typeof authGuard, data: object = {}, url = '/lotes') =>
    TestBed.runInInjectionContext(() => guard({ data } as unknown as ActivatedRouteSnapshot, { url } as RouterStateSnapshot));

  beforeEach(() => TestBed.configureTestingModule({ providers: [provideRouter([])] }));

  it('sin sesión lleva al acceso recordando la ruta', async () => {
    TestBed.inject(Auth).load = async () => null;
    const r = (await run(authGuard)) as UrlTree;
    expect(TestBed.inject(Router).serializeUrl(r)).toBe('/acceso?volver=%2Flotes');
  });

  it('con sesión deja pasar y no vuelve a consultar al servidor', async () => {
    const auth = TestBed.inject(Auth);
    auth.me.set(me([]));
    auth.load = async () => {
      throw new Error('no debía consultar');
    };
    expect(await run(authGuard)).toBe(true);
  });

  it('el permiso decide: sin él va a «sin acceso»', () => {
    const auth = TestBed.inject(Auth);
    auth.me.set(me(['batches.view']));
    expect(run(permissionGuard, { permission: 'batches.view' })).toBe(true);
    const r = run(permissionGuard, { permission: 'users.manage' }) as UrlTree;
    expect(TestBed.inject(Router).serializeUrl(r)).toBe('/sin-acceso');
  });
});
