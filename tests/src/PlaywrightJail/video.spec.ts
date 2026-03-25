import { readFile } from 'fs/promises';
import { expect } from '@playwright/test';

import { isolatedPerTest as test } from '../../fixtures/test.js';

test.describe('Video Component', () => {
  test.beforeEach(async ({ page }) => {
    await page.route(
      '/modules/contrib/canvas/ui/assets/videos/mountain_wide.mp4',
      async (route) => {
        await route.fulfill({
          path: './tests/fixtures/videos/bear.mp4',
          headers: {
            'content-type': 'video/mp4',
          },
        });
      },
    );
  });

  test('Can use a generic file widget to populate a video prop', async ({
    page,
    drupal,
    canvas,
  }) => {
    await drupal.login({ username: 'editor', password: 'editor' });
    await canvas.openCanvas(await canvas.createCanvas());
    let previewFrame;

    const code = await readFile(
      'tests/fixtures/code_components/videos/Video.jsx',
      'utf-8',
    );
    await canvas.createCodeComponent('Video', code);
    await canvas.addCodeComponentProp('video', 'Video', [
      {
        type: 'select',
        label: 'Example aspect ratio',
        value: '16:9 (Widescreen)',
      },
    ]);
    await canvas.addCodeComponentProp('text', 'Text', [
      { type: 'text', label: 'Example value', value: 'Example Text' },
    ]);
    await canvas.saveCodeComponent('js.video');
    await canvas.addComponent({ id: 'js.video' });

    const formBuildId = await page
      .locator(
        'input[type="hidden"][data-form-id="component_instance_form"][name="form_build_id"]',
      )
      .getAttribute('value');

    // Check hardcoded default values.
    previewFrame = await canvas.getActivePreviewFrame();
    expect(await previewFrame.locator('video').getAttribute('src')).toContain(
      '/ui/assets/videos/mountain_wide',
    );
    expect(
      await previewFrame.locator('video').getAttribute('poster'),
    ).toContain('https://placehold.co/1920x1080.png?text=Widescreen');

    await canvas.editComponentProp(
      'video',
      '../../../../../tests/fixtures/videos/bear.mp4',
      'file',
    );
    previewFrame = await canvas.getActivePreviewFrame();
    await expect(previewFrame.locator('video')).not.toHaveAttribute('poster');

    // Click the remove button to remove the video.
    // @todo Regular .click() doesn't work for some reason.
    await page.evaluate(() => {
      const button = document.querySelector(
        '[data-drupal-selector^="edit-canvas-component-props-"][data-drupal-selector$="-video-0-remove-button"]',
      );

      ['mousedown', 'mouseup', 'click'].forEach((eventType) => {
        button.dispatchEvent(
          new MouseEvent(eventType, {
            bubbles: true,
            cancelable: true,
            view: window,
            button: 0,
          }),
        );
      });
    });
    await expect(
      page.locator(
        '[data-drupal-selector^="edit-canvas-component-props-"][data-drupal-selector$="-video-0-remove-button"]',
      ),
    ).not.toBeVisible();

    // After removal, optional video prop is NULL, so no video element renders.
    previewFrame = await canvas.getActivePreviewFrame();
    await expect(previewFrame.locator('video')).not.toBeVisible();

    // Add a different video
    await canvas.editComponentProp(
      'video',
      '../../../../../tests/fixtures/videos/four-colors.mp4',
      'file',
    );
    await expect(
      page.locator(
        '[data-drupal-selector^="edit-canvas-component-props-"][data-drupal-selector$="-video-0-remove-button"]',
      ),
    ).toBeVisible();

    // Check the form build id was changed.
    expect(
      await page
        .locator(
          'input[type="hidden"][data-form-id="component_instance_form"][name="form_build_id"]',
        )
        .getAttribute('value'),
    ).not.toEqual(formBuildId);

    previewFrame = await canvas.getActivePreviewFrame();
    await expect(previewFrame.locator('video')).not.toHaveAttribute('poster');
    expect(await previewFrame.locator('video').getAttribute('src')).toContain(
      'four-colors',
    );
  });

  test('Can use media to populate a video prop', async ({ drupal, canvas }) => {
    await drupal.loginAsAdmin();
    await drupal.applyRecipe('core/recipes/local_video_media_type');
    await drupal.addPermissions({
      role: 'editor',
      permissions: ['create media'],
    });
    await drupal.logout();
    await drupal.login({ username: 'editor', password: 'editor' });
    await canvas.openCanvas(await canvas.createCanvas());

    // Add the component again. The previous one can't be reused because it needs
    // resaving in order for the media widget to kick in.
    // Also, if test retries occur then we can't assume that the test above has run.
    const code = await readFile(
      'tests/fixtures/code_components/videos/Video.jsx',
      'utf-8',
    );
    await canvas.createCodeComponent('VideoMedia', code);
    await canvas.addCodeComponentProp('video', 'Video', [
      {
        type: 'select',
        label: 'Example aspect ratio',
        value: '16:9 (Widescreen)',
      },
    ]);
    await canvas.addCodeComponentProp('text', 'Text', [
      { type: 'text', label: 'Example value', value: 'Example Text' },
    ]);
    await canvas.saveCodeComponent('js.videomedia');
    await canvas.addComponent({ id: 'js.videomedia' });

    await canvas.addMediaFile('../../../../../fixtures/videos/four-colors.mp4');
    const previewFrame = await canvas.getActivePreviewFrame();
    await expect(previewFrame.locator('video')).not.toHaveAttribute('poster');
    expect(await previewFrame.locator('video').getAttribute('src')).toContain(
      'four-colors',
    );
  });
});
