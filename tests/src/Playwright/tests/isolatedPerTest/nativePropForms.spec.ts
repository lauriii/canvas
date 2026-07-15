import { expect } from '@playwright/test';

import { isolatedPerTest as test } from '../../fixtures/test.js';

/**
 * Native prop forms: standard-widget components render their prop editing UI
 * client-side from cached metadata.
 *
 * The zero-request assertion is the deterministic CI guard from the
 * native-prop-forms change: selecting a component whose props all use
 * standard widgets must not trigger any request to the component-instance
 * form endpoint. Request-count assertions cannot flake the way latency
 * assertions can; the selection-to-form-interactive latency itself is a
 * monitored metric, not a per-commit gate.
 */

test.use({ modules: ['canvas_test_sdc'], enableTestExtensions: true });

const FORM_ENDPOINT_PATTERN = /\/canvas\/api\/v0\/form\/component-instance\//;

test.describe('Native prop forms', () => {
  test('Standard-widget component issues no form request and edits natively', async ({
    page,
    drupal,
    canvas,
  }) => {
    await drupal.login({ username: 'editor', password: 'editor' });
    await canvas.openCanvas(await canvas.createCanvas());
    await canvas.openLibraryPanel();
    await canvas.addComponent({ id: 'sdc.canvas_test_sdc.heading' });

    // Track form-endpoint requests from the moment the component is selected.
    const formRequests: string[] = [];
    page.on('request', (request) => {
      if (FORM_ENDPOINT_PATTERN.test(request.url())) {
        formRequests.push(request.url());
      }
    });

    await canvas.clickPreviewComponent('sdc.canvas_test_sdc.heading');

    // The native prop form renders with all three heading props as native
    // widgets: text (string_textfield), style and element (options_select).
    const panel = page.locator(
      '[data-drupal-selector="component-instance-form"]',
    );
    await expect(
      panel.locator('[data-canvas-native-widget="string_textfield"]'),
    ).toBeVisible();
    await expect(
      panel.locator('[data-canvas-native-widget="options_select"]'),
    ).toHaveCount(2);

    // Native editing flows through to the preview (same pipeline as before).
    await canvas.editComponentProp('style', 'secondary', 'select');
    await canvas.editComponentProp('element', 'h3', 'select');
    await canvas.testInPreviewFrame(
      'h3[data-component-id="canvas_test_sdc:heading"].secondary',
      async (heading) => {
        await expect(heading).toBeAttached();
      },
    );

    // Text edits via the native textfield persist to the model and preview.
    const textInput = panel.locator('.field--name-text input');
    await textInput.fill('Natively edited');
    await canvas.testInPreviewFrame(
      'h3[data-component-id="canvas_test_sdc:heading"]',
      async (heading) => {
        await expect(heading).toHaveText('Natively edited');
      },
    );

    // The CI guard: zero requests to the form endpoint for a component whose
    // props all have native client widgets.
    expect(formRequests).toEqual([]);
  });

  test('Undo restores native widget values', async ({
    page,
    drupal,
    canvas,
  }) => {
    await drupal.login({ username: 'editor', password: 'editor' });
    await canvas.openCanvas(await canvas.createCanvas());
    await canvas.openLibraryPanel();
    await canvas.addComponent({ id: 'sdc.canvas_test_sdc.heading' });
    await canvas.clickPreviewComponent('sdc.canvas_test_sdc.heading');

    const panel = page.locator(
      '[data-drupal-selector="component-instance-form"]',
    );
    const textInput = panel.locator('.field--name-text input');
    const originalValue = await textInput.inputValue();
    await textInput.fill('Changed for undo');
    await canvas.testInPreviewFrame(
      '[data-component-id="canvas_test_sdc:heading"]',
      async (heading) => {
        await expect(heading).toHaveText('Changed for undo');
      },
    );

    await page.getByRole('button', { name: 'Undo' }).click();
    await canvas.testInPreviewFrame(
      '[data-component-id="canvas_test_sdc:heading"]',
      async (heading) => {
        await expect(heading).toHaveText(originalValue);
      },
    );
    await expect(textInput).toHaveValue(originalValue);
  });
});
