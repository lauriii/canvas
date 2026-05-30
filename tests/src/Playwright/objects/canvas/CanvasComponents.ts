import nodePath from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect } from '@playwright/test';

import type { FrameLocator, Locator } from '@playwright/test';
import type { CanvasBase } from './CanvasBase.js';

/**
 * Format a date string for display using Intl.DateTimeFormat.
 * Replicates the logic in DrupalDatetimeMultivalueForm.tsx.
 */
function formatDateForDisplay(value: string): string {
  if (!value) return 'Empty';
  try {
    const date = new Date(value + 'T00:00:00');
    return new Intl.DateTimeFormat().format(date);
  } catch {
    return value;
  }
}

/**
 * Format time for display using Intl.DateTimeFormat.
 * Replicates the logic in DrupalDatetimeMultivalueForm.tsx.
 */
function formatTimeForDisplay(value: string): string {
  if (!value) return '';
  try {
    const date = new Date(`2000-01-01T${value}`);
    const parts = value.split(':');
    const hasNonZeroSeconds =
      parts.length === 3 && parts[2] !== '00' && parts[2] !== '00.000';
    return new Intl.DateTimeFormat(undefined, {
      hour: 'numeric',
      minute: 'numeric',
      second: hasNonZeroSeconds ? 'numeric' : undefined,
      hour12: true,
    }).format(date);
  } catch {
    return value;
  }
}

/**
 * Format datetime for display (date + time combined).
 * Replicates the logic in DrupalDatetimeMultivalueForm.tsx.
 */
