import { execFileSync } from 'node:child_process';
import { existsSync, rmSync } from 'node:fs';
import path from 'node:path';

export default async function globalSetup(): Promise<void> {
  const root = path.resolve(__dirname, '../..');
  const database = path.join(root, 'var/e2e-wardrobe-onboarding.db');
  const fixture = path.join(root, 'var/e2e-wardrobe-onboarding-fixture.json');
  const env = {
    ...process.env,
    APP_ENV: 'test',
    APP_DEBUG: '1',
    DATABASE_URL: 'sqlite:///'+database,
  };

  if (existsSync(database)) rmSync(database);
  if (existsSync(fixture)) rmSync(fixture);

  execFileSync('php', ['tests/e2e/fixtures/wardrobe-onboarding.php'], {
    cwd: root,
    env,
    stdio: 'inherit',
  });
}
