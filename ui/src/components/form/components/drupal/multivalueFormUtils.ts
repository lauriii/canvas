/**
 * Utility functions for multivalue form components.
 */

import type {
  EvaluatedComponentModel,
  ResolvedValues,
} from '@/features/layout/layoutModelSlice';

/**
 * Determine if the remove button should be enabled for a multivalue field item.
 *
 * This function checks:
 * 1. Whether a Drupal remove button exists and is enabled
 * 2. If the field is required and has only one item (prevents removal)
 *
 * @param triggerElement - The DOM element that triggers the popover (should be inside a table row)
 * @returns boolean - true if the remove button should be enabled, false otherwise
 */
export const isRemoveButtonEnabled = (
  triggerElement: HTMLElement | null,
): boolean => {
  if (!triggerElement) return false;

  // Check whether the table row has a Drupal remove button.
  const tableRow = triggerElement.closest('tr');
  const removeActionCell = tableRow?.querySelector('.canvas-remove-action');

  // Look for the Drupal remove button. Drupal adds these buttons to all rows
  // in unlimited cardinality fields.
  const removeButton = removeActionCell?.querySelector(
    'input[type="submit"][name*="remove_button"]',
  ) as HTMLInputElement | null;

  // Check if button exists and is not disabled
  if (!removeButton || removeButton.disabled) {
    return false;
  }

  // Get the field wrapper that contains row count and required status.
  // These are set by canvas_stark_preprocess_field_multiple_value_form.
  const fieldWrapperRowCount = tableRow?.closest('[data-canvas-row-count]');
  if (!fieldWrapperRowCount) {
    return true;
  }

  const rowCount = parseInt(
    fieldWrapperRowCount.getAttribute('data-canvas-row-count') || '0',
    10,
  );
  // Check if the field is required by looking for .form-required class.
  // This class is added by Drupal to the label or field wrapper.
  if (tableRow) {
    const table = tableRow.closest('table');
    const fieldWrapper = table?.closest('.js-form-wrapper, .form-item');
    const isRequired =
      fieldWrapper?.querySelector('.form-required, .js-form-required') !== null;

    // Disable remove button if required field with only one item.
    if (isRequired && rowCount === 1) {
      return false;
    }
  }

  return true;
};

/**
 * Normalize the _weight selects/inputs of a multivalue table so that each
 * element's submitted value matches the visual DOM row order.
 *
 * The problem: after a wrong-order AJAX rebuild, PHP renders rows in the order
 * it sorted them (e.g. delta-1 first, delta-0 second). The <select> elements
 * keep their original delta-based names (field[0][_weight], field[1][_weight])
 * but now appear in the wrong DOM positions. Simply writing 0,1,2 by DOM
 * position won't help because the names don't match their positions — PHP
 * would still sort wrong.
 *
 * The correct fix: set each weight element's value equal to its DOM row index,
 * AND swap the name attributes so name[N][_weight] corresponds to the Nth DOM
 * row. PHP then receives delta-0=0, delta-1=1, etc. and sorts to the current
 * visual order.
 *
 * Call this immediately before triggering any AJAX action (add or remove).
 *
 * @param triggerElement - Any element inside the multivalue field wrapper.
 */
export const normalizeRowWeights = (
  triggerElement: HTMLElement | null,
): void => {
  if (!triggerElement) return;
  const tbody = triggerElement
    .closest('[data-canvas-multiple-values]')
    ?.querySelector('table.field-multiple-table tbody');
  if (!tbody) return;

  // Only operate on real (non-optimistic) draggable rows.
  const rows = [
    ...tbody.querySelectorAll<HTMLTableRowElement>('tr.draggable'),
  ].filter((row) => !row.hasAttribute('data-canvas-optimistic'));

  // Weight fields render as <select> when delta <= weight_select_max (15),
  // otherwise as <input type="number">. Collect them in DOM order.
  const weightEls = rows.map((row) =>
    row.querySelector<HTMLSelectElement | HTMLInputElement>(
      '[name*="_weight"]',
    ),
  );

  // Extract the delta from each element's name, e.g. "field[1][_weight]" → 1.
  // Then assign names sequentially (0, 1, 2…) to match DOM row order, and
  // set the corresponding value so PHP receives delta-N = weight N.
  const deltaPattern = /\[(\d+)\]\[_weight\]/;
  weightEls.forEach((el, i) => {
    if (!el) return;
    el.name = el.name.replace(deltaPattern, `[${i}][_weight]`);
    el.value = String(i);
  });
};

