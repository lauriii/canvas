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
      await expect(
        this.page.getByTestId('canvas-page-list-new-button'),
      ).toBeVisible();
      await this.page.getByTestId('canvas-page-list-new-button').click();

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

    async deleteFolder(name: string) {
      try {
        await this.page.locator(`[data-canvas-folder-name="${name}"]`).hover();
        await this.page
          .locator(`[data-canvas-folder-name="${name}"]`)
          .getByRole('button', { name: 'Menu' })
          .click();

        // Click Delete folder option.
        await this.page
          .getByRole('menuitem', { name: 'Delete folder' })
          .click();

        // Wait for folder to be deleted.
        await expect(
          this.page.locator(`[data-canvas-folder-name="${name}"]`),
        ).not.toBeAttached({ timeout: 10000 });
      } catch (error) {
        throw new Error(
          'deleteFolder: Folder did not delete - is it empty?\n' +
            (error instanceof Error ? error.message : String(error)),
        );
      }
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

    async moveComponentIntoFolder(componentName: string, folder: string) {
      const componentLocator = `[data-testid="canvas-primary-panel"] [data-canvas-name="${componentName}"]`;
      const dropzoneLocator = `[data-testid="canvas-primary-panel"] [data-canvas-folder-name="${folder}"]`;
      await this.drag(componentLocator, dropzoneLocator);
      const newComponentLocation = this.page
        .locator(
          `[data-testid="canvas-primary-panel"] [data-canvas-folder-name="${folder}"]`,
        )
        .locator('..')
        .locator(`[data-canvas-name="${componentName}"]`);

      await expect(newComponentLocation).toBeVisible();
      await expect(newComponentLocation).toContainText(componentName);
    }

    async moveComponentOutOfFolder(componentName: string, folder: string) {
      const componentLocator = `[data-testid="canvas-primary-panel"] [data-canvas-name="${componentName}"]`;
      const dropzoneLocator = `[data-testid="canvas-primary-panel"] [data-testid="canvas-uncategorized-drop-zone-js_component"]`;
      await this.drag(componentLocator, dropzoneLocator);
      const newComponentLocation = this.page
        .locator(
          `[data-testid="canvas-primary-panel"] [data-canvas-folder-name="${folder}"]`,
        )
        .locator('..')
        .locator(`[data-canvas-name="${componentName}"]`);

      await expect(newComponentLocation).toBeVisible();
      await expect(newComponentLocation).toContainText(componentName);
    }
  };
}
