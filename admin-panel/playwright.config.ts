import { defineConfig } from '@playwright/test';
import { existsSync } from 'node:fs';
import { homedir } from 'node:os';

// Chromium ya descargado por Playwright (si no coincide con la revisión que pide esta versión, se usa el de la caché local).
const cached = `${homedir()}/Library/Caches/ms-playwright/chromium-1243/chrome-mac-arm64/Google Chrome for Testing.app/Contents/MacOS/Google Chrome for Testing`;
const executablePath = process.env['CHROMIUM_PATH'] ?? (existsSync(cached) ? cached : undefined);

/** E2E contra el backend REAL (docker compose up -d db backend) a través del proxy de `ng serve`. Una sola pestaña a la vez: los códigos TOTP son de un solo uso. */
export default defineConfig({
  testDir: 'e2e',
  fullyParallel: false,
  workers: 1,
  timeout: 60_000,
  reporter: [['list']],
  globalSetup: './e2e/global-setup.ts',
  use: {
    baseURL: 'http://localhost:4200',
    launchOptions: { executablePath },
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
  },
  webServer: {
    command: 'npx ng serve --port 4200',
    url: 'http://localhost:4200',
    reuseExistingServer: true,
    timeout: 120_000,
  },
});
