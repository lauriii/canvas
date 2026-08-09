import { expect } from '@playwright/test';

import { isolatedPerTest as test } from '../../fixtures/test.js';

/**
 * End-to-end author flow for personalization: create a segment with a rule,
 * personalize a page, create a variant targeting the segment, edit the
 * variant's content, publish, and verify that anonymous visitors get the
 * right variant server-side (no client-side swapping).
 */
test.use({ modules: ['canvas_test_sdc', 'canvas_personalization'] });

test.describe('Personalization author flow', () => {
  test('create segment, variant, publish, and verify live', async ({
    page,
    drupal,
    canvas,
  }) => {
    await drupal.loginAsAdmin();

    // Create a page with a heading component: this becomes the default
    // variant's content.
    const pagePath = await canvas.createCanvas({ title: 'Personalized page' });
    await canvas.openCanvas(pagePath);
    await canvas.openLibraryPanel();
    await canvas.addComponent({ id: 'sdc.canvas_test_sdc.heading' });

    // Create a segment matching ?coupon=WEEKEND via the segments dashboard.
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

    // Back to the builder; personalize the page.
    await page.getByRole('button', { name: 'Builder' }).click();
    await canvas.waitForEditorUi();
    await page.getByRole('button', { name: 'Personalize' }).click();
    await page.getByRole('menuitem', { name: 'Personalize this page' }).click();

    // The variants control appears, previewing the default variant.
    const variantsTrigger = page.getByRole('button', {
      name: 'Manage variants',
    });
    await expect(variantsTrigger).toContainText('Variant: default');

    // Create a variant targeting the segment, starting from the default.
    await variantsTrigger.click();
    await page.getByRole('button', { name: 'New variant' }).click();
    const createVariantDialog = page.getByRole('dialog');
    await createVariantDialog
      .getByRole('textbox')
      .first()
      .fill('Coupon campaign');
    await createVariantDialog
      .getByRole('group', { name: 'Audience' })
      .getByText('Coupon users')
      .click();
    await createVariantDialog.getByRole('button', { name: 'Create' }).click();

    // The new variant is now previewed — never ambiguous.
    await expect(variantsTrigger).toContainText('Variant: coupon_campaign');

    // Edit the heading inside the variant. Only the variant's copy is
    // visible in the preview and layers.
    await canvas.openLayersPanel();
    await page
      .getByTestId('canvas-primary-panel')
      .getByText('Heading')
      .first()
      .click();
    await canvas.editComponentProp('Text', 'Weekend deal for you');

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
