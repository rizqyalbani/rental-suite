import { execSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));

async function globalTeardown() {
  const root = path.resolve(__dirname, '../..');
  execSync('php artisan e2e:cleanup --yes', { cwd: root, stdio: 'inherit' });
}

export default globalTeardown;

