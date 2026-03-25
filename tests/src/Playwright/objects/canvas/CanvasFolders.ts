import { expect } from '@playwright/test';

import type { CanvasBase } from './CanvasBase.js';

type Constructor<T = {}> = new (...args: any[]) => T;

export function CanvasFoldersMixin<TBase extends Constructor<CanvasBase>>(
  Base: TBase,
) {
  return class extends Base {
    /***********
     * Folders *
     ***********/
    async addFolder(name: string) {
      // Open the New dropdown
      await this.page.getByTestId('canvas-page-list-new-button').click({
        force: true,
        timeout: 10000,
      });

      // Wait for dropdown to be visible
      await expect(
        this.page.getByTestId('canvas-library-new-folder-button'),
      ).toBeVisible();

      // Click Add folder option
      await this.page.getByTestId('canvas-library-new-folder-button').click();

      // Wait for the folder input to appear
      const folderInput = this.page.getByTestId(
        'canvas-manage-library-new-folder-name',
      );
      await expect(folderInput).toBeVisible();
      await folderInput.clear();
      await folderInput.fill(name);
      await folderInput.press('Enter');
      // Wait for folder creation to complete (input should disappear)
      await expect(folderInput).not.toBeVisible();

      // Verify the folder was created.
      await expect(
        this.page.locator(`[data-canvas-folder-name="${name}"]`),
      ).toBeVisible();
    }

    async expandFolder(name: string) {
      const folder = this.page.locator(`[data-canvas-folder-name="${name}"]`);
      await expect(folder).toBeVisible();

      // Ensure the row is on screen before trying to toggle collapse state.
      await folder.scrollIntoViewIfNeeded();

      const expandToggle = this.page.locator(
        `[aria-label="Expand ${name} folder"]`,
      );
      if ((await expandToggle.count()) > 0) {
        await expandToggle.first().click({ force: true });
      }
    }
  };
}
