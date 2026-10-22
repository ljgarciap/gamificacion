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
