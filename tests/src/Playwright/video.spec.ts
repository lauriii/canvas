import { expect } from '@playwright/test';
import { test } from './fixtures/DrupalSite';
import { getModuleDir } from './utilities/DrupalFilesystem';
import { readFile } from 'fs/promises';
import { Drupal } from './objects/Drupal';

// cspell:ignore videomedia

test.describe('Video Component', () => {
  test.beforeAll(
    'Setup test site with Experience Builder',
    async ({ browser, drupalSite }) => {
      const page = await browser.newPage();
      const drupal: Drupal = new Drupal({ page, drupalSite });
      await drupal.installModules(['experience_builder']);
      await page.close();
    },
  );

  test('Can use a generic file widget to populate a video prop', async ({
    page,
    drupal,
    xBEditor,
  }) => {
    await drupal.loginAsAdmin();
    await drupal.createXbPage('Video Test', '/video-test');
    await page.goto('/video-test');
    await xBEditor.goToEditor();
    let previewFrame;

    const moduleDir = await getModuleDir();
    const code = await readFile(
      `${moduleDir}/experience_builder/tests/fixtures/code_components/videos/Video.jsx`,
      'utf-8',
    );
    await xBEditor.createCodeComponent('Video', code);
    await xBEditor.addCodeComponentProp('video', 'Video', [
      {
        type: 'select',
        label: 'Example aspect ratio',
        value: '16:9 (Widescreen)',
      },
    ]);
    await xBEditor.addCodeComponentProp('text', 'Text', [
      { type: 'text', label: 'Example value', value: 'Example Text' },
    ]);
    await xBEditor.saveCodeComponent('js.video');
    await xBEditor.addComponent({ id: 'js.video' });

    const formBuildId = await page
      .locator(
        'input[type="hidden"][data-form-id="component_instance_form"][name="form_build_id"]',
      )
      .getAttribute('value');

    // Check hardcoded default values.
    previewFrame = await xBEditor.getActivePreviewFrame();
    expect(await previewFrame.locator('video').getAttribute('src')).toContain(
      '/ui/assets/videos/mountain_wide',
    );
    expect(
      await previewFrame.locator('video').getAttribute('poster'),
    ).toContain('https://placehold.co/1920x1080.png?text=Widescreen');

    await xBEditor.editComponentProp(
      'video',
      // @todo move this to tests/fixtures/videos
      '../../../../ui/assets/videos/mountain_wide.mp4',
      'file',
    );
    previewFrame = await xBEditor.getActivePreviewFrame();
    await expect(previewFrame.locator('video')).not.toHaveAttribute('poster');

    // Click the remove button to remove the video.
    // @todo Regular .click() doesn't work for some reason.
    await page.evaluate(() => {
      const button = document.querySelector(
        '[data-drupal-selector^="edit-xb-component-props-"][data-drupal-selector$="-video-0-remove-button"]',
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
        '[data-drupal-selector^="edit-xb-component-props-"][data-drupal-selector$="-video-0-remove-button"]',
      ),
    ).not.toBeVisible();

    // Back to the default, which has a poster image.
    previewFrame = await xBEditor.getActivePreviewFrame();
    await expect(previewFrame.locator('video')).toHaveAttribute('poster');

    // Add a different video
    await xBEditor.editComponentProp(
      'video',
      // @todo move this to tests/fixtures/videos
      '../../../../ui/assets/videos/bird_vertical.mp4',
      'file',
    );
    await expect(
      page.locator(
        '[data-drupal-selector^="edit-xb-component-props-"][data-drupal-selector$="-video-0-remove-button"]',
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

    previewFrame = await xBEditor.getActivePreviewFrame();
    await expect(previewFrame.locator('video')).not.toHaveAttribute('poster');
    expect(await previewFrame.locator('video').getAttribute('src')).toContain(
      'bird_vertical',
    );
  });

  test('Can use media to populate a video prop', async ({
    page,
    drupal,
    xBEditor,
  }) => {
    await drupal.applyRecipe('core/recipes/local_video_media_type');
    await drupal.loginAsAdmin();
    await drupal.createXbPage('Video Media Test', '/video-media-test');
    await page.goto('/video-media-test');
    await xBEditor.goToEditor();

    // Add the component again. The previous one can't be reused because it needs
    // resaving in order for the media widget to kick in.
    // Also, if test retries occur then we can't assume that the test above has run.
    const moduleDir = await getModuleDir();
    const code = await readFile(
      `${moduleDir}/experience_builder/tests/fixtures/code_components/videos/Video.jsx`,
      'utf-8',
    );
    await xBEditor.createCodeComponent('VideoMedia', code);
    await xBEditor.addCodeComponentProp('video', 'Video', [
      {
        type: 'select',
        label: 'Example aspect ratio',
        value: '16:9 (Widescreen)',
      },
    ]);
    await xBEditor.addCodeComponentProp('text', 'Text', [
      { type: 'text', label: 'Example value', value: 'Example Text' },
    ]);
    await xBEditor.saveCodeComponent('js.videomedia');
    await xBEditor.addComponent({ id: 'js.videomedia' });

    await drupal.addMediaGenericFile(
      '../../../../ui/assets/videos/bird_vertical.mp4',
    );
    const previewFrame = await xBEditor.getActivePreviewFrame();
    await expect(previewFrame.locator('video')).not.toHaveAttribute('poster');
    expect(await previewFrame.locator('video').getAttribute('src')).toContain(
      'bird_vertical',
    );
  });
});
