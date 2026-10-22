import { execSync } from 'node:child_process';
import { writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { resetTotpState } from './totp';

/** Deja cuentas conocidas en la base de desarrollo (backend/scripts/e2e_admin_users.php, solo APP_ENV=local) y guarda lo que las pruebas necesitan. */
export default function globalSetup(): void {
  const out = execSync('docker compose exec -T backend php /var/www/html/scripts/e2e_admin_users.php', { cwd: '..', encoding: 'utf8' });
  JSON.parse(out); // falla pronto y claro si el backend no respondió JSON
  writeFileSync(join(process.cwd(), 'e2e', '.fixtures.json'), out);
  resetTotpState();
}
