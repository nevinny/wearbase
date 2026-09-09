import { execFileSync } from 'node:child_process';
import { existsSync, rmSync } from 'node:fs';
import path from 'node:path';

export default async function globalSetup(): Promise<void> {
  const root = path.resolve(__dirname, '../..');
  const database = path.join(root, 'var/e2e-family.db');
  const fixture = path.join(root, 'var/e2e-family-fixture.json');
  const env = {
    ...process.env,
    APP_ENV: 'test',
    DATABASE_URL: 'sqlite:///'+database,
  };

  if (existsSync(database)) rmSync(database);
  if (existsSync(fixture)) rmSync(fixture);

  execFileSync('php', ['-d', 'memory_limit=512M', 'bin/console', 'cache:clear', '--no-warmup'], {
    cwd: root,
    env,
    stdio: 'inherit',
  });
  execFileSync('php', ['tests/e2e/fixtures/family-flow.php'], {
    cwd: root,
    env,
    stdio: 'inherit',
  });
}
