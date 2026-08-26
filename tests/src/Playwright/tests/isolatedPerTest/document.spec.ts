import { readFile } from 'fs/promises';
import { expect } from '@playwright/test';

import { isolatedPerTest as test } from '../../fixtures/test.js';

import type { Page } from '@playwright/test';

const DOCUMENT_SDC_ID = 'sdc.canvas_test_sdc.document';

/**
 * Picks an existing document from the media library of the selected component.
 */
async function selectExistingMedia(page: Page, mediaName: string) {
  await page
    .locator('[data-testid="canvas-contextual-panel"] input[value="Add media"]')
    .first()
    .click();
  const modal = page.getByRole('dialog', { name: 'Add or select media' });
  await expect(modal.getByRole('checkbox', { name: /^Select / })).toHaveCount(
    2,
  );
  await modal
    .getByRole('checkbox', { name: `Select ${mediaName}` })
    // eslint-disable-next-line playwright/no-force-option
    .setChecked(true, { force: true }); // Drupal media library checkboxes are visually hidden by CSS
  await page
    .getByRole('button', { name: 'Insert selected', exact: true })
    .click();
  await expect(modal).toBeHidden();
  await expect(page.getByLabel(`Remove ${mediaName}`)).toBeVisible();
}

test.use({
  modules: ['canvas_test_sdc', 'canvas_test_document_fixture'],
  enableTestExtensions: true,
});

test.describe('Document Component', () => {
  test('Can use the media library widget to populate a document prop', async ({
    page,
    drupal,
    canvas,
  }) => {
    await drupal.login({ username: 'editor', password: 'editor' });
    await canvas.openCanvas(await canvas.createCanvas());
    await canvas.openLibraryPanel();
    await canvas.addComponent({ id: DOCUMENT_SDC_ID });
    await canvas.addComponent({ id: DOCUMENT_SDC_ID });

    await canvas.clickPreviewComponent(DOCUMENT_SDC_ID, { index: 0 });
    await selectExistingMedia(page, 'Annual Report');
    let previewFrame = await canvas.getActivePreviewFrame();
    await expect(
      previewFrame.locator('.document > a[href*="report.pdf"]'),
    ).toBeVisible();

    await canvas.clickPreviewComponent(DOCUMENT_SDC_ID, { index: 1 });
    await selectExistingMedia(page, 'Product Brochure');
    previewFrame = await canvas.getActivePreviewFrame();
    await expect(
      previewFrame.locator('.document > a[href*="brochure.pdf"]'),
    ).toBeVisible();
    await expect(page.getByLabel('Remove Annual Report')).toBeHidden();

    // Each instance keeps its own document.
    await canvas.clickPreviewComponent(DOCUMENT_SDC_ID, { index: 0 });
    await expect(page.getByLabel('Remove Annual Report')).toBeVisible();
    await expect(page.getByLabel('Remove Product Brochure')).toBeHidden();
  });

  test('Renders the bundled sample document as a code component example', async ({
    page,
    drupal,
    canvas,
  }) => {
    await drupal.login({ username: 'editor', password: 'editor' });
    await canvas.openCanvas(await canvas.createCanvas());

    const code = await readFile(
      'tests/fixtures/code_components/documents/Document.jsx',
      'utf-8',
    );
    await canvas.createCodeComponent('Document', code);
    await canvas.addCodeComponentProp('doc', 'Document', [
      {
        type: 'select',
        label: 'Example document',
        value: 'Sample document (PDF)',
      },
    ]);
    await canvas.saveCodeComponent('js.document');
    await canvas.addComponent({ id: 'js.document' });

    // The inputs form shows the example as a file chip, not a broken image.
    const defaultPreview = page.locator(
      '[data-testid="canvas-contextual-panel"] [class*="defaultImagePreview"]',
    );
    await expect(defaultPreview).toContainText('sample.pdf');
    await expect(defaultPreview.locator('img')).toHaveCount(0);

    const previewFrame = await canvas.getActivePreviewFrame();
    await expect(
      previewFrame.locator('a[download="sample.pdf"]'),
    ).toHaveAttribute('href', /\/ui\/assets\/documents\/sample\.pdf$/);
  });
});
