import nodePath from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect } from '@playwright/test';

import type { FrameLocator } from '@playwright/test';
import type { CanvasBase } from './CanvasBase.js';

type Constructor<T = {}> = new (...args: any[]) => T;

interface HasUtilities {
  getActivePreviewFrame(): Promise<FrameLocator>;
}

export function CanvasComponentsMixin<
  TBase extends Constructor<CanvasBase & HasUtilities>,
>(Base: TBase) {
  return class extends Base {
    /**************
     * Components *
     **************/
    async openComponent(title: string) {
      await this.page
        .locator(
          '[data-testid="canvas-primary-panel"] [data-canvas-type="component"]',
        )
        .locator(`text="${title}"`)
        .click();
    }

    /**
     * Adds a component to the preview by clicking it in .
     *
     * @param identifier An object with either an 'id' (sdc.canvas_test_sdc.card) or 'name' (Hero) property to identify the component.
     * @param options Optional parameters:
     * - hasInputs: If true, waits for the component inputs form to be visible. (default: true)
     *
     * Example usage:
     *   await canvasEditor.addComponent({ name: 'Card' }, { waitForNetworkResponses: true });
     */
    async addComponent(
      identifier: { id?: string; name?: string },
      options: {
        hasInputs?: boolean;
        waitForVisible?: boolean;
      } = { waitForVisible: true },
    ) {
      const { id, name } = identifier;
      const { hasInputs = true } = options;

      let selector, previewSelector;

      if (id) {
        selector = `[data-canvas-type="component"][data-canvas-component-id="${id}"]`;
        previewSelector = `#canvasPreviewOverlay [data-canvas-component-id="${id}"]`;
      } else if (name) {
        selector = `[data-canvas-type="component"][data-canvas-name="${name}"]`;
        previewSelector = `#canvasPreviewOverlay [aria-label="${name}"]`;
      } else {
        throw new Error("Either 'id' or 'name' must be provided.");
      }

      try {
        await expect(
          this.page.getByRole('heading', { name: 'Library' }),
        ).toBeVisible();
      } catch (error) {
        throw new Error(
          'addComponent: Make sure you open the Library panel before calling addComponent.\n' +
            (error instanceof Error ? error.message : String(error)),
        );
      }

      const componentLocator = this.page
        .getByTestId('canvas-primary-panel')
        .locator(selector);

      const existingInstances = this.page.locator(previewSelector);
      const initialCount = await existingInstances.count();
      await componentLocator.hover();
      await componentLocator.getByLabel('Open contextual menu').click();
      await this.page.getByText('Insert').click();

      expect(await this.page.locator(previewSelector).count()).toBe(
        initialCount + 1,
      );

      if (options?.waitForVisible) {
        const updatedInstances = this.page.locator(previewSelector);
        const updatedCount = await updatedInstances.count();
        for (let i = 0; i < updatedCount; i++) {
          await this.page.waitForFunction(
            ([selector, index]) => {
              const element = document.querySelectorAll(selector)[index];
              if (!element) return false;
              const box = element.getBoundingClientRect();
              return box.width > 0 && box.height > 0;
            },
            [previewSelector, i],
          );
        }
      }

      if (hasInputs) {
        const formElement = this.page.locator(
          'form[data-form-id="component_instance_form"]',
        );
        await formElement.waitFor({ state: 'visible' });
      }
    }

    async previewComponent(componentId: string) {
      const component = this.page.locator(
        `#canvasPreviewOverlay [data-canvas-component-id="${componentId}"]`,
      );

      // Directly trigger click events via JavaScript because of webkit
      await component.evaluate((el) => {
        // First ensure element is visible in its container
        el.scrollIntoView({
          behavior: 'instant',
          block: 'center',
          inline: 'center',
        });

        // Create and dispatch the full click sequence
        const mousedownEvent = new MouseEvent('mousedown', {
          view: window,
          bubbles: true,
          cancelable: true,
          button: 0, // Left mouse button
          buttons: 1,
        });

        const mouseupEvent = new MouseEvent('mouseup', {
          view: window,
          bubbles: true,
          cancelable: true,
          button: 0,
          buttons: 0,
        });

        const clickEvent = new MouseEvent('click', {
          view: window,
          bubbles: true,
          cancelable: true,
          button: 0,
          buttons: 0,
        });

        // Dispatch the full sequence: mousedown → mouseup → click
        el.dispatchEvent(mousedownEvent);
        el.dispatchEvent(mouseupEvent);
        el.dispatchEvent(clickEvent);
      });
    }

    async moveComponent(componentName: string, target: string) {
      const component = this.page
        .locator(
          '[data-testid="canvas-primary-panel"] [data-canvas-type="component"]',
        )
        .getByText(componentName);
      const dropzoneLocator = `[data-testid="canvas-primary-panel"] [data-canvas-uuid*="${target}"] [class*="DropZone"]`;
      this.drag(component, dropzoneLocator);
      await expect(
        this.page.locator(
          `[data-testid="canvas-primary-panel"] [data-canvas-type="slot"][data-canvas-uuid*="${target}"]`,
        ),
      ).toContainText(componentName);
    }

    async deleteComponent(componentId: string) {
      const component = this.page.locator(
        `.componentOverlay:has([data-canvas-component-id="${componentId}"])`,
      );
      await expect(component).toHaveCount(1);
      // get the component's data-canvas-uuid attribute value from the child .canvas--sortable-item element
      const componentUuid = await component
        .locator('> .canvas--sortable-item')
        .getAttribute('data-canvas-uuid');

      if (!componentUuid) {
        const html = await component.evaluate((el) => el.outerHTML);
        throw new Error(`data-canvas-uuid is null. Element HTML: ${html}`);
      }

      await expect(
        (await this.getActivePreviewFrame()).locator(
          `[data-canvas-uuid="${componentUuid}"]`,
        ),
      ).toHaveCount(1);
      await this.previewComponent(componentId);
      await this.page.keyboard.press('Delete');
      // Should be gone from the overlay
      await expect(
        this.page.locator(`[data-canvas-uuid="${componentUuid}"]`),
      ).toHaveCount(0);
      // should be gone from inside the preview frame
      await expect(
        (await this.getActivePreviewFrame()).locator(
          `[data-canvas-uuid="${componentUuid}"]`,
        ),
      ).toHaveCount(0);
    }

    async editComponentProp(
      propName: string,
      propValue: string,
      propType = 'text',
    ) {
      const inputLocator = `[data-testid="canvas-contextual-panel"] [data-drupal-selector="component-instance-form"] .field--name-${propName.toLowerCase()} input`;
      const labelLocator = `[data-testid="canvas-contextual-panel"] [data-drupal-selector="component-instance-form"] .field--name-${propName.toLowerCase()} label`;

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
            .setInputFiles(
              nodePath.join(
                nodePath.dirname(fileURLToPath(import.meta.url)),
                propValue,
              ),
            );
          await expect(
            this.page.getByRole('button', { name: 'remove' }),
          ).toBeVisible();
          break;
        default:
          await this.page.locator(inputLocator).fill(propValue);
          // Click the label as autocomplete/link fields will not update until the
          // element has lost focus.
          await this.page.locator(labelLocator).click();
          break;
      }
    }

    async hoverPreviewComponent(componentId: string) {
      const component = this.page.locator(
        `#canvasPreviewOverlay [data-canvas-component-id="${componentId}"]`,
      );
      // Directly trigger mouse events via JavaScript because of webkit.
      await component.evaluate((el) => {
        // First ensure element is visible in its container
        el.scrollIntoView({
          behavior: 'instant',
          block: 'center',
          inline: 'center',
        });

        // Create and dispatch mouse events
        const mouseenterEvent = new MouseEvent('mouseenter', {
          view: window,
          bubbles: true,
          cancelable: true,
        });

        const mouseoverEvent = new MouseEvent('mouseover', {
          view: window,
          bubbles: true,
          cancelable: true,
        });

        el.dispatchEvent(mouseenterEvent);
        el.dispatchEvent(mouseoverEvent);
      });
    }

    async clickPreviewComponent(componentId: string) {
      const component = this.page.locator(
        `#canvasPreviewOverlay [data-canvas-component-id="${componentId}"]`,
      );

      // Directly trigger click events via JavaScript because of webkit
      await component.evaluate((el) => {
        // First ensure element is visible in its container
        el.scrollIntoView({
          behavior: 'instant',
          block: 'center',
          inline: 'center',
        });

        // Create and dispatch the full click sequence
        const mousedownEvent = new MouseEvent('mousedown', {
          view: window,
          bubbles: true,
          cancelable: true,
          button: 0, // Left mouse button
          buttons: 1,
        });

        const mouseupEvent = new MouseEvent('mouseup', {
          view: window,
          bubbles: true,
          cancelable: true,
          button: 0,
          buttons: 0,
        });

        const clickEvent = new MouseEvent('click', {
          view: window,
          bubbles: true,
          cancelable: true,
          button: 0,
          buttons: 0,
        });

        // Dispatch the full sequence: mousedown → mouseup → click
        el.dispatchEvent(mousedownEvent);
        el.dispatchEvent(mouseupEvent);
        el.dispatchEvent(clickEvent);
      });
    }
  };
}
