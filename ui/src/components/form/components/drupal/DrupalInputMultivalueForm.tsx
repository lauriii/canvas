import { useEffect, useRef, useState } from 'react';
import clsx from 'clsx';
import { ArrowRightIcon, Cross2Icon, TrashIcon } from '@radix-ui/react-icons';
import { Box, Button, Flex, Popover, Text } from '@radix-ui/themes';

import TextField from '@/components/form/components/TextField';
import InputBehaviors from '@/components/form/inputBehaviors';
import { a2p } from '@/local_packages/utils';

import type { NumericInputAttributes } from '@/types/DrupalAttribute';

import styles from './DrupalInputMultivalueForm.module.css';

// Create the wrapped TextField component with InputBehaviors HOC.
const TextFieldWithBehaviors = InputBehaviors(TextField);

/**
 * DrupalInputMultivalueForm component for inputs within multivalue widgets.
 *
 * This component displays a compact list item with drag handle that opens
 * an edit popover when clicked. The design matches the multivalue field
 * pattern with:
 * - List view: drag handle + text preview
 * - Edit popover: label, close button, input field, remove button
 */
const DrupalInputMultivalueForm = ({
  attributes = {},
}: {
  attributes?: NumericInputAttributes & {
    onChange?: (e: React.ChangeEvent<HTMLInputElement>) => void;
    value?: string;
    name?: string;
    id?: string;
    'data-field-label'?: string;
  };
}) => {
  // Get the initial value from attributes
  const initialValue = attributes.value || attributes.defaultValue || '';
  // Manage local state to sync display value with input changes
  const [displayValue, setDisplayValue] = useState<string>(
    initialValue as string,
  );
  // Temporary value for the popover input (only committed on Enter).
  const [tempValue, setTempValue] = useState<string>(initialValue as string);
  const inputWrapperRef = useRef<HTMLDivElement | null>(null);
  // Ref for the trigger area that lives inside the actual table row.
  const triggerRowRef = useRef<HTMLDivElement | null>(null);
  // Controlled popover state so we can close it programmatically on remove.
  const [popoverOpen, setPopoverOpen] = useState(false);
  const fieldLabel = attributes['data-field-label'] || '';
  // Ref for the popover trigger to restore focus after closing.
  const triggerRef = useRef<HTMLButtonElement | null>(null);
  // Ref for the popover input to focus it when opening.
  const popoverInputRef = useRef<HTMLInputElement | null>(null);
  // Ref to the Box containing the TextField so we can find the input element
  const popoverTextFieldWrapperRef = useRef<HTMLDivElement | null>(null);

  // Sync displayValue with attributes.value when it changes (e.g., after AJAX updates)
  useEffect(() => {
    const newValue = attributes.value || attributes.defaultValue || '';
    setDisplayValue(newValue as string);
    setTempValue(newValue as string);
  }, [attributes.value, attributes.defaultValue]);

  // Find and store reference to the actual input element when popover opens
  useEffect(() => {
    if (popoverOpen && popoverTextFieldWrapperRef.current) {
      const inputElement =
        popoverTextFieldWrapperRef.current.querySelector('input');
      if (inputElement) {
        popoverInputRef.current = inputElement;
        // Focus and select the input
        inputElement.focus();
        inputElement.select();
      }
    }
  }, [popoverOpen]);

  // Handle temporary input changes in popover (not committed until Enter).
  const handleTempInputChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    setTempValue(e.target.value);
  };

  // Commit the temporary value to the actual input and display.
  const handleCommitValue = () => {
    // Validate the input using the popover input element's HTML5 validation
    if (popoverInputRef.current) {
      // Check if the input is valid according to its constraints (min, max, step, etc.)
      if (!popoverInputRef.current.checkValidity()) {
        // Show the browser's native validation message
        popoverInputRef.current.reportValidity();
        return; // Don't commit invalid value
      }
    }

    const newValue = tempValue;
    setDisplayValue(newValue);

    // Update the hidden real input field to keep it in sync.
    if (inputWrapperRef.current) {
      const realInput = inputWrapperRef.current.querySelector(
        'input',
      ) as HTMLInputElement | null;
      if (realInput && realInput.value !== newValue) {
        const nativeInputValueSetter = Object.getOwnPropertyDescriptor(
          HTMLInputElement.prototype,
          'value',
        )?.set;
        nativeInputValueSetter?.call(realInput, newValue);
        // Trigger both input and change events so React and Drupal handlers are notified.
        const inputEvent = new Event('input', { bubbles: true });
        const changeEvent = new Event('change', { bubbles: true });
        realInput.dispatchEvent(inputEvent);
        realInput.dispatchEvent(changeEvent);
      }
    }
  };

  // Handle Enter key press in popover input.
  const handleKeyDown = (e: React.KeyboardEvent<HTMLInputElement>) => {
    if (e.key === 'Enter') {
      e.preventDefault();

      // Use the stored ref or fallback to the event target
      const inputElement =
        popoverInputRef.current || (e.target as HTMLInputElement);

      // Validate the input before committing
      if (inputElement && !inputElement.checkValidity()) {
        // Show validation error and keep popover open
        inputElement.reportValidity();
        return;
      }

      handleCommitValue();
      setPopoverOpen(false);
    }
  };

  // Handle popover open/close - reset tempValue when opening,
  // revert when closing without commit.
  const handlePopoverOpenChange = (open: boolean) => {
    if (open) {
      // When opening, set tempValue to current displayValue.
      setTempValue(displayValue);
    } else {
      // When closing, revert tempValue to displayValue (discards uncommitted changes).
      setTempValue(displayValue);
      // Restore focus to the trigger button after closing.
      setTimeout(() => {
        if (triggerRef.current) {
          triggerRef.current.focus();
        }
      }, 0);
    }
    setPopoverOpen(open);
  };

  // Determine if the remove button should be enabled.
  const isRemoveButtonEnabled = () => {
    // Check whether the table row has a Drupal remove button.
    const tableRow = triggerRowRef.current?.closest('tr');
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

    // Get the field wrapper that contains row count and required status
    // These are set by canvas_stark_preprocess_field_multiple_value_form
    const fieldWrapperRowCount = tableRow?.closest('[data-canvas-row-count]');
    if (!fieldWrapperRowCount) {
      return true;
    }

    const rowCount = parseInt(
      fieldWrapperRowCount.getAttribute('data-canvas-row-count') || '0',
      10,
    );
    // Check if the field is required by looking for .form-required class
    // This class is added by Drupal to the label or field wrapper
    if (tableRow) {
      const table = tableRow.closest('table');
      const fieldWrapper = table?.closest('.js-form-wrapper, .form-item');
      const isRequired =
        fieldWrapper?.querySelector('.form-required, .js-form-required') !==
        null;

      // Disable remove button if required field with only one item
      if (isRequired && rowCount === 1) {
        return false;
      }
    }

    return true;
  };

  const handleRemove = () => {
    // Close the popover first so the portal is cleaned up before AJAX replaces the DOM.
    setPopoverOpen(false);

    if (!triggerRowRef.current) return;

    // Traverse up from the trigger element to find the containing table row,
    // then locate the Drupal remove button in the .canvas-remove-action cell.
    const tableRow = triggerRowRef.current.closest('tr');
    if (!tableRow) return;

    // Find the original Drupal remove button directly (the hidden input/button
    // that carries the AJAX behavior). The cell and button are hidden by
    // CSS but remain in the DOM.
    const removeActionCell = tableRow.querySelector('.canvas-remove-action');
    if (removeActionCell) {
      const removeButton = removeActionCell.querySelector(
        'input[type="submit"][name*="remove_button"]',
      ) as HTMLElement | null;
      if (removeButton) {
        // Dispatch mousedown first (some Drupal AJAX handlers listen for it),
        // then click — mirroring what Drupal's AJAX system expects.
        const mousedownEvent = new MouseEvent('mousedown', {
          bubbles: true,
          cancelable: true,
          view: window,
        });
        removeButton.dispatchEvent(mousedownEvent);
        removeButton.click();
        return;
      }
    }

    setDisplayValue('');
    if (!inputWrapperRef.current) return;
    const inputElement = inputWrapperRef.current.querySelector(
      'input',
    ) as HTMLInputElement | null;
    if (inputElement) {
      const nativeInputValueSetter = Object.getOwnPropertyDescriptor(
        HTMLInputElement.prototype,
        'value',
      )?.set;
      nativeInputValueSetter?.call(inputElement, '');
      const event = new Event('input', { bubbles: true });
      inputElement.dispatchEvent(event);
    }
  };

  return (
    <>
      {/* Hidden input field for accessibility and form functionality */}
      <Box
        ref={inputWrapperRef}
        className={styles.visuallyHiddenInput}
        aria-hidden="true"
      >
        <TextFieldWithBehaviors
          {...(attributes.class ? { className: clsx(attributes.class) } : {})}
          {...{
            attributes: {
              ...a2p(attributes, {}, { skipAttributes: ['class'] }),
              tabIndex: -1,
            },
          }}
        />
      </Box>

      <Popover.Root open={popoverOpen} onOpenChange={handlePopoverOpenChange}>
        <Flex
          ref={triggerRowRef}
          align="center"
          gap="2"
          className={styles.itemRow}
        >
          {/* List Item View - Trigger */}
          <Popover.Trigger>
            <button
              ref={triggerRef}
              className={styles.listItem}
              type="button"
              aria-label={`Edit ${fieldLabel}: ${displayValue || 'Empty'}`}
            >
              <Text size="2" className={styles.itemText}>
                {displayValue || 'Empty'}
              </Text>
              <ArrowRightIcon className={styles.arrowIcon} />
            </button>
          </Popover.Trigger>
        </Flex>

        {/* Edit Popover */}
        <Popover.Content
          side="left"
          align="start"
          sideOffset={6}
          className={styles.popoverContent}
          style={{ maxWidth: '235px' }}
        >
          {/* Popover Header */}
          <Flex
            justify="between"
            align="center"
            className={styles.popoverHeader}
          >
            <Text size="1" weight="medium" className={styles.popoverLabel}>
              {fieldLabel}
            </Text>
            <Popover.Close aria-label="Close">
              <Cross2Icon />
            </Popover.Close>
          </Flex>

          {/* Input Field - Visual duplicate for popover editing */}
          <Box ref={popoverTextFieldWrapperRef}>
            <TextField
              {...(attributes.class
                ? { className: clsx(attributes.class) }
                : {})}
              {...{
                attributes: {
                  value: tempValue,
                  onChange: handleTempInputChange,
                  onKeyDown: handleKeyDown,
                  type: attributes.type || 'text',
                  placeholder: attributes.placeholder,
                  ...Object.fromEntries(
                    ['min', 'max', 'step']
                      .filter(
                        (key) =>
                          attributes[key as keyof typeof attributes] !==
                          undefined,
                      )
                      .map((key) => [
                        key,
                        attributes[key as keyof typeof attributes],
                      ]),
                  ),
                },
              }}
            />
          </Box>

          {/* Remove Button */}
          <Flex justify="center">
            <Button
              variant="ghost"
              color="red"
              size="1"
              onClick={handleRemove}
              disabled={!isRemoveButtonEnabled()}
            >
              <TrashIcon />
              Remove
            </Button>
          </Flex>
        </Popover.Content>
      </Popover.Root>
    </>
  );
};

export default DrupalInputMultivalueForm;
