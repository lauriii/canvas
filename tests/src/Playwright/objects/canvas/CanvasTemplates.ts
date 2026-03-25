import { expect } from '@playwright/test';

import type { CanvasBase } from './CanvasBase.js';

type Constructor<T = {}> = new (...args: any[]) => T;

export function CanvasTemplatesMixin<TBase extends Constructor<CanvasBase>>(
  Base: TBase,
) {
  return class extends Base {
    /*************
     * Templates *
     *************/
    async addTemplate(contentType: string, template: string) {
      await this.page.getByTestId('big-add-template-button').click();
      await expect(
        this.page.getByTestId('canvas-manage-library-add-template-content'),
      ).toBeVisible();
      await this.page.locator('#content-type').click();
      await expect(
        this.page.getByRole('option', { name: contentType }),
      ).toBeVisible();
      await this.page.getByRole('option', { name: contentType }).click();
      await this.page.locator('#template-name').click();
      await expect(
        this.page.getByRole('option', { name: template }),
      ).toBeVisible();
      await this.page.getByRole('option', { name: template }).click();

      await expect(
        this.page
          .getByRole('dialog')
          .getByRole('button', { name: 'Add new template' }),
      ).not.toBeDisabled();
      await this.page
        .getByRole('dialog')
        .getByRole('button', { name: 'Add new template' })
        .click();
      // The dialog should close after adding a template.
      await expect(
        this.page.getByTestId('canvas-manage-library-add-template-content'),
      ).not.toBeVisible();
    }
  };
}
