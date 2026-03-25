import { expect } from '@playwright/test';

import type { CanvasBase } from './CanvasBase.js';

type Constructor<T = {}> = new (...args: any[]) => T;

interface HasNavigation {
  openLibraryPanel(): Promise<void>;
  waitForEditorUi(): Promise<void>;
}

export function CanvasCodeComponentsMixin<
  TBase extends Constructor<CanvasBase & HasNavigation>,
>(Base: TBase) {
  return class extends Base {
    /*******************
     * Code components *
     *******************/
    getCodePreviewFrame() {
      return this.page
        .locator('iframe[data-canvas-iframe="canvas-code-editor-preview"]')
        .contentFrame()
        .locator('#canvas-code-editor-preview-root');
    }

    async createCodeComponent(componentName: string, code: string) {
      await this.openLibraryPanel();
      await this.page.getByTestId('canvas-page-list-new-button').click();

      await this.page
        .getByTestId('canvas-library-new-code-component-button')
        .click();

      await this.page.fill('#componentName', componentName);
      await this.page
        .locator('.rt-BaseDialogContent button')
        .getByText('Create')
        .click();
      await expect(
        this.page.locator('[data-testid="canvas-code-editor-main-panel"]'),
      ).toBeVisible();
      const codeEditor = this.page.locator(
        '[data-testid="canvas-code-editor-main-panel"] div[role="textbox"]',
      );
      await expect(codeEditor).toBeVisible();
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
        .locator(
          '[data-testid="canvas-code-editor-component-data-panel"] button:has-text("Props")',
        )
        .click();
      await this.page
        .getByTestId('canvas-code-editor-component-data-panel')
        .getByRole('button')
        .getByText('Add')
        .click();
      const propForm = this.page
        .locator(
          '[data-testid="canvas-code-editor-component-data-panel"] [data-testid^="prop-"]',
        )
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
          response
            .url()
            .includes('/canvas/api/v0/config/auto-save/js_component/') &&
          response.request().method() === 'PATCH',
      );

      await expect(this.getCodePreviewFrame()).toBeVisible();
    }

    async saveCodeComponent(componentName: string) {
      await this.page
        .getByRole('button', { name: 'Add to components' })
        .click();
      await this.page.getByRole('button', { name: 'Add' }).click();
      await this.waitForEditorUi();
      await this.openLibraryPanel();
      await expect(
        this.page.locator(
          `[data-canvas-type="component"][data-canvas-component-id="${componentName}"]`,
        ),
      ).toBeVisible();
    }
  };
}
