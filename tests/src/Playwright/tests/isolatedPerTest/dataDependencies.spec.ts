import { readFile } from 'fs/promises';
import { expect } from '@playwright/test';

import { isolatedPerTest as test } from '../../fixtures/test.js';

// @cspell:ignore PageTitle
/**
 * Tests data dependencies.
 */

test.describe('Data dependencies', () => {
  test('Are extracted and saved to the entity', async ({
    page,
    canvas,
    drupal,
  }) => {
    await drupal.login({ username: 'editor', password: 'editor' });
    const canvasPage = await canvas.createCanvas();
    await canvas.openCanvas(canvasPage);
    const code = await readFile(
      `tests/fixtures/code_components/page-elements/PageTitle.jsx`,
      'utf-8',
    );
    await canvas.createCodeComponent('PageTitle', code);
    const preview = canvas.getCodePreviewFrame();
    // @see \Drupal\canvas\Controller\CanvasController::__invoke
    await expect(
      preview.getByRole('heading', {
        name: 'This is a page title for testing purposes',
      }),
    ).toBeVisible();
    await canvas.publishAllChanges(['PageTitle', 'Global CSS']);
    await canvas.saveCodeComponent('js.pagetitle');
    await canvas.addComponent({ id: 'js.pagetitle' }, { hasInputs: false });
    await canvas.publishAllChanges(['Untitled page']);
    await page.goto(`/page/${canvasPage.entity_id}`);
    await expect(
      page
        .locator('canvas-island')
        .getByRole('heading', { name: 'Untitled page' }),
    ).toBeVisible();
  });
});
