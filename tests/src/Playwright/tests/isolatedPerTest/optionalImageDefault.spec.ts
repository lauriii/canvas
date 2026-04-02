import { expect } from '@playwright/test';

import { isolatedPerTest as test } from '../../fixtures/test.js';

test.describe('Optional Image Default Management', () => {
  test.beforeEach(async ({ drupal }) => {
    await drupal.enableTestExtensions();
    await drupal.loginAsAdmin();
    await drupal.applyRecipe(`core/recipes/image_media_type`);
    await drupal.installModules([
      'canvas_test_sdc',
      'canvas_test_e2e_code_components',
    ]);
    await drupal.logout();
  });

  test('SDC: Optional image default can be removed, uploaded, and persists correctly', async ({
    page,
    drupal,
    canvas,
  }) => {
    await drupal.login({ username: 'editor', password: 'editor' });
    const canvasPage = await canvas.createCanvas();
    await canvas.openCanvas(canvasPage);
    await canvas.openLibraryPanel();
    await canvas.addComponent({
      id: 'sdc.canvas_test_sdc.image-optional-with-example-and-additional-prop',
    });

    let frame = await canvas.getActivePreviewFrame();
    await expect(frame.locator('img[alt="A good dog"]')).toBeVisible();

    const imageFieldset = page.locator(
      '[class*="contextualPanel"] fieldset[data-form-id="component_instance_form"][data-canvas-media-library-fieldset="true"]',
    );
    await expect(imageFieldset).toBeVisible();

    const defaultImagePreview = imageFieldset.locator(
      '[class*="defaultImagePreview"]',
    );

    await defaultImagePreview
      .locator('button[aria-label="Remove default"]')
      .click();

    frame = await canvas.getActivePreviewFrame();
    await expect(frame.locator('img[alt="A good dog"]')).not.toBeVisible();

    await expect(defaultImagePreview).not.toBeVisible();
    await expect(
      imageFieldset.locator('.js-media-library-open-button').first(),
    ).toBeVisible();

    const libraryHeading = page.getByRole('heading', { name: 'Library' });
    if (!(await libraryHeading.isVisible())) {
      await page.getByTestId('canvas-side-menu').getByLabel('Library').click();
      await expect(libraryHeading).toBeVisible();
      await page.getByTestId('canvas-library-components-tab-select').click();
    }
    await canvas.addComponent({
      id: 'sdc.canvas_test_sdc.card',
    });

    frame = await canvas.getActivePreviewFrame();
    const optionalImageComponent = frame.locator(
      '[data-canvas-component-id="sdc.canvas_test_sdc.image-optional-with-example-and-additional-prop"]',
    );
    await expect(optionalImageComponent.locator('img')).toHaveCount(0);

    await canvas.publishAllChanges();

    await page.goto(`/page/${canvasPage.entity_id}`);
    const publishedFrame = page.locator('main');
    await expect(publishedFrame.locator('img[alt="A good dog"]')).toHaveCount(
      0,
    );
    await page.getByRole('link', { name: 'Edit' }).click();
    await canvas.waitForEditorUi();

    await canvas.clickPreviewComponent(
      'sdc.canvas_test_sdc.image-optional-with-example-and-additional-prop',
    );

    const imageFieldsetAfterPublish = page.locator(
      '[class*="contextualPanel"] fieldset[data-form-id="component_instance_form"][data-canvas-media-library-fieldset="true"]',
    );
    await expect(imageFieldsetAfterPublish).toBeVisible();

    await canvas.addMediaImage(
      '../../../../../fixtures/recipes/test_site/content/file/cats-1.jpg',
      'A cat on top of a cat tree trying to reach a Christmas tree',
    );

    frame = await canvas.getActivePreviewFrame();
    await expect(
      frame.locator(
        'img[alt="A cat on top of a cat tree trying to reach a Christmas tree"]',
      ),
    ).toBeVisible();
    await expect(frame.locator('img[alt="A good dog"]')).not.toBeVisible();
  });

  test('SDC and Code Component: Required vs optional image behavior', async ({
    page,
    drupal,
    canvas,
  }) => {
    await drupal.login({ username: 'editor', password: 'editor' });
    const canvasPage = await canvas.createCanvas();
    await canvas.openCanvas(canvasPage);
    await canvas.openLibraryPanel();
    await canvas.addComponent({
      id: 'sdc.canvas_test_sdc.image-optional-with-example-and-additional-prop',
    });

    let frame = await canvas.getActivePreviewFrame();
    await expect(frame.locator('img[alt="A good dog"]')).toBeVisible();

    let imageFieldset = page.locator(
      '[class*="contextualPanel"] fieldset[data-form-id="component_instance_form"][data-canvas-media-library-fieldset="true"]',
    );
    let defaultImagePreview = imageFieldset.locator(
      '[class*="defaultImagePreview"]',
    );
    await expect(
      defaultImagePreview.locator('button[aria-label="Remove default"]'),
    ).toBeVisible({ timeout: 15000 });

    const libraryHeading = page.getByRole('heading', { name: 'Library' });
    if (!(await libraryHeading.isVisible())) {
      await page.getByTestId('canvas-side-menu').getByLabel('Library').click();
      await expect(libraryHeading).toBeVisible();
      await page.getByTestId('canvas-library-components-tab-select').click();
    }
    await canvas.addComponent({
      id: 'sdc.canvas_test_sdc.image-required-with-example',
    });

    frame = await canvas.getActivePreviewFrame();
    await expect(frame.locator('img[alt="Boring placeholder"]')).toBeVisible();

    imageFieldset = page.locator(
      '[class*="contextualPanel"] fieldset[data-form-id="component_instance_form"][data-canvas-media-library-fieldset="true"]',
    );
    defaultImagePreview = imageFieldset.locator(
      '[class*="defaultImagePreview"]',
    );
    await expect(defaultImagePreview).toBeVisible();
    await expect(defaultImagePreview.locator('img')).toBeVisible();

    await expect(
      defaultImagePreview.locator('button[aria-label="Remove default"]'),
    ).not.toBeVisible();

    const libraryHeading2 = page.getByRole('heading', { name: 'Library' });
    if (!(await libraryHeading2.isVisible())) {
      await page.getByTestId('canvas-side-menu').getByLabel('Library').click();
      await expect(libraryHeading2).toBeVisible();
      await page.getByTestId('canvas-library-components-tab-select').click();
    }
    await canvas.addComponent({
      id: 'js.canvas_test_e2e_code_components_optional_image',
    });

    frame = await canvas.getActivePreviewFrame();
    await expect(
      frame.locator('.layout-content img[alt="Example image placeholder"]'),
    ).toBeVisible();

    imageFieldset = page.locator(
      '[class*="contextualPanel"] fieldset[data-form-id="component_instance_form"][data-canvas-media-library-fieldset="true"]',
    );
    defaultImagePreview = imageFieldset.locator(
      '[class*="defaultImagePreview"]',
    );
    await expect(defaultImagePreview).toBeVisible();
    await expect(
      defaultImagePreview.locator('button[aria-label="Remove default"]'),
    ).toBeVisible();
  });

  test('Code component: Remove, upload, and persist optional image correctly', async ({
    page,
    drupal,
    canvas,
  }) => {
    await drupal.login({ username: 'editor', password: 'editor' });
    const canvasPage = await canvas.createCanvas();
    await canvas.openCanvas(canvasPage);
    await canvas.openLibraryPanel();
    await canvas.addComponent({
      id: 'js.canvas_test_e2e_code_components_optional_image',
    });

    let frame = await canvas.getActivePreviewFrame();
    await expect(
      frame.locator('.layout-content img[alt="Example image placeholder"]'),
    ).toBeVisible();

    const imageFieldset = page.locator(
      '[class*="contextualPanel"] fieldset[data-form-id="component_instance_form"][data-canvas-media-library-fieldset="true"]',
    );
    const defaultImagePreview = imageFieldset.locator(
      '[class*="defaultImagePreview"]',
    );
    await expect(defaultImagePreview).toBeVisible({ timeout: 15000 });
    await expect(
      defaultImagePreview.locator('button[aria-label="Remove default"]'),
    ).toBeVisible();

    await defaultImagePreview
      .locator('button[aria-label="Remove default"]')
      .click();

    frame = await canvas.getActivePreviewFrame();
    const componentLocator = frame.locator(
      '[data-canvas-component-id="js.canvas_test_e2e_code_components_optional_image"]',
    );
    await expect(componentLocator.locator('img')).toHaveCount(0);

    await canvas.publishAllChanges();

    await page.goto(`/page/${canvasPage.entity_id}`);
    const publishedFrame = page.locator('main');
    await expect(
      publishedFrame.locator('img[alt="Example image placeholder"]'),
    ).toHaveCount(0);

    await canvas.openCanvas(canvasPage);
    await canvas.openLayersPanel();
    await canvas.openComponent('CC Optional Image');

    const imageFieldsetAfterPublish = page.locator(
      '[class*="contextualPanel"] fieldset[data-form-id="component_instance_form"][data-canvas-media-library-fieldset="true"]',
    );
    await expect(imageFieldsetAfterPublish).toBeVisible();

    await canvas.addMediaImage(
      '../../../../../fixtures/recipes/test_site/content/file/cats-1.jpg',
      'A cat on top of a cat tree trying to reach a Christmas tree',
    );

    frame = await canvas.getActivePreviewFrame();
    await expect(
      frame.locator(
        'img[alt="A cat on top of a cat tree trying to reach a Christmas tree"]',
      ),
    ).toBeVisible();

    await page
      .locator('[class*="contextualPanel"]')
      .getByLabel('Remove cats-1.jpg')
      .click();

    await expect(componentLocator.locator('img')).toHaveCount(0);

    const libraryHeading = page.getByRole('heading', { name: 'Library' });
    if (!(await libraryHeading.isVisible())) {
      await page.getByTestId('canvas-side-menu').getByLabel('Library').click();
      await expect(libraryHeading).toBeVisible();
      await page.getByTestId('canvas-library-components-tab-select').click();
    }
    await canvas.addComponent({
      id: 'sdc.canvas_test_sdc.card',
    });

    frame = await canvas.getActivePreviewFrame();
    const optionalImageComponent = frame.locator(
      '[data-canvas-component-id="js.canvas_test_e2e_code_components_optional_image"]',
    );
    await expect(optionalImageComponent.locator('img')).toHaveCount(0);
  });

  test('SDC: Multiple media widgets — each DefaultImagePreview is scoped to its own prop, and required images cannot be deleted', async ({
    page,
    drupal,
    canvas,
  }) => {
    await drupal.login({ username: 'editor', password: 'editor' });
    await canvas.createCanvas();
    await canvas.openLibraryPanel();
    await canvas.addComponent({
      id: 'sdc.canvas_test_sdc.mixed-images-with-example',
    });

    // All three default images should be visible in the preview.
    let frame = await canvas.getActivePreviewFrame();
    await expect(
      frame.locator('img[alt="Primary default image"]'),
    ).toBeVisible();
    await expect(
      frame.locator('img[alt="Secondary default image"]'),
    ).toBeVisible();
    await expect(
      frame.locator('img[alt="Required default image"]'),
    ).toBeVisible();

    // All three fieldsets should each have their own independent
    // DefaultImagePreview — one per prop.
    const contextualPanel = page.locator('[class*="contextualPanel"]');
    const allFieldsets = contextualPanel.locator(
      'fieldset[data-form-id="component_instance_form"][data-canvas-media-library-fieldset="true"]',
    );
    await expect(allFieldsets).toHaveCount(3);

    const primaryFieldset = allFieldsets.first();
    const secondaryFieldset = allFieldsets.nth(1);
    const requiredFieldset = allFieldsets.nth(2);

    const primaryPreview = primaryFieldset.locator(
      '[class*="defaultImagePreview"]',
    );
    const secondaryPreview = secondaryFieldset.locator(
      '[class*="defaultImagePreview"]',
    );
    const requiredPreview = requiredFieldset.locator(
      '[class*="defaultImagePreview"]',
    );

    await expect(primaryPreview).toBeVisible();
    await expect(secondaryPreview).toBeVisible();
    await expect(requiredPreview).toBeVisible();

    // Optional props have the "Remove default" button; required does NOT.
    await expect(
      primaryPreview.locator('button[aria-label="Remove default"]'),
    ).toBeVisible();
    await expect(
      secondaryPreview.locator('button[aria-label="Remove default"]'),
    ).toBeVisible();
    await expect(
      requiredPreview.locator('button[aria-label="Remove default"]'),
    ).not.toBeVisible();

    // Removing the default from the PRIMARY field must not affect the
    // SECONDARY or the REQUIRED field.
    await primaryPreview.locator('button[aria-label="Remove default"]').click();

    frame = await canvas.getActivePreviewFrame();
    await expect(
      frame.locator('img[alt="Primary default image"]'),
    ).not.toBeVisible();
    await expect(
      frame.locator('img[alt="Secondary default image"]'),
    ).toBeVisible();
    await expect(
      frame.locator('img[alt="Required default image"]'),
    ).toBeVisible();

    await expect(primaryPreview).not.toBeVisible();
    await expect(secondaryPreview).toBeVisible();
    await expect(requiredPreview).toBeVisible();

    // The Page data tab must NOT show any DefaultImagePreview widgets,
    // even when a component with multiple image props is selected.
    await page.getByTestId('canvas-contextual-panel--page-data').click();
    const pageDataTab = page.locator('[data-testid="canvas-contextual-panel"]');
    await expect(
      pageDataTab.locator('[class*="defaultImagePreview"]'),
    ).toHaveCount(0);
  });
});
