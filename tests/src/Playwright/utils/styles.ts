import { expect } from '@playwright/test';

import type { Page } from '@playwright/test';

/**
 * Installs the experimental Style API polyfill through the modules page.
 *
 * Drupal.installModules() only confirms the dependency form, not the form core
 * shows for experimental modules, so the polyfill cannot go in `modules`.
 */
export async function installStylePolyfill(page: Page): Promise<void> {
  await page.goto('/admin/modules');
  const checkbox = page.locator(
    'input[name="modules[canvas_style_polyfill][enable]"]',
  );
  // Packages start collapsed.
  const listing = page.locator('details.package-listing', { has: checkbox });
  if ((await listing.getAttribute('open')) === null) {
    await listing.locator('summary').click();
  }
  await checkbox.check();
  await page.locator('[data-drupal-selector="edit-submit"]').click();
  await expect(
    page.locator(
      '[data-drupal-selector="system-modules-non-stable-confirm-form"]',
    ),
  ).toBeVisible();
  await page.getByRole('button', { name: 'Continue' }).click();
  await expect(checkbox).toBeDisabled();
}
