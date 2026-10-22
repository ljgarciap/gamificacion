import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { Api, ApiError } from './api';

describe('Api', () => {
  let api: Api;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({ providers: [provideHttpClient(), provideHttpClientTesting()] });
    api = TestBed.inject(Api);
    http = TestBed.inject(HttpTestingController);
  });

  it('normaliza el error del servidor con su código, mensaje y espera', async () => {
    const p = api.post('/auth/mfa', { code: '1' }).catch((e) => e);
    http.expectOne('/admin/api/v1/auth/mfa').flush({ error: { code: 'rateLimited', message: 'Espera', retryAfter: 30 } }, { status: 429, statusText: 'x' });
    const e = (await p) as ApiError;
    expect(e).toBeInstanceOf(ApiError);
    expect([e.status, e.code, e.message, e.retryAfter]).toEqual([429, 'rateLimited', 'Espera', 30]);
  });

  it('un fallo de red es «network» y una respuesta sin forma conocida es serverError', async () => {
    const a = api.get('/dashboard').catch((e) => e);
    http.expectOne('/admin/api/v1/dashboard').error(new ProgressEvent('error'));
    expect(((await a) as ApiError).code).toBe('network');

    const b = api.get('/dashboard').catch((e) => e);
    http.expectOne('/admin/api/v1/dashboard').flush('<html>boom</html>', { status: 500, statusText: 'x' });
    expect(((await b) as ApiError).code).toBe('serverError');
  });

  it('omite los parámetros vacíos o indefinidos', async () => {
    const p = api.get('/audit', { action: '', actor: undefined, perPage: 20 });
    const req = http.expectOne((r) => r.url === '/admin/api/v1/audit');
    expect(req.request.params.keys()).toEqual(['perPage']);
    req.flush({});
    await p;
  });
});