function formatDatetimeForDisplay(date: string, time: string): string {
  if (!date && !time) return 'Empty';
  const formattedDate = date ? formatDateForDisplay(date) : '';
  const formattedTime = time ? formatTimeForDisplay(time) : '';
  if (date && time) {
    return `${formattedDate}, ${formattedTime}`;
  }
  return formattedDate || formattedTime || 'Empty';
}

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

      await expect(this.page.locator(previewSelector)).toHaveCount(
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
        case 'file': {
          const fileInput = this.page.locator(`${inputLocator}[type="file"]`);
          // For a moment there's 2 file choosers whilst the elements are processed.
          await expect(fileInput).toHaveCount(1);
          await expect(fileInput).toBeVisible();
          // Wait until Drupal's fileAutoUpload behavior has attached its change
          // listener to the input (marked with data-once="auto-file-upload").
          // Setting the file before then fires no upload AJAX, so the "remove"
          // button never appears and the test times out (flaky failure).
          await expect(fileInput).toHaveAttribute(
            'data-once',
            /(^|\s)auto-file-upload(\s|$)/,
          );
          await fileInput.setInputFiles(
            nodePath.join(
              nodePath.dirname(fileURLToPath(import.meta.url)),
              propValue,
            ),
          );
          await expect(
            this.page.getByRole('button', { name: 'remove' }),
          ).toBeVisible();
          break;
        }
        default:
          await this.page.locator(inputLocator).fill(propValue);
          // Click the label as autocomplete/link fields will not update until the
          // element has lost focus.
          await this.page.locator(labelLocator).click();
          break;
      }
    }

    async editMultiValueProp(
      propName: string,
      propValue: string,
      propPosition: number,
      propType = 'string',
    ) {
      const field = this.page
        .locator(
          `.field--type-${propType} [data-canvas-multiple-values="true"]`,
        )
        .filter({
          has: this.page.getByRole('heading', { name: propName }),
        });
      await expect(field).toBeVisible();
      const row = field.locator('tr.draggable').nth(propPosition);
      await row.getByRole('button', { name: /^Edit/ }).click();
      const popover = row.getByRole('dialog');
      const displayName = propName.endsWith('*')
        ? propName.slice(0, -1)
        : propName;
      await expect(
        popover.getByText(displayName, { exact: true }),
      ).toBeVisible();

      // Set up auto-save listener.
      const autoSavePromise = this.page.waitForResponse(
        (response) =>
          response.url().includes('/canvas/api/v0/layout/canvas_page/') &&
          response.request().method() === 'PATCH',
      );

      switch (propType) {
        case 'string':
          await popover.getByRole('textbox').fill(propValue);
          await popover.getByRole('textbox').press('Enter');
          break;
      }

      await autoSavePromise;
      // eslint-disable-next-line playwright/no-networkidle
      await this.page.waitForLoadState('networkidle'); // drain any follow-on requests after the auto-save PATCH

      // Verify text in the Settings pane is updated.
      await expect(
        field
          .locator('tr.draggable')
          .nth(propPosition)
          .locator('[data-canvas-multivalue-label="true"]'),
      ).toHaveText(propValue);
    }

    async editMultiValueDatetimeProp(
      propName: string,
      dateValue: string,
      timeValue: string,
      propPosition: number,
    ) {
      const field = this.page
        .locator(`.field--type-datetime [data-canvas-multiple-values="true"]`)
        .filter({
          has: this.page.getByRole('heading', { name: propName }),
        });
      await expect(field).toBeVisible();
      const row = field.locator('tr.draggable').nth(propPosition);
      await row.getByRole('button', { name: /^Edit/ }).click();
      const popover = row.getByRole('dialog');
      const displayName = propName.endsWith('*')
        ? propName.slice(0, -1)
        : propName;
      await expect(
        popover.getByText(displayName, { exact: true }),
      ).toBeVisible();

      // Set up auto-save listener.
      const autoSavePromise = this.page.waitForResponse(
        (response) =>
          response.url().includes('/canvas/api/v0/layout/canvas_page/') &&
          response.request().method() === 'PATCH',
      );

      await popover.locator('input[type="date"]').fill(dateValue);
      await popover.locator('input[type="time"]').fill(timeValue);
      await popover.locator('input[type="time"]').press('Enter');

      await autoSavePromise;
      // eslint-disable-next-line playwright/no-networkidle
      await this.page.waitForLoadState('networkidle');

      // Verify text in the Settings pane is updated.
      const expectedLabel = formatDatetimeForDisplay(dateValue, timeValue);
      await expect(
        row.locator('[data-canvas-multivalue-label="true"]'),
      ).toHaveText(expectedLabel);
    }

    async editMultiValueDateProp(
      propName: string,
      dateValue: string,
      propPosition: number,
    ) {
      const field = this.page
        .locator(`.field--type-datetime [data-canvas-multiple-values="true"]`)
        .filter({
          has: this.page.getByRole('heading', { name: propName }),
        });
      await expect(field).toBeVisible();
      const row = field.locator('tr.draggable').nth(propPosition);
      await row.getByRole('button', { name: /^Edit/ }).click();
      const popover = row.getByRole('dialog');
      const displayName = propName.endsWith('*')
        ? propName.slice(0, -1)
        : propName;
      await expect(
        popover.getByText(displayName, { exact: true }),
      ).toBeVisible();

      // Set up auto-save listener.
      const autoSavePromise = this.page.waitForResponse(
        (response) =>
          response.url().includes('/canvas/api/v0/layout/canvas_page/') &&
          response.request().method() === 'PATCH',
      );

      await popover.locator('input[type="date"]').fill(dateValue);
      await popover.locator('input[type="date"]').press('Enter');

      await autoSavePromise;
      // eslint-disable-next-line playwright/no-networkidle
      await this.page.waitForLoadState('networkidle');

      // Verify text in the Settings pane is updated.
      const expectedLabel = formatDateForDisplay(dateValue);
      await expect(
        row.locator('[data-canvas-multivalue-label="true"]'),
      ).toHaveText(expectedLabel);
    }

    async reorderMultiValueProp(propName: string, from: number, to: number) {
      const field = this.page
        .locator('[data-canvas-multiple-values="true"]')
        .filter({
          has: this.page.getByRole('heading', { name: propName }),
        });
      await expect(field).toBeVisible();
      await field.scrollIntoViewIfNeeded();
      const dragHandle = (row: Locator) =>
        row.locator('.canvas-drag-handle a.tabledrag-handle');

      const rows = field.locator('tr.draggable');

      const fromHandle = dragHandle(rows.nth(from));
      await expect(async () => {
        const pointerEvents = await fromHandle.evaluate(
          (el) => window.getComputedStyle(el).pointerEvents,
        );
        expect(pointerEvents).not.toBe('none');
      }).toPass();

      // Set up auto-save listener.
      const autoSavePromise = this.page.waitForResponse(
        (response) =>
          response.url().includes('/canvas/api/v0/layout/canvas_page/') &&
          response.request().method() === 'PATCH',
      );

      const toHandle = dragHandle(rows.nth(to));
      await fromHandle.dragTo(toHandle);
      await autoSavePromise;
      // eslint-disable-next-line playwright/no-networkidle
      await this.page.waitForLoadState('networkidle'); // drain any follow-on requests after the auto-save PATCH
    }

    async addMultiValueProp(
      propName: string,
      propValue: string | null = null,
      propType: string = 'string',
    ) {
      const field = this.page
        .locator(
          `.field--type-${propType} [data-canvas-multiple-values="true"]`,
        )
        .filter({
          has: this.page.getByRole('heading', { name: propName }),
        });
      await expect(field).toBeVisible();
      const originalRowCount = await field.locator('tr.draggable').count();
      await field.getByRole('button', { name: '+ Add new' }).click();

      // Wait for all drag handles to be visible again.
      const dragHandles = field.locator('.canvas-drag-handle');
      await expect(dragHandles.last()).toBeVisible();

      await expect(async () => {
        const newRowCount = field.locator('tr.draggable');
        await expect(newRowCount).toHaveCount(originalRowCount + 1);
      }).toPass();

      await expect(
        field
          .locator('tr.draggable')
          .last()
          .locator('[data-canvas-multivalue-label="true"]'),
      ).toHaveText('Empty');

      if (propValue) {
        await this.editMultiValueProp(
          propName,
          propValue,
          originalRowCount,
          propType,
        );
      }
    }

    async removeMultiValueProp(propName: string, propPosition: number) {
      const field = this.page
        .locator('[data-canvas-multiple-values="true"]')
        .filter({
          has: this.page.getByRole('heading', { name: propName }),
        });
      await expect(field).toBeVisible();

      const row = field.locator('tr.draggable').nth(propPosition);
      await row.getByRole('button', { name: /^Edit/ }).click();
      const popover = row.getByRole('dialog');
      const displayName = propName.endsWith('*')
        ? propName.slice(0, -1)
        : propName;
      await expect(
        popover.getByText(displayName, { exact: true }),
      ).toBeVisible();
      await expect(
        popover.getByRole('button', { name: 'Remove' }),
      ).toBeEnabled();

      // Set up auto-save listener.
      const autoSavePromise = this.page.waitForResponse(
        (response) =>
          response.url().includes('/canvas/api/v0/layout/canvas_page/') &&
          response.request().method() === 'PATCH',
      );

      const rowCountBefore = await field.locator('tr.draggable').count();
      await popover.getByRole('button', { name: 'Remove' }).click();

      await autoSavePromise;
      // eslint-disable-next-line playwright/no-networkidle
      await this.page.waitForLoadState('networkidle'); // drain any follow-on requests after the auto-save PATCH
      await expect(field.locator('tr.draggable')).toHaveCount(
        rowCountBefore - 1,
      );
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
