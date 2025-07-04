// cspell:ignore networkidle
import { expect, Page } from '@playwright/test';

export class XBEditor {
  readonly page: Page;

  constructor({ page }: { page: Page }) {
    this.page = page;
  }

  async getSettings() {
    const value = await this.page.evaluate(() => {
      return window.drupalSettings;
    });
    return value;
  }

  async getEditorPath() {
    const bodyClass = await this.page.locator('body').getAttribute('class');
    const hasXBPageClass = bodyClass?.includes('xb-page');
    const drupalSettings = await this.getSettings();
    if (hasXBPageClass) {
      return `${drupalSettings.path.baseUrl}xb/xb_${drupalSettings.path.currentPath}`;
    } else {
      return `${drupalSettings.path.baseUrl}xb/${drupalSettings.path.currentPath}/editor`;
    }
  }

  async waitForEditorUi() {
    await expect(this.page.getByTestId('xb-contextual-panel')).toContainText(
      'Title',
      {
        timeout: 15_000,
      },
    );
    await expect(this.page.getByTestId('xb-primary-panel')).toContainText(
      'Content',
      {
        timeout: 15_000,
      },
    );
  }

  /**
   * Waits for the XB preview iframe to be initialized and active, and for the canvas to be ready.
   * Mirrors the Cypress `previewReady` command.
   *
   * @returns Locator for the iframe element
   */
  async waitForPreviewReady() {
    const iframeSelector =
      '[data-test-xb-content-initialized="true"][data-xb-swap-active="true"]';

    // Wait for the canvas scaling container to be fully visible
    await expect(this.page.locator('.xbCanvasScalingContainer')).toHaveCSS(
      'opacity',
      '1',
    );
    await expect(
      this.page.locator('.xb--region-overlay__content'),
    ).toBeAttached();
    // The idea behind iframeSelector selector is that the data-xb-swap-active part ensures we are selecting the
    // correct/visible iFrame (because there are two that swap back and forth). The
    // data-test-xb-content-initialized="true" part ensures that XB has marked the iframe as ready.
    await expect(this.page.locator(iframeSelector)).toBeAttached({
      timeout: 10000,
    });
    await this.page.waitForFunction((selector) => {
      const el = document.querySelector(selector) as HTMLIFrameElement;
      return !!el && !!el.contentDocument;
    }, iframeSelector);

    return this.page.locator(iframeSelector);
  }

  async goToEditor() {
    const path = await this.getEditorPath();
    await this.page.goto(path);
    await this.waitForEditorUi();
    // Wait for the preview iframe and canvas to be ready
    await this.waitForPreviewReady(this.page);
  }

  /**
   * Opens the Library Panel by clicking the "Add" button in the side menu.
   * Waits for the library loading indicator to disappear.
   *
   */
  async openLibraryPanel() {
    await this.page.getByTestId('xb-side-menu').getByLabel('Add').click();
    await expect(
      this.page.getByTestId('xb-components-library-loading'),
    ).not.toBeVisible();
    await expect(
      this.page.locator(
        '[data-testid="xb-primary-panel"] h4:has-text("Library")',
      ),
    ).toBeVisible();
    const components = this.page.locator(
      '[data-testid="xb-primary-panel"] [data-xb-type="component"]',
    );
    const count = await components.count();
    for (let i = 0; i < count; i++) {
      await components.nth(i).waitFor({ state: 'visible' });
    }
    const button = this.page
      .locator('button')
      .filter({ hasText: /^Components$/ });
    const buttonExpanded =
      (await button.getAttribute('aria-expanded')) === 'true';
    if (!buttonExpanded) {
      await button.click();
    }
  }

  async addComponent(componentId: string) {
    this.openLibraryPanel();
    const component = this.page.locator(
      `[data-xb-type="component"][data-xb-component-id="${componentId}"]`,
    );
    await component.click();
    await Promise.all([
      this.page.waitForResponse(
        (response) =>
          response.url().includes('/xb/api/v0/layout/') &&
          response.request().method() === 'POST',
      ),
      this.page.waitForResponse(
        (response) =>
          response.url().includes('/xb/api/v0/form/component-instance/') &&
          response.request().method() === 'PATCH',
      ),
      this.page.waitForResponse(
        (response) =>
          response.url().includes('/xb/api/v0/auto-saves/pending') &&
          response.request().method() === 'GET',
      ),
    ]);

    const formElement = this.page.locator(
      'form[data-form-id="component_inputs_form"]',
    );
    await formElement.waitFor({ state: 'visible' });

    const previewElement = this.page.locator(
      `#xbPreviewOverlay [data-xb-component-id="${componentId}"]`,
    );
    await previewElement.waitFor({ state: 'attached' });
  }

  async preview() {
    await this.page
      .locator('[data-testid="xb-topbar"]')
      .getByRole('button', { name: 'Preview' })
      .click();
    await this.page
      .locator('iframe[class^="_PagePreviewIframe"]')
      .contentFrame()
      .locator('.layout-container')
      .waitFor({ state: 'visible' });
    await this.page.waitForLoadState('networkidle');
    await this.page
      .locator('iframe[class^="_PagePreviewIframe"]')
      .contentFrame()
      .locator('main')
      .waitFor({ state: 'visible' });
  }

  /**
   * Opens the Layers Panel by clicking the "Layers" button in the side menu.
   */
  async openLayersPanel(): Promise<void> {
    await this.page.getByTestId('xb-side-menu').getByLabel('Layers').click();
    await this.page;
  }
}
