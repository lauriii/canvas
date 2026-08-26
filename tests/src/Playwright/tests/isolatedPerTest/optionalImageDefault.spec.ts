import nodePath from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect } from '@playwright/test';

import { isolatedPerTest as test } from '../../fixtures/test.js';

test.use({
  modules: ['canvas_test_sdc', 'canvas_test_e2e_code_components'],
  enableTestExtensions: true,
});

const CAT_IMAGE =
  '../../../../../fixtures/recipes/test_site/content/file/cats-1.jpg';
const PUB_IMAGE =
  '../../../../../fixtures/recipes/test_site/content/file/PrincesHead.jpg';
const SVG_IMAGE = '../../../../../fixtures/images/canvas-test.svg';

test.describe('Optional Image Default Management', () => {
  test.beforeEach(async ({ drupal }) => {
    await drupal.loginAsAdmin();
    await drupal.applyRecipe(`core/recipes/image_media_type`);
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
    await canvas.addComponent(
      {
        id: 'sdc.canvas_test_sdc.image-optional-with-example-and-additional-prop',
      },
      { waitForVisible: false },
    );

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
    await expect(frame.locator('img[alt="A good dog"]')).toBeHidden();

    await expect(defaultImagePreview).toBeHidden();
    await expect(
      imageFieldset.locator('.js-media-library-open-button').first(),
    ).toBeVisible();

    await canvas.openLibraryPanel();
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

    await canvas.openLayersPanel();
    await canvas.openComponent(
      'Canvas test SDC with optional image and heading',
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
    await expect(frame.locator('img[alt="A good dog"]')).toBeHidden();
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
    await canvas.addComponent(
      {
        id: 'sdc.canvas_test_sdc.image-optional-with-example-and-additional-prop',
      },
      { waitForVisible: false },
    );

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

    await canvas.openLibraryPanel();
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
    ).toBeHidden();

    await canvas.openLibraryPanel();
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

    await canvas.openLibraryPanel();
    await canvas.addComponent({
      id: 'sdc.canvas_test_sdc.card',
    });

    frame = await canvas.getActivePreviewFrame();
    const optionalImageComponent = frame.locator(
      '[data-canvas-component-id="js.canvas_test_e2e_code_components_optional_image"]',
    );
    await expect(optionalImageComponent.locator('img')).toHaveCount(0);
  });

  test("SDC: Multiple media widgets — each DefaultImagePreview is scoped to its own prop, required images cannot be deleted, and removing one prop's media leaves the others intact", async ({
    page,
    drupal,
    canvas,
  }) => {
    await drupal.login({ username: 'editor', password: 'editor' });
    const canvasPage = await canvas.createCanvas();
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
    ).toBeHidden();

    // Removing the default from the PRIMARY field must not affect the
    // SECONDARY or the REQUIRED field.
    await primaryPreview.locator('button[aria-label="Remove default"]').click();

    frame = await canvas.getActivePreviewFrame();
    await expect(
      frame.locator('img[alt="Primary default image"]'),
    ).toBeHidden();
    await expect(
      frame.locator('img[alt="Secondary default image"]'),
    ).toBeVisible();
    await expect(
      frame.locator('img[alt="Required default image"]'),
    ).toBeVisible();

    await expect(primaryPreview).toBeHidden();
    await expect(secondaryPreview).toBeVisible();
    await expect(requiredPreview).toBeVisible();

    // The Page data tab must NOT show any DefaultImagePreview widgets,
    // even when a component with multiple image props is selected.
    await page.getByTestId('canvas-contextual-panel--page-data').click();
    const pageDataTab = page.locator('[data-testid="canvas-contextual-panel"]');
    await expect(
      pageDataTab.locator('[class*="defaultImagePreview"]'),
    ).toHaveCount(0);

    // Removing the image from one media prop must not clear the others.
    // The component is still selected, so its form lives under the Settings
    // tab; switch back to it.
    await page.getByTestId('canvas-contextual-panel--settings').click();
    await canvas.addMediaImage(CAT_IMAGE, 'Primary cat', {
      fieldset: primaryFieldset,
    });
    await canvas.addMediaImage(PUB_IMAGE, 'Secondary pub', {
      fieldset: secondaryFieldset,
    });

    await canvas.testInPreviewFrame('img.primary[alt="Primary cat"]', (img) =>
      expect(img).toBeVisible(),
    );
    await canvas.testInPreviewFrame(
      'img.secondary[alt="Secondary pub"]',
      (img) => expect(img).toBeVisible(),
    );

    // Remove the image from the PRIMARY prop only, in the same editing
    // session, without re-selecting the component first.
    await primaryFieldset.locator('[data-canvas-media-remove-button]').click();

    // The secondary image must survive the removal of the primary image.
    await canvas.testInPreviewFrame('img.primary', (img) =>
      expect(img).toBeHidden(),
    );
    await canvas.testInPreviewFrame(
      'img.secondary[alt="Secondary pub"]',
      (img) => expect(img).toBeVisible(),
    );

    // And it must still be in the secondary prop's widget.
    await expect(
      secondaryFieldset.locator('.js-media-library-item'),
    ).toHaveCount(1);

    // The value must also persist: reload the editor (the model is rebuilt
    // from the auto-saved state) and check both the preview and the widgets.
    await canvas.openCanvas(canvasPage);
    await canvas.clickPreviewComponent(
      'sdc.canvas_test_sdc.mixed-images-with-example',
    );

    await canvas.testInPreviewFrame('img.primary', (img) =>
      expect(img).toBeHidden(),
    );
    await canvas.testInPreviewFrame(
      'img.secondary[alt="Secondary pub"]',
      (img) => expect(img).toBeVisible(),
    );

    const fieldsetsAfterReload = contextualPanel.locator(
      'fieldset[data-form-id="component_instance_form"][data-canvas-media-library-fieldset="true"]',
    );
    await expect(fieldsetsAfterReload).toHaveCount(3);
    await expect(
      fieldsetsAfterReload.first().locator('.js-media-library-item'),
    ).toHaveCount(0);
    await expect(
      fieldsetsAfterReload.nth(1).locator('.js-media-library-item'),
    ).toHaveCount(1);
  });

  test('SDC: Remove button is visible and functional after selecting an SVG image', async ({
    page,
    drupal,
    canvas,
  }) => {
    await drupal.loginAsAdmin();
    await drupal.applyRecipe(
      'modules/contrib/canvas/tests/fixtures/recipes/image_media_type_with_svg',
    );
    await drupal.logout();

    await drupal.login({ username: 'editor', password: 'editor' });
    await canvas.openCanvas(await canvas.createCanvas());
    await canvas.openLibraryPanel();
    await canvas.addComponent(
      { id: 'sdc.canvas_test_sdc.image-optional-without-example' },
      { waitForVisible: false },
    );

    const imageFieldset = page.locator(
      '[data-testid="canvas-contextual-panel"] fieldset[data-form-id="component_instance_form"][data-canvas-media-library-fieldset="true"]',
    );
    await expect(imageFieldset).toBeVisible();

    const addButton = imageFieldset.locator(
      '[data-canvas-media-library-open-button="true"]',
    );
    await expect(addButton).toBeVisible();
    await addButton.click();

    await page
      .locator('form[data-drupal-selector^="media-library-add-form-upload"]')
      .locator('input[name="files[upload]"], input[name="files[upload][]"]')
      .setInputFiles(nodePath.join(fileURLToPath(import.meta.url), SVG_IMAGE));

    await page
      .locator('input[name="media[0][fields][field_media_image][0][alt]"]')
      .evaluate((el: HTMLInputElement, value) => {
        el.value = value;
      }, 'A test SVG');

    await page.getByRole('button', { name: 'Save', exact: true }).click();
    await page
      .locator(
        '.media-library-widget-modal input[data-drupal-selector^="edit-media-library-select-form"]',
      )
      .first()
      // eslint-disable-next-line playwright/no-force-option
      .setChecked(true, { force: true });
    await page
      .getByRole('button', { name: 'Insert selected', exact: true })
      .click();

    await expect(imageFieldset.locator('.js-media-library-item')).toHaveCount(
      1,
    );

    const selectedItem = imageFieldset
      .locator('.js-media-library-item')
      .first();
    await expect(selectedItem).toBeVisible();

    const removeButton = selectedItem.locator(
      '[data-canvas-media-remove-button]',
    );
    await expect(removeButton).toBeVisible();

    const [itemBox, buttonBox] = await Promise.all([
      selectedItem.boundingBox(),
      removeButton.boundingBox(),
    ]);
    expect(buttonBox).not.toBeNull();
    expect(itemBox).not.toBeNull();
    expect(buttonBox!.y).toBeGreaterThanOrEqual(itemBox!.y);
    expect(buttonBox!.y + buttonBox!.height).toBeLessThanOrEqual(
      itemBox!.y + itemBox!.height,
    );

    await removeButton.click();

    await expect(imageFieldset.locator('.js-media-library-item')).toHaveCount(
      0,
    );
  });
});
