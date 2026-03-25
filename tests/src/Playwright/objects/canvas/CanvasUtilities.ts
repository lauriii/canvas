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
          '[data-testid="canvas-editor-frame-scaling"] iframe[data-canvas-swap-active="true"]',
        )
        .contentFrame();
    }

    /**
     * Returns the <head> element from the preview iframe.
     */
    async getIframeHead(
      iframeSelector = '[data-test-canvas-content-initialized="true"][data-canvas-swap-active="true"]',
    ) {
      const iframeHandle = await this.page.waitForSelector(iframeSelector, {
        timeout: 10000,
      });
      const headHandle = await iframeHandle.evaluateHandle(
        (iframe: HTMLIFrameElement) => {
          return iframe.contentDocument?.head;
        },
      );
      return headHandle;
    }
  };
}
