import { Page, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { freshCode } from './totp';

export interface Fixtures {
  password: string;
  users: Record<'superadmin' | 'operator' | 'auditor', { email: string; secretBase32: string }>;
  fresh: { email: string; tempPassword: string };
}

export const fixtures = (): Fixtures => JSON.parse(readFileSync(join(process.cwd(), 'e2e', '.fixtures.json'), 'utf8'));

/** Inicia sesión completa (correo, contraseña y TOTP) y espera a ver el panel. */
export async function login(page: Page, role: keyof Fixtures['users'], volver?: string): Promise<void> {
  const f = fixtures();
  const u = f.users[role];
  await page.goto(volver ? `/acceso?volver=${encodeURIComponent(volver)}` : '/acceso');
  await page.getByLabel('Correo', { exact: true }).fill(u.email);
  await page.getByLabel('Contraseña', { exact: true }).fill(f.password);
  await page.getByRole('button', { name: 'Entrar' }).click();
  await page.getByLabel('Código de 6 dígitos', { exact: true }).fill(await freshCode(u.secretBase32));
  await page.getByRole('button', { name: 'Verificar' }).click();
  await expect(page.getByTestId('who')).toBeVisible();
}

/** Llamada a la API del panel con la sesión (y el token CSRF) del navegador: para preparar datos de una prueba sin pasar por la interfaz. */
export async function apiCall<T = unknown>(page: Page, method: 'GET' | 'POST' | 'PATCH' | 'DELETE', path: string, body?: unknown): Promise<T> {
  const cookies = await page.context().cookies();
  const xsrf = decodeURIComponent(cookies.find((c) => c.name === 'XSRF-TOKEN')?.value ?? '');
  const res = await page.request.fetch(`/admin/api/v1${path}`, {
    method,
    headers: { 'X-XSRF-TOKEN': xsrf, Accept: 'application/json' },
    data: body === undefined ? undefined : body,
  });
  if (!res.ok()) throw new Error(`${method} ${path} → ${res.status()} ${await res.text()}`);
  return (res.status() === 204 ? undefined : await res.json()) as T;
}
