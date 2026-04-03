import { useCallback, useEffect, useRef, useState } from 'react';
import clsx from 'clsx';
import { ArrowRightIcon, Cross2Icon, TrashIcon } from '@radix-ui/react-icons';
import { Box, Button, Flex, Popover, Text } from '@radix-ui/themes';

import DrupalInput from '@/components/form/components/drupal/DrupalInput';
import TextField from '@/components/form/components/TextField';
import InputBehaviors from '@/components/form/inputBehaviors';
import { a2p } from '@/local_packages/utils';

import {
  copyInputAttributes,
  isRemoveButtonEnabled,
  triggerDrupalRemoveButton,
  updateInputValue,
} from './multivalueFormUtils';

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
  const popoverContainerRef = useRef<HTMLDivElement | null>(null);

  // Sync displayValue when a genuine external value arrives (e.g. AJAX
  // re-hyperscriptification after "Add another item" or "Remove").
  useEffect(() => {
    const liveInput = inputWrapperRef.current?.querySelector(
      'input',
    ) as HTMLInputElement | null;
    const liveValue = liveInput?.value ?? '';
    const attrValue = (attributes.value ??
      attributes.defaultValue ??
      '') as string;

    // Use the live input value when it is non-empty; fall back to the
    // attribute value on the very first mount (before InputBehaviors has had
    // a chance to populate the input).
    const newValue = liveValue !== '' ? liveValue : attrValue;

    setDisplayValue(newValue);
    setTempValue(newValue);
  }, [attributes.value, attributes.defaultValue]);

  // Commit a value directly — used by the autocomplete selection handler so
  // the table row updates immediately.
  const commitValue = useCallback((newValue: string) => {
    setDisplayValue(newValue);
    setTempValue(newValue);

    if (inputWrapperRef.current) {
      const realInput = inputWrapperRef.current.querySelector(
        'input',
      ) as HTMLInputElement | null;
      updateInputValue(realInput, newValue);
    }
  }, []);

  // Listen for the custom autocomplete-selected event dispatched by
  // autocomplete.extend.js when the user picks a suggestion.
  const autocompleteCleanupRef = useRef<(() => void) | null>(null);
  // Flag to indicate an autocomplete suggestion was just selected. Set by the
  // data-canvas-autocomplete-selected handler so that handleKeyDown (which may
  // fire after jQuery UI has already closed the dropdown) does not overwrite
  // the committed value with the stale tempValue.
  const autocompleteJustSelectedRef = useRef(false);

  // Clean up the autocomplete listener whenever the popover closes.
  useEffect(() => {
    if (!popoverOpen) {
      autocompleteCleanupRef.current?.();
      autocompleteCleanupRef.current = null;
    }
  }, [popoverOpen]);
  // Handle temporary input changes in popover (not committed until Enter).
  const handleTempInputChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    setTempValue(e.target.value);
  };

  // Commit the temporary value to the actual input and display.
  const handleCommitValue = () => {
    // Validate the input using the popover input element's HTML5 validation.
    if (popoverInputRef.current) {
      // Check if the input is valid according to its constraints
      // (min, max, step, etc.).
      if (!popoverInputRef.current.checkValidity()) {
        // Show the browser's native validation message.
        popoverInputRef.current.reportValidity();
        return;
      }
    }

    commitValue(tempValue);
  };

  // Handle Enter key press in popover input.
  const handleKeyDown = (e: React.KeyboardEvent<HTMLInputElement>) => {
    if (e.key === 'Enter') {
      // If a jQuery UI autocomplete suggestion was just selected synchronously
      // (before this React event handler ran via event delegation), do NOT
      // commit the stale tempValue. The data-canvas-autocomplete-selected
      // handler already committed the correct suggestion value.
      if (autocompleteJustSelectedRef.current) {
        autocompleteJustSelectedRef.current = false;
        return;
      }

      // If a jQuery UI autocomplete dropdown is currently open, do NOT
      // intercept Enter — let jQuery UI process the selection so that
      // the auto complete select event fires. The data-canvas-autocomplete-selected
      // listener will then commit the correct suggestion value and close the popover.
      const inputElement =
        popoverInputRef.current || (e.target as HTMLInputElement);
      const $jq =
        typeof window !== 'undefined' && (window as any).jQuery
          ? (window as any).jQuery
          : null;
      const isAutocompleteOpen =
        $jq &&
        $jq(inputElement)
          .data('ui-autocomplete')
          ?.menu?.element?.is?.(':visible');

      if (isAutocompleteOpen) {
        return;
      }

      e.preventDefault();

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
      // Focus the input field in the popover. Find it inside the container
      // since TextFieldAutocomplete does not forward the ref from attributes.
      // Also re-attach Drupal behaviors so that entity_autocomplete (jQuery UI
      // autocomplete) is initialized on the input that just appeared inside
      // the Radix UI portal — it was never seen by Drupal.behaviors.
      // autocomplete during the initial page load.
      setTimeout(() => {
        if (popoverContainerRef.current) {
          // @todo Refactor this as a part of https://www.drupal.org/i/3581159.
          window.Drupal?.attachBehaviors(popoverContainerRef.current);

          const handleSelected = (e: Event) => {
            if (!popoverContainerRef.current?.contains(e.target as Node))
              return;
            autocompleteJustSelectedRef.current = true;
            commitValue((e as CustomEvent<{ value: string }>).detail.value);
            setPopoverOpen(false);
          };
          document.addEventListener(
            'data-canvas-autocomplete-selected',
            handleSelected,
          );
          autocompleteCleanupRef.current = () =>
            document.removeEventListener(
              'data-canvas-autocomplete-selected',
              handleSelected,
            );
        }
        const input = popoverContainerRef.current?.querySelector(
          'input',
        ) as HTMLInputElement | null;
        if (input) {
          popoverInputRef.current = input;
          input.select();
        }
      }, 0);
    } else {
      // Restore focus to the trigger button after closing.
      setTimeout(() => {
        if (triggerRef.current) {
          triggerRef.current.focus();
        }
      }, 0);
    }
    setPopoverOpen(open);
  };

  const handleRemove = () => {
    // Close the popover first so the portal is cleaned up before AJAX replaces the DOM.
    setPopoverOpen(false);

    if (!triggerRowRef.current) return;

    // Try to trigger the Drupal remove button.
    if (triggerDrupalRemoveButton(triggerRowRef.current)) {
      return;
    }

    setDisplayValue('');
    if (!inputWrapperRef.current) return;
    const inputElement = inputWrapperRef.current.querySelector(
      'input',
    ) as HTMLInputElement | null;
    updateInputValue(inputElement, '');
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
          ref={popoverContainerRef}
          side="left"
          align="start"
          sideOffset={6}
          className={styles.popoverContent}
          style={{ maxWidth: '235px' }}
          onInteractOutside={(e) => {
            // Prevent the popover from closing when the user clicks on a
            // jQuery UI autocomplete suggestion. The dropdown is rendered in
            // a portal outside the popover, so Radix treats it as an outside
            // click and would close the popover before the selection is
            // committed via the MutationObserver.
            const target = e.target as Element | null;
            if (target?.closest('.ui-autocomplete, .ui-menu')) {
              e.preventDefault();
            }
          }}
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
            {attributes?.class instanceof Array &&
            attributes?.class?.includes('form-autocomplete') ? (
              <DrupalInput
                attributes={{
                  ...attributes,
                  value: tempValue,
                  onChange: handleTempInputChange,
                  onKeyDown: handleKeyDown,
                }}
              />
            ) : (
              <TextField
                className={clsx(attributes.class)}
                attributes={{
                  ...attributes,
                  value: tempValue,
                  onChange: handleTempInputChange,
                  onKeyDown: handleKeyDown,
                  type: attributes.type || 'text',
                  placeholder: attributes.placeholder,
                  ...copyInputAttributes(attributes),
                }}
              />
            )}
          </Box>

          {/* Remove Button */}
          <Flex justify="center" className={styles.removeButtonContainer}>
            <Button
              variant="ghost"
              color="red"
              size="1"
              onClick={handleRemove}
              disabled={!isRemoveButtonEnabled(triggerRowRef.current)}
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
