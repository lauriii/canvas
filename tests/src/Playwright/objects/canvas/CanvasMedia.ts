import { readFileSync } from 'node:fs';
import nodePath from 'node:path';
import { fileURLToPath } from 'node:url';
import path from 'path';
import { expect } from '@playwright/test';

import type { Locator } from '@playwright/test';
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
      const addButton = this.page.locator(
        '[data-canvas-media-library-open-button="true"][data-form-id="component_instance_form"][data-once="drupal-ajax"]',
      );
      await expect(addButton).toBeVisible();
      await addButton.click();

      await this.page
        .locator('form[data-drupal-selector^="media-library-add-form-upload"]')
        .locator('input[name="files[upload]"], input[name="files[upload][]"]')
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
        this.page
          .locator(
            '[data-testid="canvas-contextual-panel"] .js-media-library-item-preview img',
          )
          .last(),
      ).toHaveAttribute('alt', alt);
    }

    async dropFile(dropZone: Locator, filePath: string, mimeType: string) {
      const buffer = readFileSync(filePath);
      const base64 = buffer.toString('base64');

      await dropZone.dispatchEvent('dragenter');
      await dropZone.dispatchEvent('dragover');

      await dropZone.dispatchEvent('drop', {
        dataTransfer: await this.page.evaluateHandle(
          (data) => {
            const dt = new DataTransfer();
            const byteString = atob(data.base64);
            const bytes = new Uint8Array(byteString.length);
            for (let i = 0; i < byteString.length; i++) {
              bytes[i] = byteString.charCodeAt(i);
            }
            const file = new File([bytes], data.name, { type: data.mimeType });
            dt.items.add(file);
            return dt;
          },
          { base64, name: path.basename(filePath), mimeType },
        ),
      });
    }
  };
}
