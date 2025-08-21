// cspell:ignore networkidle
import type { Page } from '@playwright/test';
import { expect } from '@playwright/test';
import nodePath from 'node:path';

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
    await this.page
      .getByTestId('xb-contextual-panel')
      .locator('form')
      .first()
      .waitFor({ state: 'attached', timeout: 30_000 });
    const forms = this.page.getByTestId('xb-contextual-panel').locator('form');
    const count = await forms.count();
    await Promise.race(
      Array.from({ length: count }, (_, i) =>
        forms.nth(i).waitFor({ state: 'visible', timeout: 30_000 }),
      ),
    );

    await expect(this.page.getByTestId('xb-primary-panel')).toContainText(
      /Layers|Library/,
      {
        timeout: 15000,
      },
    );

    await this.page.evaluate(() => {
      return new Promise((resolve) => {
        let timeout;
        const observer = new MutationObserver(() => {
          clearTimeout(timeout);
          timeout = setTimeout(() => {
            observer.disconnect();
            resolve();
          }, 1000);
        });

        const parent = document.querySelector(
          '[data-testid="xb-canvas-scaling"]',
        );
        observer.observe(parent, {
          childList: true,
          subtree: true,
          attributes: true,
        });

        // Observe the iframes.
        const iframes = parent.querySelectorAll('iframe[data-xb-iframe]');
        iframes.forEach((iframe) => {
          try {
            if (iframe.contentDocument) {
              observer.observe(iframe.contentDocument.body, {
                childList: true,
                subtree: true,
                attributes: true,
              });
            }
            // eslint-disable-next-line @typescript-eslint/no-unused-vars
          } catch (e) {
            // Cross-origin iframe
          }
        });

        // Initial timeout
        timeout = setTimeout(() => {
          observer.disconnect();
          resolve();
        }, 1000);
      });
    });

    await expect(
      this.page.locator(
        '[data-testid="xb-canvas-scaling"] iframe[data-test-xb-content-initialized="true"]',
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

  async getActivePreviewFrame() {
    await this.waitForEditorUi();
    return this.page
      .locator(
        '[data-testid="xb-canvas-scaling"] iframe[data-xb-swap-active="true"]',
      )
      .contentFrame();
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

  async openLayersPanel() {
    // Click the library panel first so it doesn't matter if the layers panel
    // is already open or not.
    await this.page.getByTestId('xb-side-menu').getByLabel('Add').click();
    await this.page.getByTestId('xb-side-menu').getByLabel('Layers').click();
    await expect(
      this.page.locator(
        '[data-testid="xb-primary-panel"] h4:has-text("Layers")',
      ),
    ).toBeVisible();
  }

  async openComponent(title: string) {
    await this.page
      .locator('[data-testid="xb-primary-panel"] [data-xb-type="component"]')
      .locator(`text="${title}"`)
      .click();
  }

  async addComponent(componentId: string, hasInputs: boolean = true) {
    // Click the layers panel first so it doesn't matter if the library panel
    // is already open or not.
    await this.page.getByTestId('xb-side-menu').getByLabel('Layers').click();
    await this.page.getByTestId('xb-side-menu').getByLabel('Add').click();

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

    if (hasInputs) {
      const formElement = this.page.locator(
        'form[data-form-id="component_instance_form"]',
      );
      await formElement.waitFor({ state: 'visible' });
    }

    const previewElement = this.page.locator(
      `#xbPreviewOverlay [data-xb-component-id="${componentId}"]`,
    );
    await previewElement.waitFor({ state: 'attached' });
  }

  async editComponentProp(
    propName: string,
    propValue: string,
    propType = 'text',
  ) {
    const inputLocator = `[data-testid="xb-contextual-panel"] [data-drupal-selector="component-instance-form"] .field--name-${propName.toLowerCase()} input`;
    switch (propType) {
      case 'file':
        // For a moment there's 2 file choosers whilst the elements are processed.
        await expect(
          this.page.locator(`${inputLocator}[type="file"]`),
        ).toHaveCount(1);
        await expect(
          this.page.locator(`${inputLocator}[type="file"]`),
        ).toBeVisible();
        await this.page
          .locator(`${inputLocator}[type="file"]`)
          .setInputFiles(nodePath.join(__dirname, propValue));
        await expect(
          this.page.locator(`${inputLocator}[type="file"]`),
        ).not.toBeVisible();
        break;
      default:
        await this.page.locator(inputLocator).fill(propValue);
    }
  }

  async clickPreviewComponent(componentId: string) {
    const previewElement = this.page.locator(
      `#xbPreviewOverlay [data-xb-component-id="${componentId}"]`,
    );
    await previewElement.waitFor({ state: 'attached' });
    await previewElement.click();
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

  async addCodeComponentProp(
    propName: string,
    propType: string,
    example: { label: string; value: string; type: string }[] = [],
    required: boolean = false,
  ) {
    await this.page
      .locator('.xb-mosaic-window-component-data button:has-text("Props")')
      .click();
    await this.page
      .locator('.xb-mosaic-window-component-data')
      .getByRole('button')
      .getByText('Add')
      .click();
    const propForm = this.page
      .locator('.xb-mosaic-window-component-data [data-testid^="prop-"]')
      .last();
    await propForm.locator('[id^="prop-name-"]').fill(propName);
    await propForm.locator('[id^="prop-type-"]').click();
    await this.page
      .locator('body > div > div.rt-SelectContent')
      .getByRole('option', { name: propType, exact: true })
      .click();
    await expect(propForm.locator('[id^="prop-type-"]')).toHaveText(propType);
    const requiredChecked = await propForm
      .locator('[id^="prop-required-"]')
      .getAttribute('data-state');
    if (required && requiredChecked === 'unchecked') {
      await propForm.locator('[id^="prop-required-"]').click();
    }
    if (required) {
      expect(
        await propForm
          .locator('[id^="prop-required-"]')
          .getAttribute('data-state'),
      ).toEqual('checked');
    } else {
      expect(
        await propForm
          .locator('[id^="prop-required-"]')
          .getAttribute('data-state'),
      ).toEqual('unchecked');
    }
    for (const { label, value, type } of example) {
      switch (type) {
        case 'text':
          await propForm
            .locator(
              `label[for^="prop-example-"]:has-text("${label}") + div input[id^="prop-example-"]`,
            )
            .fill(value);
          break;
        case 'select':
          await propForm
            .locator(
              `label[for^="prop-example-"]:has-text("${label}") + button`,
            )
            .click();
          await this.page
            .locator('body > div > div.rt-SelectContent')
            .getByRole('option', { name: value, exact: true })
            .click();
          await expect(
            propForm.locator(
              `label[for^="prop-example-"]:has-text("${label}") + button`,
            ),
          ).toHaveText(value);
          break;
        default:
          throw new Error(`Unknown form element type ${type}`);
      }
    }

    await this.page.waitForResponse(
      (response) =>
        response.url().includes('/xb/api/v0/config/auto-save/js_component/') &&
        response.request().method() === 'PATCH',
    );

    await expect(this.getCodePreviewFrame()).toBeVisible();
  }

  async saveCodeComponent(componentName: string) {
    await this.page.getByRole('button', { name: 'Add to components' }).click();
    await this.page.getByRole('button', { name: 'Add' }).click();
    await this.waitForEditorUi();
    await expect(
      this.page.locator(
        `[data-xb-type="component"][data-xb-component-id="${componentName}"]`,
      ),
    ).toBeVisible();
  }

  getCodePreviewFrame() {
    return this.page
      .locator('.xb-mosaic-window-preview iframe')
      .contentFrame()
      .locator('#xb-code-editor-preview-root');
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

  async exitPreview() {
    await this.page
      .locator('[data-testid="xb-topbar"]')
      .getByRole('button', { name: 'Exit Preview' })
      .click();
    await this.waitForEditorUi();
  }

  async publishAllChanges(expectedTitles: string[] = []) {
    await this.page
      .getByRole('button', { name: /Review \d+ changes?/ })
      .click();
    await expect(async () => {
      await this.page.getByLabel('Select all changes', { exact: true }).click();
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
      await expect(this.page.getByText('All changes published!')).toBeVisible();
    }).toPass({
      // Probe, wait 1s, probe, wait 2s, probe, wait 10s, probe, wait 10s, probe
      intervals: [1_000, 2_000, 10_000],
      // Fail after a minute of trying.
      timeout: 60_000,
    });
  }
}
