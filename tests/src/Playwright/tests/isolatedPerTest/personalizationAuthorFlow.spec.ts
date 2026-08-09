import { expect } from '@playwright/test';

import { isolatedPerTest as test } from '../../fixtures/test.js';

/**
 * End-to-end author flow for personalization: create a segment with a rule,
 * personalize a page, create a variant targeting the segment, edit the
 * variant's content, publish, and verify that anonymous visitors get the
 * right variant server-side (no client-side swapping).
 */
// canvas_personalization is hidden (it is its own feature flag), so it has no
// checkbox on the modules UI; the visible e2e helper test module pulls it in
// as a dependency. It is installed inside the test rather than through the
// fixture's `modules` option because the dependency-confirm install runs as a
// batch that outlasts the fixture helper's fixed post-install assertions on
// slower environments.
// canvas_dev_mode loosens the ComponentSource allowlist so the p13n
// components can be installed.
// @see https://www.drupal.org/i/3520484
test.use({
  modules: ['canvas_test_sdc', 'canvas_dev_mode'],
  enableTestExtensions: true,
});

test.describe('Personalization author flow', () => {
  // The per-test site install plus two extra modules exceeds the default
  // test timeout on slower (bind-mounted) local environments.
  test.describe.configure({ timeout: 600_000 });

  test('create segment, variant, publish, and verify live', async ({
    page,
    drupal,
    canvas,
  }) => {
    await drupal.loginAsAdmin();
    // Install the helper module (and with it, hidden canvas_personalization)
    // through the modules UI, waiting on the install batch's status message.
    await page.goto('/admin/modules');
    // Package groups are collapsed; the checkbox must be visible to check it.
    for (const packageGroup of await page
      .locator('details.package-listing:not([open])')
      .all()) {
      await packageGroup.evaluate((element) =>
        element.setAttribute('open', ''),
      );
    }
    await page
      .locator('input[name="modules[canvas_personalization_e2e][enable]"]')
      .check();
    await page.locator('[data-drupal-selector="edit-submit"]').click();
    await page.waitForLoadState('domcontentloaded');
    const confirmForm = page.locator(
      '[data-drupal-selector="system-modules-confirm-form"]',
    );
    if (await confirmForm.isVisible()) {
      await page.locator('[data-drupal-selector="edit-submit"]').click();
    }
    await expect(page.locator('[data-drupal-messages]')).toContainText(
      /been installed/,
      { timeout: 240_000 },
    );

    // Create a segment matching ?coupon=WEEKEND via the segments dashboard.
    await canvas.openCanvasRoot();
    await page.getByRole('button', { name: 'Segments' }).click();
    await page.getByRole('button', { name: 'Create segment' }).click();
    const createSegmentDialog = page.getByRole('dialog');
    await createSegmentDialog.getByRole('textbox').first().fill('Coupon users');
    await createSegmentDialog.getByRole('button', { name: 'Create' }).click();

    // The dashboard navigates to the new segment's details page.
    await expect(page.getByText('Coupon users')).toBeVisible();
    await page.getByRole('button', { name: 'Add rule' }).click();
    await page.getByRole('menuitem', { name: 'Query parameter' }).click();
    await page.getByLabel('Parameter').fill('coupon');
    await page.getByLabel('Value').fill('WEEKEND');
    await page.getByRole('button', { name: 'Save rules' }).click();
    await page.getByRole('button', { name: 'Enable' }).click();

    // Create a page with a heading component: this becomes the default
    // variant's content.
    const canvasPage = await canvas.createCanvas({
      title: 'Personalized page',
    });
    await canvas.openCanvas(canvasPage);
    const pagePath = `/page/${canvasPage.entity_id}`;
    await canvas.openLibraryPanel();
    await canvas.addComponent({ id: 'sdc.canvas_test_sdc.heading' });

    // Personalize the page.
    await page
      .getByRole('button', { name: 'Personalize', exact: true })
      .click();
    await page
      .getByRole('button', { name: 'Personalize page', exact: true })
      .click();

    // The variants control appears, previewing the default variant.
    const variantsTrigger = page.getByRole('button', {
      name: 'Manage variants',
    });
    await expect(variantsTrigger).toContainText('Variant: Default');

    // Create a variant targeting the segment, starting from the default.
    await variantsTrigger.click();
    await page.getByRole('button', { name: 'New variant' }).click();
    const createVariantDialog = page.getByRole('dialog');
    await createVariantDialog
      .getByRole('textbox')
      .first()
      .fill('Coupon campaign');
    const audienceCheckbox = createVariantDialog.getByRole('checkbox', {
      name: 'Coupon users',
    });
    // The popover-anchored dialog re-renders as the preview refreshes, which
    // trips Playwright's stability check; the assertions after each forced
    // click are the real gates.
    await audienceCheckbox.click({ force: true });
    await expect(audienceCheckbox).toBeChecked();
    await createVariantDialog
      .getByRole('button', { name: 'Create' })
      .click({ force: true });

    // The new variant is now previewed — never ambiguous.
    await expect(variantsTrigger).toContainText('Variant: Coupon campaign');

    // Edit the heading inside the variant. Only the variant's copy is
    // visible in the preview and layers.
    await canvas.openLayersPanel();
    await page
      .getByTestId('canvas-primary-panel')
      .getByText('Heading')
      .first()
      .click();
    await canvas.editComponentProp('Text', 'Weekend deal for you');

    // The edited text appearing in the preview proves the variant's copy is
    // the one being edited, and settles the auto-save round trip so the
    // publish request is not racing a stale hash.
    const previewFrame = await canvas.getActivePreviewFrame();
    await expect(
      previewFrame.getByText('Weekend deal for you').first(),
    ).toBeVisible({ timeout: 60_000 });

    // Publish everything: the page and the segment.
    await canvas.publishAllChanges();

    // Verify server-side variant selection as an anonymous visitor.
    await drupal.logout();
    await page.goto(pagePath);
    await expect(page.locator('h1', { hasText: 'Weekend deal' })).toHaveCount(
      0,
    );
    await page.goto(`${pagePath}?coupon=WEEKEND`);
    await expect(page.getByText('Weekend deal for you')).toBeVisible();
    // The correct variant is in the server-rendered HTML: no personalization
    // JavaScript, no client-side swap.
    const html = await page.content();
    expect(html).toContain('Weekend deal for you');
  });
});