/**
 * Trigger the Drupal remove button for a multivalue field row.
 *
 * This function finds and clicks the hidden Drupal remove button that carries
 * the AJAX behavior. The button is hidden by CSS but remains in the DOM.
 *
 * @param triggerElement - The DOM element that triggers the action (should be inside a table row)
 * @returns string | null - The name attribute of the remove button if found and triggered, null otherwise
 */
export const triggerDrupalRemoveButton = (
  triggerElement: HTMLElement | null,
  formId: string | null = '',
): string | null => {
  if (!triggerElement) return null;

  // Traverse up from the trigger element to find the containing table row,
  // then locate the Drupal remove button in the .canvas-remove-action cell.
  const tableRow = triggerElement.closest('tr');
  if (!tableRow) return null;
  document.body.setAttribute('data-canvas-ajax-behaviors', 'true');

  // Effectively hide the row, but maintain a pixel of height so the popover
  // can still anchor to its position.
  tableRow.style.height = '1px';
  tableRow.style.overflow = 'hidden';
  tableRow.style.visibility = 'collapse';

  normalizeRowWeights(triggerElement);

  // Find the original Drupal remove button directly (the hidden input/button
  // that carries the AJAX behavior). The cell and button are hidden by
  // CSS but remain in the DOM.
  const removeActionCell = tableRow.querySelector('.canvas-remove-action');
  if (removeActionCell) {
    const removeButton = removeActionCell.querySelector(
      'input[type="submit"][name*="remove_button"][data-once="drupal-ajax"]',
    ) as HTMLInputElement | null;
    if (removeButton) {
      if (formId !== 'component_instance_form') {
        // Dispatch mousedown first (some Drupal AJAX handlers listen for it),
        // then click — mirroring what Drupal's AJAX system expects.
        const mousedownEvent = new MouseEvent('mousedown', {
          bubbles: true,
          cancelable: true,
          view: window,
        });
        removeButton.dispatchEvent(mousedownEvent);
        removeButton.click();
      }
      const buttonName = removeButton.getAttribute('name');

      return buttonName;
    }
  }

  return null;
};

/**
 * Extract the numeric index of an item in a multivalue field from its input name.
 *
 * Given a field name like `field_example[0][value]` and a prop name like
 * `field_example`, returns 0.
 *
 * @param name - The full input name attribute value
 * @param propName - The resolved prop name for the field
 * @returns The zero-based index, or null if it cannot be determined
 */
export const extractPositionFromFieldName = (
  name: string,
  propName: string,
): number | null => {
  if (!name || !propName) return null;

  const escapedPropName = propName.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  const match = name.match(new RegExp(`\\[${escapedPropName}\\]\\[(\\d+)\\]`));
  if (match && match[1]) {
    return parseInt(match[1], 10);
  }
  return null;
};

/**
 * Build a patched EvaluatedComponentModel with a specific array item removed.
 *
 * Creates a shallow-cloned model with the item at `position` spliced out of
 * the `propName` array in `resolved` (and left untouched elsewhere).
 *
 * @param model - The current evaluated component model
 * @param propName - The prop whose array value should be modified
 * @param position - The zero-based index of the item to remove
 * @returns A new model object with the item removed
 */
export const buildModelWithItemRemoved = (
  model: EvaluatedComponentModel,
  propName: string,
  position: number,
): EvaluatedComponentModel => {
  const oldValue: unknown[] = (model.resolved[propName] as unknown[]) || [];
  const newValue = [...oldValue];
  newValue.splice(position, 1);

  return {
    ...model,
    source: model.source,
    resolved: {
      ...model.resolved,
      [propName]: newValue,
    } as ResolvedValues,
  };
};
