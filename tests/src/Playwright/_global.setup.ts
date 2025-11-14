import { chmodSync, existsSync, mkdirSync } from 'node:fs';
import { getRootDir } from '@drupal-canvas/test-utils';
import { test as setup } from '@playwright/test';

setup('Create sites/simpletest folder', async () => {
  const rootDir = getRootDir();
  const sitesDir = `${rootDir}/sites`;
  const simpletestDir = `${sitesDir}/simpletest`;
  if (!existsSync(simpletestDir)) {
    mkdirSync(simpletestDir, {
      mode: 0o775,
    });
  }
  chmodSync(`${sitesDir}/default`, 0o755);
  const filesDir = `${sitesDir}/default/files`;
  if (!existsSync(filesDir)) {
    mkdirSync(filesDir, {
      mode: 0o777,
    });
  }
});
