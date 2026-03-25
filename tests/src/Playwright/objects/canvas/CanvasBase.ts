import { expect } from '@playwright/test';

import type { Drupal } from '@drupal/playwright';
import type { Page } from '@playwright/test';

export class CanvasBase {
  readonly page: Page;
  readonly initializedReadyPreviewIframeSelector =
    '[data-test-canvas-content-initialized="true"][data-canvas-swap-active="true"]';

  constructor({ drupal }: { drupal: Drupal }) {
    this.page = drupal.page;
  }

  /**
   * Wait for Canvas UI elements to load.
   */
  async waitForContextualPanel() {
    await expect(
      this.page.getByTestId('canvas-contextual-panel'),
    ).toBeAttached();
    await expect(
      this.page.getByTestId('canvas-contextual-panel').locator('form').first(),
    ).toBeAttached();
  }

  async waitForCanvasSideMenu() {
    await expect(this.page.getByTestId('canvas-side-menu')).toBeAttached();
  }

  async waitForCanvasTopbar() {
    await expect(this.page.getByTestId('canvas-topbar')).toBeAttached();
  }

  async waitForEditorFrame() {
    await expect(
      this.page.locator('.canvasEditorFrameScalingContainer'),
    ).toHaveCSS('opacity', '1');

    await expect(
      this.page.locator(this.initializedReadyPreviewIframeSelector),
    ).toBeAttached();

    const iframeElement = await this.page.$(
      this.initializedReadyPreviewIframeSelector,
    );
    const contentDocumentExists = await iframeElement?.evaluate((el) => {
      return !!(el as HTMLIFrameElement).contentDocument;
    });
    expect(contentDocumentExists).toBe(true);
  }

  async waitForEditorUi() {
    await this.waitForCanvasSideMenu();
    await this.waitForCanvasTopbar();
    await this.waitForContextualPanel();
    await this.waitForEditorFrame();
  }
}
