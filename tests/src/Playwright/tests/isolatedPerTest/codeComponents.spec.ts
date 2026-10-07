import { readFile } from 'fs/promises';
import { expect } from '@playwright/test';

import { isolatedPerTest as test } from '../../fixtures/test.js';

// @cspell:ignore DeleteGate deletegate

test.describe('Code Components', () => {
  test('Delete gate', async ({ page, canvas, drupal }) => {
    await drupal.login({ username: 'editor', password: 'editor' });
    await canvas.openCanvas(await canvas.createCanvas());

    const code = await readFile(
      'tests/fixtures/code_components/page-elements/PageTitle.jsx',
      'utf-8',
    );
    await canvas.createCodeComponent('DeleteGate', code);
    await canvas.publishAllChanges(['DeleteGate', 'Global CSS']);
    await canvas.saveCodeComponent('js.deletegate');
    await canvas.addComponent({ id: 'js.deletegate' }, { hasInputs: false });

    const libraryItem = page
      .getByTestId('canvas-primary-panel')
      .locator('[data-canvas-name="DeleteGate"]');

    // Component is in the draft layout — "Remove from components" is blocked.
    await libraryItem.hover();
    await libraryItem.getByLabel('Open contextual menu').click();
    await page
      .getByRole('menuitem', { name: 'Remove from components' })
      .click();
    await expect(page.getByText('Component in use')).toBeVisible();
    await page.getByRole('button', { name: 'Cancel' }).click();

    await canvas.publishAllChanges();

    // Remove the instance and demote the component back to internal.
    await canvas.deleteComponent('js.deletegate');

    await libraryItem.hover();
    await libraryItem.getByLabel('Open contextual menu').click();
    await page
      .getByRole('menuitem', { name: 'Remove from components' })
      .click();
    await expect(
      page.getByRole('dialog', { name: 'Remove from components' }),
    ).toBeVisible();
    await page.getByRole('button', { name: 'Remove' }).click();
    await expect(
      page.getByRole('dialog', { name: 'Remove from components' }),
    ).toBeHidden();

    await canvas.openCodePanel();

    const codeItem = page
      .getByTestId('canvas-primary-panel')
      .locator('[data-canvas-name="DeleteGate"]');
    await expect(codeItem).toBeVisible();
    await codeItem.hover();
    await codeItem.getByLabel('Open contextual menu').click();

    // The layout removal is unpublished — backend still considers the component
    // in use, so Delete must be disabled.
    await expect(page.getByRole('menuitem', { name: 'Delete' })).toBeDisabled();
    await page.keyboard.press('Escape');

    // Publish the layout removal, then Delete should be enabled.
    await canvas.publishAllChanges();

    await codeItem.hover();
    await codeItem.getByLabel('Open contextual menu').click();
    await expect(page.getByRole('menuitem', { name: 'Delete' })).toBeEnabled();

    await page.getByRole('menuitem', { name: 'Delete' }).click();
    await page.getByRole('button', { name: 'Delete' }).click();
    await expect(codeItem).not.toBeAttached();
  });
});
