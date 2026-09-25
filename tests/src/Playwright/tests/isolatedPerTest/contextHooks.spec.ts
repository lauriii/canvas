import { readFile } from 'fs/promises';
import { expect } from '@playwright/test';

import { isolatedPerTest as test } from '../../fixtures/test.js';

// @cspell:ignore PageTitle pagetitlehook
/**
 * Tests the drupal-canvas context hooks in Drupal-rendered Code Components.
 *
 * @see docs/adr/0021-code-component-runtime-compatibility-across-frontend-modes.md
 */

test.describe('Context hooks', () => {
  test('Provide page context in the code editor and on the live page', async ({
    page,
    canvas,
    drupal,
  }) => {
    await drupal.login({ username: 'editor', password: 'editor' });
    const canvasPage = await canvas.createCanvas();
    await canvas.openCanvas(canvasPage);
    const code = await readFile(
      `tests/fixtures/code_components/page-elements/PageTitleHook.jsx`,
      'utf-8',
    );
    await canvas.createCodeComponent('PageTitleHook', code);
    const preview = canvas.getCodePreviewFrame();
    // The code editor preview supplies the editor's preview page context.
    // @see \Drupal\canvas\Controller\CanvasController::__invoke
    await expect(
      preview.getByRole('heading', {
        name: 'This is a page title for testing purposes',
      }),
    ).toBeVisible();
    // Hook usage is reported to the Component data panel, once per value:
    // one entry (an accordion trigger) named after the hook. The locator is
    // scoped to the panel because the code editor also shows the call
    // `usePageContext()` as source text.
    await page.getByRole('tab', { name: 'Data Fetch' }).click();
    const dataFetchPanel = page.getByRole('tabpanel', { name: 'Data Fetch' });
    await expect(
      dataFetchPanel.getByRole('button', { name: 'usePageContext()' }),
    ).toHaveCount(1);
    await expect(
      dataFetchPanel.getByRole('region', { name: 'usePageContext()' }),
    ).toContainText('This is a page title for testing purposes');
    await canvas.publishAllChanges(['PageTitleHook', 'Global CSS']);
    await canvas.saveCodeComponent('js.pagetitlehook');
    await canvas.addComponent({ id: 'js.pagetitlehook' }, { hasInputs: false });
    await canvas.publishAllChanges(['Untitled page']);
    await page.goto(`/page/${canvasPage.entity_id}`);
    // The island renders inside the shared provider with the live page's
    // context; only the page-level settings the hook needs are attached.
    await expect(
      page
        .locator('canvas-island')
        .getByRole('heading', { name: 'Untitled page' }),
    ).toBeVisible();
    const settings = await page.evaluate(
      () =>
        (
          window as unknown as {
            drupalSettings: { canvasData: { v0: Record<string, unknown> } };
          }
        ).drupalSettings.canvasData.v0,
    );
    expect(Object.keys(settings).sort()).toEqual([
      'breadcrumbs',
      'mainEntity',
      'pageTitle',
    ]);
  });
});
