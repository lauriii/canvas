import { execDrupal } from '@drupal-canvas/test-utils';

import type { Drupal } from '@drupal/playwright';

export async function applyRecipe(
  drupal: Drupal,
  recipe: string,
): Promise<void> {
  await execDrupal(`recipe ${recipe}`, {
    env: {
      DRUPAL_DEV_SITE_PATH: drupal.drupalSite.sitePath,
    },
  });
}
