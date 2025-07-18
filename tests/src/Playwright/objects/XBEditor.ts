// cspell:ignore networkidle
import { expect, Page } from '@playwright/test';

export class XBEditor {
  readonly page: Page;

  constructor({ page }: { page: Page }) {
    this.page = page;
  }

  async getSettings() {
    return await this.page.evaluate(() => {
      return window.drupalSettings;
    });
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
        timeout: 15000,
      },
    );
    await expect(this.page.getByTestId('xb-primary-panel')).toContainText(
      'Content',
      {
        timeout: 15000,
      },
    );

    await this.page.waitForFunction(
      async () => {
        const element = document.querySelector(
          'iframe[data-xb-swap-active="true"]',
        );
        if (!element) return false;

        // Check if it's been stable for a short period
        const currentValue = element.getAttribute('data-xb-swap-active');
        if (currentValue !== 'true') return false;
        await new Promise((resolve) => setTimeout(resolve, 1000));

        // Check again after the delay to confirm stability
        return element.getAttribute('data-xb-swap-active') === 'true';
      },
      { timeout: 10000 },
    );

    await expect(
      this.page.locator(
        '[data-testid="xb-canvas-scaling"] iframe[data-xb-swap-active="true"]',
      ),
    ).toHaveCSS('opacity', '1');
    await expect(
      this.page.locator(
        '[data-testid="xb-canvas-scaling"] iframe[data-xb-swap-active="false"]',
      ),
    ).toHaveCSS('opacity', '0');
  }

  async goToEditor() {
    const path = await this.getEditorPath();
    await this.page.goto(path);
    await this.waitForEditorUi();
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
    await this.openLibraryPanel();
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

  async addCodeComponent(componentName: string, code: string) {
    await this.openLibraryPanel();
    await this.page
      .locator('[data-testid="xb-primary-panel"]')
      .getByText('Add new')
      .click();
    await this.page.fill('#componentName', componentName);
    await this.page
      .locator('.rt-BaseDialogContent button')
      .getByText('Add')
      .click();
    await expect(
      this.page.locator('[data-testid="xb-mosaic-container"]'),
    ).toBeVisible();
    const codeEditor = this.page.locator(
      '.xb-mosaic-window-editor div[role="textbox"]',
    );
    await codeEditor.waitFor({ state: 'visible' });
    await expect(codeEditor).toContainText(
      'for documentation on how to build a code component',
    );
    await codeEditor.selectText();
    await this.page.keyboard.press('Delete');
    await codeEditor.fill(code);
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
}
