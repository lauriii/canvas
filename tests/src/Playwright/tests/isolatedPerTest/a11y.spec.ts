import AxeBuilder from '@axe-core/playwright';
import { expect } from '@playwright/test';

import { isolatedPerTest as test } from '../../fixtures/test.js';

/**
 * Perfunctory accessibility scan.
 */

test.describe('Basic accessibility', () => {
  test.beforeEach(async ({ drupal }) => {
    await drupal.loginAsAdmin();
    await drupal.installModules(['canvas_test_sdc']);
    await drupal.logout();
  });

  test('Axe scan', async ({ page, drupal, canvas }, testInfo) => {
    // These are the rules that these screens currently violate.
    // @todo not do that.
    const baseline = [
      'aria-required-children',
      'aria-valid-attr-value',
      'button-name',
      'color-contrast',
      'frame-focusable-content',
      'landmark-unique',
      'meta-viewport',
      'region',
      'scrollable-region-focusable',
    ];
    await drupal.login({ username: 'editor', password: 'editor' });
    await canvas.openCanvasRoot();
    const editorScan = await new AxeBuilder({ page })
      .disableRules(baseline)
      .analyze();
    await testInfo.attach('a11y-editor-scan', {
      body: JSON.stringify(editorScan, null, 2),
      contentType: 'application/json',
    });
    expect(
      editorScan.violations,
      'Canvas root screen to pass a11y check',
    ).toEqual([]);

    // Layers Panel.
    await canvas.createCanvas();
    await canvas.openLayersPanel();
    const layersScan = await new AxeBuilder({ page })
      .disableRules(baseline)
      .analyze();
    await testInfo.attach('a11y-layers-panel-scan', {
      body: JSON.stringify(layersScan, null, 2),
      contentType: 'application/json',
    });
    expect(layersScan.violations, 'Layers panel to pass a11y check').toEqual(
      [],
    );

    // Library Panel.
    await canvas.openLibraryPanel();
    const libraryScan = await new AxeBuilder({ page })
      .disableRules(baseline)
      .analyze();
    await testInfo.attach('a11y-library-panel-scan', {
      body: JSON.stringify(libraryScan, null, 2),
      contentType: 'application/json',
    });
    expect(libraryScan.violations, 'Library panel to pass a11y check').toEqual(
      [],
    );

    // Props Panel.
    await canvas.addComponent({ id: 'sdc.canvas_test_sdc.my-hero' });
    const propsScan = await new AxeBuilder({ page })
      .disableRules(baseline)
      .analyze();
    await testInfo.attach('a11y-props-panel-scan', {
      body: JSON.stringify(libraryScan, null, 2),
      contentType: 'application/json',
    });
    expect(
      propsScan.violations,
      'Component instance form to pass a11y check',
    ).toEqual([]);
  });
});
