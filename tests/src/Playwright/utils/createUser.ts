import { expect } from '@playwright/test';

import type { Drupal } from '@drupal/playwright';

/**
 * Creates a user, like Drupal::createUser(), on every supported core version.
 *
 * Drupal 11.5 checks "Email user with password setup instructions" by default
 * on the admin user form, which hides the password fields.
 *
 * @todo Use Drupal::createUser() again once @drupal/playwright supports it.
 */
export async function createUser(
  drupal: Drupal,
  {
    username,
    password,
    email,
    roles = [],
  }: Parameters<Drupal['createUser']>[0],
): Promise<void> {
  const page = drupal.page;
  await page.goto('/admin/people/create');
  const notify = page.locator('[data-drupal-selector="edit-notify"]');
  if ((await notify.count()) > 0 && (await notify.isChecked())) {
    await notify.uncheck();
  }
  await page.locator('[data-drupal-selector="edit-mail"]').fill(email);
  await page.locator('[data-drupal-selector="edit-name"]').fill(username);
  await page.locator('[data-drupal-selector="edit-pass-pass1"]').fill(password);
  await page.locator('[data-drupal-selector="edit-pass-pass2"]').fill(password);
  for (const role of roles) {
    await page
      .locator(
        `[data-drupal-selector="edit-roles-${role.replace(/[ _]/g, '-')}"]`,
      )
      .check();
  }
  await page.locator('[data-drupal-selector="edit-submit"]').click();
  await expect(page.locator('[data-drupal-messages]')).toContainText(username);
}
