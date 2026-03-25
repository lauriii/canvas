import nodePath from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect } from '@playwright/test';

import type { CanvasBase } from './CanvasBase.js';

type Constructor<T = {}> = new (...args: any[]) => T;

export function CanvasMediaMixin<TBase extends Constructor<CanvasBase>>(
  Base: TBase,
) {
  return class extends Base {
    /**
     * Media.
     */
    async addMediaFile(path: string) {
      await this.page
        .locator(
          '[data-testid="canvas-contextual-panel"] input[value="Add media"]',
        )
        .first() // @todo shouldn't need this but Canvas is currently rendering two fields
        .click();
      await this.page
        .locator(
          'form[data-drupal-selector^="media-library-add-form-upload"] input[name="files[upload]"]',
        )
        .setInputFiles(nodePath.join(fileURLToPath(import.meta.url), path));
      await this.page
        .getByRole('button', { name: 'Save', exact: true })
        .click();
      // @todo select the item we just uploaded rather than the first.
      await this.page
        .locator(
          '.media-library-widget-modal input[data-drupal-selector^="edit-media-library-select-form"]',
        )
        .first()
        .setChecked(true, { force: true });
      await this.page
        .getByRole('button', { name: 'Insert selected', exact: true })
        .click();
      await expect(
        this.page.locator(
          '[data-testid="canvas-contextual-panel"] .js-media-library-item input[data-canvas-media-remove-button="true"]',
        ),
      ).toBeVisible();
    }

    async addMediaImage(path: string, alt: string) {
      await this.page.getByRole('button', { name: 'Add media' }).click();

      await this.page
        .locator(
          'form[data-drupal-selector^="media-library-add-form-upload"] input[name="files[upload]"]',
        )
        .setInputFiles(nodePath.join(fileURLToPath(import.meta.url), path));

      // It should be possible to set the alt text with the following, but there's currently a bug
      // await this.page.getByLabel('Alternative text').fill('A cute dog');
      // instead we use the evaluate method to set the value directly.
      // https://www.drupal.org/project/canvas/issues/3535215
      await this.page
        .locator('input[name="media[0][fields][field_media_image][0][alt]"]')
        .evaluate((el: HTMLInputElement, value) => {
          el.value = value;
        }, alt);

      await this.page
        .getByRole('button', { name: 'Save', exact: true })
        .click();
      // @todo select the item we just uploaded rather than the first.
      await this.page
        .locator(
          '.media-library-widget-modal input[data-drupal-selector^="edit-media-library-select-form"]',
        )
        .first()
        .setChecked(true, { force: true });
      await this.page
        .getByRole('button', { name: 'Insert selected', exact: true })
        .click();
      await expect(
        this.page.locator(
          '[data-testid="canvas-contextual-panel"] .js-media-library-item-preview img',
        ),
      ).toHaveAttribute('alt', alt);
    }
  };
}
