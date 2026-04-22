/**
 * Utility functions for multivalue form components.
 */

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
  const removeActionCell = tableRow?.querySelector(
    '[data-canvas-remove-button]',
  );

  // Look for the Drupal remove button. Drupal adds these buttons to all rows
  // in unlimited cardinality fields.
  const removeButton = removeActionCell?.querySelector(
    'input[type="submit"]',
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
): string | null => {
  if (!triggerElement) return null;

  // Traverse up from the trigger element to find the containing table row,
  // then locate the Drupal remove button in the .canvas-remove-action cell.
  const tableRow = triggerElement.closest('tr');
  if (!tableRow) return null;

  // Find the original Drupal remove button directly (the hidden input/button
  // that carries the AJAX behavior). The cell and button are hidden by
  // CSS but remain in the DOM.
  const removeActionCell = tableRow.querySelector(
    '[data-canvas-remove-button]',
  );
  if (removeActionCell) {
    const removeButton = removeActionCell.querySelector(
      'input[type="submit"][data-once="drupal-ajax"]',
    ) as HTMLInputElement | null;
    if (removeButton) {
      const buttonName = removeButton.getAttribute('name');
      // Dispatch mousedown first (some Drupal AJAX handlers listen for it),
      // then click — mirroring what Drupal's AJAX system expects.
      const mousedownEvent = new MouseEvent('mousedown', {
        bubbles: true,
        cancelable: true,
        view: window,
      });
      removeButton.dispatchEvent(mousedownEvent);
      removeButton.click();
      return buttonName;
    }
  }

  return null;
};

/**
 * Programmatically set an input value and trigger React's onChange pipeline.
 *
 * Uses the native HTMLInputElement prototype setter to set the value, then
 * calls onChange (and optionally onBlur) directly via the React fiber props
 * attached to the DOM node. This bypasses React's internal value tracker which
 * would otherwise suppress the change event.
 */
export const dispatchSyntheticChange = (
  input: HTMLInputElement,
  value: string,
  blur: boolean = true,
): void => {
  const nativeInputValueSetter = Object.getOwnPropertyDescriptor(
    window.HTMLInputElement.prototype,
    'value',
  )?.set;

  nativeInputValueSetter?.call(input, value);

  const propsKey = Object.keys(input).find((k) => k.startsWith('__reactProps'));
  const reactProps = propsKey ? (input as any)[propsKey] : null;

  if (reactProps?.onChange) {
    const event = new Event('change');
    Object.defineProperty(event, 'target', { writable: false, value: input });
    reactProps.onChange(event);
  } else {
    input.dispatchEvent(new Event('input', { bubbles: true }));
    input.dispatchEvent(new Event('change', { bubbles: true }));
  }
  if (blur) {
    if (reactProps?.onBlur) {
      const event = new Event('blur');
      Object.defineProperty(event, 'target', { writable: false, value: input });
      reactProps.onBlur(event);
    } else {
      input.dispatchEvent(new Event('blur', { bubbles: true }));
    }
  }
};

/**
 * Returns true when jQuery UI autocomplete menu is currently visible.
 */
export const isAutocompleteMenuOpen = (input: HTMLInputElement): boolean => {
  const $jq =
    typeof window !== 'undefined' && (window as any).jQuery
      ? (window as any).jQuery
      : null;
  return !!(
    $jq && $jq(input).data('ui-autocomplete')?.menu?.element?.is?.(':visible')
  );
};
