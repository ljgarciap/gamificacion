import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import { ApiErrorBody } from './models';

/** Error del servidor con la forma `{error: {code, message, retryAfter?}}`; `code` es `network` si no hubo respuesta. */
export class ApiError extends Error {
  constructor(
    readonly status: number,
    readonly code: string,
    message: string,
    readonly retryAfter?: number,
  ) {
    super(message);
  }
}

/**
 * Cliente de la API del panel. Mismo origen (en desarrollo, el proxy de `ng serve`): Angular lee la cookie XSRF-TOKEN y manda X-XSRF-TOKEN
 * en cada petición que cambia estado. Los errores se normalizan a ApiError; el mensaje del servidor se muestra tal cual (nunca revela de más).
 */
@Injectable({ providedIn: 'root' })
export class Api {
  static readonly BASE = '/admin/api/v1';
  private readonly http = inject(HttpClient);

  get<T>(path: string, params?: Record<string, string | number | undefined>): Promise<T> {
    const clean: Record<string, string> = {};
    for (const [k, v] of Object.entries(params ?? {})) if (v !== undefined && v !== '') clean[k] = String(v);
    return this.run(this.http.get<T>(Api.BASE + path, { params: clean }));
  }

  post<T>(path: string, body: unknown = {}): Promise<T> {
    return this.run(this.http.post<T>(Api.BASE + path, body));
  }

  patch<T>(path: string, body: unknown): Promise<T> {
    return this.run(this.http.patch<T>(Api.BASE + path, body));
  }

  delete<T>(path: string): Promise<T> {
    return this.run(this.http.delete<T>(Api.BASE + path));
  }

  private async run<T>(request: import('rxjs').Observable<T>): Promise<T> {
    try {
      return await firstValueFrom(request);
    } catch (e) {
      throw this.normalize(e);
    }
  }

  private normalize(e: unknown): ApiError {
    if (!(e instanceof HttpErrorResponse)) return new ApiError(0, 'network', 'Error inesperado.');
    const body = e.error as Partial<ApiErrorBody> | null;
    if (body?.error?.code) {
      const retry = body.error.retryAfter ?? Number(e.headers.get('Retry-After') || undefined) ?? undefined;
      return new ApiError(e.status, body.error.code, body.error.message, Number.isFinite(retry) ? retry : undefined);
    }
    if (e.status === 0) return new ApiError(0, 'network', 'No hay conexión con el servidor.');
    return new ApiError(e.status, 'serverError', 'El servidor no pudo atender la petición.');
  }
}
