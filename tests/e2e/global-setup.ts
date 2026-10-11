import fs from 'node:fs';
import path from 'node:path';
import { execSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { chromium, type FullConfig } from '@playwright/test';

const __dirname = path.dirname(fileURLToPath(import.meta.url));

/**
 * Logs in once with a seeded staff user (see database/seeders/DatabaseSeeder.php).
 * Run `php artisan migrate:fresh --seed` (or ensure admin@rental.test exists) before e2e.
 *
 * Override credentials: PLAYWRIGHT_E2E_EMAIL, PLAYWRIGHT_E2E_PASSWORD
 */
async function globalSetup(config: FullConfig) {
  const root = path.resolve(__dirname, '../..');
  execSync('php artisan e2e:cleanup --yes', { cwd: root, stdio: 'inherit' });
  const baseURL =
    config.projects[0]?.use?.baseURL?.replace(/\/$/, '') || 'http://127.0.0.1:8085';
  const authDir = path.join(__dirname, '.auth');
  fs.mkdirSync(authDir, { recursive: true });
  const storagePath = path.join(authDir, 'user.json');

  const email = process.env.PLAYWRIGHT_E2E_EMAIL || 'superadmin@rental.test';
  const password = process.env.PLAYWRIGHT_E2E_PASSWORD || 'password';

  const browser = await chromium.launch();
  const context = await browser.newContext();
  const page = await context.newPage();

  await page.goto(`${baseURL}/login`, { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="email"]').fill(email);
  await page.locator('input[name="password"]').fill(password);
  await page.getByRole('button', { name: 'Log in' }).click();
  await page.waitForURL(/dashboard/, { timeout: 20_000 });

  await context.storageState({ path: storagePath });
  await browser.close();
}

export default globalSetup;
