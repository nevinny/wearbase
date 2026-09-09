import { execFileSync } from 'node:child_process';
import { existsSync, rmSync } from 'node:fs';
import path from 'node:path';

export default async function globalSetup(): Promise<void> {
  const root = path.resolve(__dirname, '../..');
  const database = path.join(root, 'var/e2e-wardrobe-lifecycle.db');
  const output = path.join(root, 'var/e2e-wardrobe-lifecycle-fixture.json');
  if (existsSync(database)) rmSync(database);
  if (existsSync(output)) rmSync(output);
  const env = { ...process.env, APP_ENV: 'test', DATABASE_URL: 'sqlite:///'+database };
  execFileSync('php', ['-d', 'memory_limit=512M', 'bin/console', 'cache:clear', '--no-warmup'], { cwd: root, env, stdio: 'inherit' });
  execFileSync('php', ['tests/e2e/fixtures/wardrobe-lifecycle.php'], { cwd: root, env, stdio: 'inherit' });
}
