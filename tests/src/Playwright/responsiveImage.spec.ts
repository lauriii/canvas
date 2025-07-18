import { expect } from '@playwright/test';
import { test } from './fixtures/DrupalSite';

/**
 * Tests the correct size of image is fetched.
 */
test.describe('Responsive Image', () => {
  test('Setup minimal test site with Experience Builder', async ({
    drupal,
  }) => {
    await drupal.applyRecipe(`core/recipes/image_media_type`);
    await drupal.setupMinimalXBTestSite();
  });
  test('Test responsive image', async ({ page, drupal, xBEditor }) => {
    await drupal.loginAsAdmin();
    await page.goto('/homepage');
    await xBEditor.goToEditor();
    await xBEditor.addComponent('sdc.xb_test_sdc.image');

    const frame = page
      .locator('[data-testid="xb-canvas-scaling"] [data-xb-swap-active="true"]')
      .contentFrame();
    await expect(frame.locator('img.image')).toBeVisible();
    await drupal.addMedia(
      '../../../fixtures/images/gracie-big.jpg',
      'A cute dog',
    );
    await page.waitForFunction(() => {
      const frame: HTMLIFrameElement = document.querySelector(
        '[data-testid="xb-canvas-scaling"] [data-xb-swap-active="true"]',
      );
      const img = frame?.contentWindow.document.querySelector('img.image');
      const imgSrc = img?.getAttribute('src');
      return imgSrc && imgSrc.includes('gracie');
    });
    frame.locator('img.image').waitFor({ state: 'visible' });
    await xBEditor.preview();

    const previewIframe = page
      .locator('iframe[class^="_PagePreviewIframe"]')
      .contentFrame();
    const previewImg = await previewIframe.locator('img.image');
    const previewSrc = await previewImg.getAttribute('src');
    expect(previewSrc).toContain('gracie');

    const previewSrcset = await previewImg.getAttribute('srcset');
    const defaultWidths = [
      16, 32, 48, 64, 96, 128, 256, 384, 640, 750, 828, 1080, 1200, 1920, 2048,
    ];
    const matches = previewSrcset.match(/itok=[^&\s]+/g);
    expect(matches).toHaveLength(defaultWidths.length);
    defaultWidths.forEach((width) => {
      expect(previewSrcset).toContain(
        `/styles/xb_parametrized_width--${width}`,
      );
      expect(previewSrcset).toContain(` ${width}w`);
    });

    // The default widths also contain 3840 but the source image is smaller so it shouldn't be generated.
    expect(previewSrcset).not.toContain('/styles/xb_parametrized_width--3840');
    expect(previewSrcset).not.toContain(' 3840w');

    await previewIframe.locator('img.image').waitFor({ state: 'attached' });
    await page.waitForLoadState('networkidle');
    const src = previewIframe.locator('img.image');
    const currentSrc = await src.evaluate(
      (image: HTMLImageElement) => image.currentSrc,
    );
    await expect(currentSrc).toContain('gracie');
    await expect(currentSrc).toContain('/styles/xb_parametrized_width--');
  });
});
