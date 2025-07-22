import { test as setup } from '@playwright/test';
import { getComposerDir, getRootDir } from './utilities/DrupalFilesystem';
import { exec } from './utilities/DrupalExec';
import { chmodSync, existsSync, mkdirSync } from 'node:fs';

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

setup('Add external composer dependencies', async () => {
  if (process.env.DRUPAL_TEST_PLAYWRIGHT_SKIP_COMPOSER_DEPS) {
    return;
  }
  const composerDir = await getComposerDir();
  await exec('composer require drupal/jsonapi_menu_items -w', composerDir);
});
