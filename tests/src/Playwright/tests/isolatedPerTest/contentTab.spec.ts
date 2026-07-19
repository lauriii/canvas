import { expect } from '@playwright/test';

import { isolatedPerTest as test } from '../../fixtures/test.js';

test.use({
  enableTestExtensions: true,
});

// The Content tab (content-entity-editing phase 2): a templated entity's
// content field widgets render inline in the contextual panel, edits
// auto-save as pending changes, publish is blocked until the entity is
// valid, and errors surface on the form fields. Uses the
// article_exposed_slots fixture (templated Article bundle, seeded nodes).
test.describe('Content tab', () => {
  test.beforeEach(async ({ drupal }) => {
    await drupal.loginAsAdmin();
    await drupal.applyRecipe(
      `modules/contrib/canvas/tests/fixtures/recipes/article_exposed_slots`,
    );
    await drupal.addPermissions({
      role: 'editor',
      permissions: ['edit any article content', 'create article content'],
    });
    await drupal.logout();
    await drupal.login({ username: 'editor', password: 'editor' });
  });

  test('renders content fields inline, auto-saves, and updates the pending changes', async ({
    canvas,
    page,
  }) => {
    // Open a seeded templated article through the Content panel; node ids
    // depend on install order, so navigate by title.
    await canvas.createCanvas();
    await canvas.openContentPanel();
    await page.getByText('Templated alpha').click();
    await expect(page).toHaveURL(/\/editor\/node\/\d+/);
    // The panel shows Page data (default) and Content.
    const pageDataTab = page.getByTestId('canvas-contextual-panel--page-data');
    const contentTab = page.getByTestId('canvas-contextual-panel--content');
    await expect(pageDataTab).toBeVisible();
    await expect(contentTab).toBeVisible();

    // Page data holds only page-level metadata: the title is visible, the
    // body widget is not.
    await expect(page.locator('[name="title[0][value]"]')).toBeVisible();
    await expect(page.locator('.field--name-body')).toBeHidden();

    // The Content tab reveals the content partition: the body widget and the
    // escape hatch to Drupal's own form.
    await contentTab.click();
    await expect(page.locator('.field--name-body')).toBeVisible();
    await expect(
      page.getByTestId('canvas-content-tab-edit-form-link'),
    ).toBeVisible();
    // The title (page data partition) is hidden in the Content tab; the one
    // form stays mounted, so its state is preserved.
    await expect(page.locator('[name="title[0][value]"]')).toBeHidden();

    // Editing the body auto-saves as a pending change.
    const body = page.locator('.field--name-body .ck-editor__editable');
    await body.click();
    await body.fill('Edited from the Content tab');
    await expect(
      page.getByRole('button', { name: /Review \d+ change/ }),
    ).toBeVisible();
  });

  test('publish is blocked on invalid fields and recoverable in place', async ({
    canvas,
    page,
  }) => {
    await canvas.createCanvas();
    await canvas.openContentPanel();
    await page.getByText('Templated beta').click();
    await expect(page).toHaveURL(/\/editor\/node\/\d+/);

    // Blank the required title; the empty value still auto-saves (validation
    // is deferred to publish).
    const title = page.locator('[name="title[0][value]"]');
    await title.fill('');
    const review = page.getByRole('button', { name: /Review \d+ change/ });
    await expect(review).toBeVisible();

    // Publishing the invalid draft is rejected; the error names the entity
    // and offers a jump to the offending field.
    await review.click();
    await page.getByRole('checkbox', { name: 'Select all changes' }).check();
    await page.getByRole('button', { name: /Publish \d+ selected/ }).click();
    await expect(page.getByText(/\d+ Error/)).toBeVisible();
    await page.getByTestId('publish-error-jump-to-field').first().click();
    await expect(title).toBeFocused();
    await expect(page.getByText('Title field is required.')).toBeVisible();

    // Fix the field in place and republish: this time it succeeds.
    await title.fill('Valid again');
    await expect(
      page.getByRole('button', { name: /Review \d+ change/ }),
    ).toBeVisible();
    await page.getByRole('button', { name: /Review \d+ change/ }).click();
    await page.getByRole('checkbox', { name: 'Select all changes' }).check();
    await page.getByRole('button', { name: /Publish \d+ selected/ }).click();
    await expect(page.getByRole('button', { name: 'Published' })).toBeVisible();
  });
});
