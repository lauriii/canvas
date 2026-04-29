import { expect } from '@playwright/test';

import type { Locator } from '@playwright/test';
import type { CanvasBase } from './CanvasBase.js';

type Constructor<T = {}> = new (...args: any[]) => T;

export function CanvasUtilitiesMixin<TBase extends Constructor<CanvasBase>>(
  Base: TBase,
) {
  return class extends Base {
    async getActivePreviewFrame() {
      await this.waitForEditorUi();
      return this.page
        .locator(
          '[data-testid="canvas-editor-frame-scaling"] iframe[data-test-canvas-content-initialized="true"][data-canvas-swap-active="true"]',
        )
        .contentFrame();
    }

    /**
     * Test content in the preview frame with automatic retries.
     * The frame is re-queried on each retry to handle frame swaps.
     *
     * @param selector - The selector to locate within the preview frame
     * @param fn - A function that receives the locator and runs expects
     *
     * @example
     * await canvas.testInPreviewFrame('#list li', async (items) => {
     *   await expect(items).toHaveCount(2);
     *   await expect(items.nth(0)).toContainText('text');
     * });
     */
    async testInPreviewFrame(
      selector: string,
      fn: (locator: Locator) => Promise<void>,
    ): Promise<void> {
      await expect(async () => {
        const frame = await this.getActivePreviewFrame();
        const locator = frame.locator(selector);
        await fn(locator);
      }).toPass();
    }

    /**
     * Returns the <head> element from the preview iframe.
     */
    async getIframeHead(
      iframeSelector = '[data-test-canvas-content-initialized="true"][data-canvas-swap-active="true"]',
    ) {
      return this.page
        .locator(iframeSelector)
        .evaluateHandle(
          (iframe: HTMLIFrameElement) => iframe.contentDocument?.head,
          { timeout: 10000 },
        );
    }
  };
}
