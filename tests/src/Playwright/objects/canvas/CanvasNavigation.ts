import { expect } from '@playwright/test';

import type { CanvasBase } from './CanvasBase.js';

type Constructor<T = {}> = new (...args: any[]) => T;

export function CanvasNavigationMixin<TBase extends Constructor<CanvasBase>>(
  Base: TBase,
) {
  return class extends Base {
    async openCanvasRoot() {
      const response = await this.page.goto('/canvas');
      if (!response || response.status() !== 200) {
        console.error(response);
        console.error('status', response?.status);
        throw new Error("Canvas didn't load");
      }

      await this.waitForCanvasSideMenu();
      await this.waitForCanvasTopbar();
    }

    async createCanvas({ title }: { title?: string } = {}) {
      await this.openCanvasRoot();
      await this.page.getByTestId('canvas-navigation-button').click();
      const newButton = this.page.getByTestId('canvas-navigation-new-button');
      await expect(newButton).toBeVisible();
      await newButton.click();
      const newPageButton = this.page.getByTestId(
        'canvas-navigation-new-page-button',
      );
      await expect(newPageButton).toBeVisible();
      await newPageButton.click();
      const titleInput = this.page.locator(
        '[data-drupal-selector="edit-title-0-value"]',
      );
      await expect(titleInput).toBeHidden();
      await expect(titleInput).toBeVisible();
      await this.waitForEditorUi();
      const url = this.page.url();
      const match = url.match(/\/canvas\/editor\/([^/]+)\/(\d+)$/);
      const [, entityType, entityId] = match!;
      const entityIdNumber = parseInt(entityId, 10);

      if (title) {
        // Fill the title input
        await this.page.fill(
          '[data-drupal-selector="edit-title-0-value"]',
          title,
        );

        // Check the navigation button reflects the new value
        await expect(
          this.page.locator('[data-testid="canvas-navigation-button"]'),
        ).toContainText(title);
      }
      return { entity_type: entityType, entity_id: entityIdNumber };
    }

    async openCanvas({
      entity_type,
      entity_id,
    }: {
      entity_type: string;
      entity_id: number;
    }) {
      await this.page.goto(`/canvas/editor/${entity_type}/${entity_id}`);
      await this.page.waitForLoadState('domcontentloaded');
      await expect(
        this.page.getByTestId('canvas-contextual-panel--page-data'),
      ).toBeVisible();
      await expect(this.page.getByTestId('canvas-side-menu')).toBeVisible();
      await expect(
        this.page.getByTestId('canvas-publish-review'),
      ).toBeVisible();
      await expect(
        this.page
          .locator(
            '[data-testid="canvas-empty-region-drop-zone-content"], [data-testid="canvas-name-tag"], [data-testid="canvas-region-drop-zone-start-content"]',
          )
          .first(),
      ).toBeVisible();
    }

    /**********************
     * Main UI navigation *
     **********************/
    async openLibraryPanel() {
      await this.page
        .getByTestId('canvas-side-menu')
        .getByLabel('Library')
        .click();

      await expect(
        this.page.getByTestId('canvas-components-library-loading'),
      ).not.toBeVisible();
      try {
        await expect(
          this.page.getByRole('heading', { name: 'Library' }),
        ).toBeVisible();
      } catch (error) {
        throw new Error(
          'openLibraryPanel: Library panel did not open - was it already open?\n' +
            (error instanceof Error ? error.message : String(error)),
        );
      }

      // Ensure we are on the Components tab.
      await this.page
        .getByTestId('canvas-library-components-tab-select')
        .click();
    }

    async openLayersPanel() {
      await this.page
        .getByTestId('canvas-side-menu')
        .getByLabel('Layers')
        .click();

      try {
        await expect(
          this.page.getByRole('heading', { name: 'Layers' }),
        ).toBeVisible();
      } catch (error) {
        throw new Error(
          'openLayersPanel: Layers panel did not open - was it already open?\n' +
            (error instanceof Error ? error.message : String(error)),
        );
      }
    }

    async openCodePanel() {
      await this.page
        .getByTestId('canvas-side-menu')
        .getByLabel('Code')
        .click();

      try {
        await expect(
          this.page.getByRole('heading', { name: 'Code' }),
        ).toBeVisible();
        await expect(
          this.page.locator('[data-testid="canvas-code-panel-content"]'),
        ).toBeVisible();
      } catch (error) {
        throw new Error(
          'openCodePanel: Code panel did not open - was it already open?\n' +
            (error instanceof Error ? error.message : String(error)),
        );
      }
    }

    async openPagesPanel() {
      await this.page
        .getByTestId('canvas-side-menu')
        .getByLabel('Pages')
        .click();
      try {
        await expect(
          this.page.getByRole('heading', { name: 'Pages' }),
        ).toBeVisible();
        await expect(
          this.page.locator('[data-testid="canvas-page-list"]'),
        ).toBeVisible();
      } catch (error) {
        throw new Error(
          'openPagesPanel: Pages panel did not open - was it already open?\n' +
            (error instanceof Error ? error.message : String(error)),
        );
      }
    }

    async openTemplatesPanel() {
      await this.page
        .getByTestId('canvas-side-menu')
        .getByLabel('Templates')
        .click();
      try {
        await expect(
          this.page.getByRole('heading', { name: 'Templates' }),
        ).toBeVisible();
        await expect(
          this.page.locator('[data-testid="big-add-template-button"]'),
        ).toBeVisible();
      } catch (error) {
        throw new Error(
          'openTemplatesPanel: Templates panel did not open - was it already open?\n' +
            (error instanceof Error ? error.message : String(error)),
        );
      }
    }

    async openContentNavigation() {
      await this.page.getByTestId('canvas-navigation-button').click();
      await expect(
        this.page.locator('#canvas-navigation-search'),
      ).toBeVisible();
    }

    async closeContentNavigation() {
      await expect(async () => {
        await this.page.keyboard.press('Escape');
        await this.page.keyboard.press('Escape');
        await expect(
          this.page.getByTestId('canvas-navigation-content'),
        ).not.toBeVisible();
      }).toPass();
    }

    async openPreview() {
      await this.page
        .locator('[data-testid="canvas-topbar"]')
        .getByRole('button', { name: 'Preview' })
        .click();
      await this.page.waitForLoadState('domcontentloaded');
      // Wait for no DOM mutations for a period.
      await this.page.waitForFunction(() => {
        const iframe = document.querySelector(
          'iframe[class^="_PagePreviewIframe"]',
        );
        const iframeDocument =
          iframe.contentDocument || iframe.contentWindow.document;
        return iframeDocument.querySelector('main')?.children.length > 0;
      });
      await this.page
        .locator('iframe[class^="_PagePreviewIframe"]')
        .contentFrame()
        .locator('main')
        .waitFor({ state: 'visible' });
    }

    async closePreview() {
      await this.page
        .locator('[data-testid="canvas-topbar"]')
        .getByRole('button', { name: 'Exit Preview' })
        .click();
      await this.waitForEditorUi();
    }

    async publishAllChanges(expectedTitles: string[] = []) {
      await this.page
        .getByRole('button', { name: /Review \d+ changes?/ })
        .click();
      await expect(async () => {
        await this.page
          .getByLabel('Select all changes', { exact: true })
          .click();
        if (expectedTitles.length > 0) {
          await Promise.all(
            expectedTitles.map(async (title: string) =>
              expect(
                await this.page.getByLabel(`Select change ${title}`),
              ).toBeChecked(),
            ),
          );
        }
        await this.page
          .getByRole('button', { name: /Publish \d+ selected?/ })
          .click();
        await expect(
          this.page.getByText('All changes published!'),
        ).toBeVisible();
      }).toPass({
        // Probe, wait 1s, probe, wait 2s, probe, wait 10s, probe, wait 10s, probe
        intervals: [1_000, 2_000, 10_000],
        // Fail after a minute of trying.
        timeout: 60_000,
      });
    }
  };
}
