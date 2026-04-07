import { useLayoutEffect, useRef, useState } from 'react';
import { ArrowRightIcon, Cross2Icon, TrashIcon } from '@radix-ui/react-icons';
import { Box, Button, Flex, Popover, Text } from '@radix-ui/themes';

import TextField from '@/components/form/components/TextField';

import {
  copyInputAttributes,
  isRemoveButtonEnabled,
  triggerDrupalRemoveButton,
  updateInputValue,
} from './multivalueFormUtils';

import styles from './DrupalInputMultivalueForm.module.css';

/**
 * Format a date string for display using Intl.DateTimeFormat.
 * This matches the format shown in native date inputs.
 */
const formatDateForDisplay = (value: string): string => {
  if (!value) return value;

  try {
    // Parse ISO date string (YYYY-MM-DD) and format using browser's locale.
    const date = new Date(value + 'T00:00:00');
    // Use Intl.DateTimeFormat to match native input formatting.
    return new Intl.DateTimeFormat().format(date);
  } catch {
    return value;
  }
};

/**
 * Format time for display using Intl.DateTimeFormat.
 * This matches the format shown in native time inputs.
 */
const formatTimeForDisplay = (value: string): string => {
  if (!value) return value;

  try {
    // Create a date with the time value to format it.
    const date = new Date(`2000-01-01T${value}`);
    // Only show seconds if the value explicitly includes non-zero seconds.
    // Browsers normalize time input values to include ':00' seconds even when
    // only HH:MM was typed, so we check for non-zero seconds explicitly.
    const parts = value.split(':');
    const hasNonZeroSeconds =
      parts.length === 3 && parts[2] !== '00' && parts[2] !== '00.000';
    // Use Intl.DateTimeFormat to match native input formatting.
    return new Intl.DateTimeFormat(undefined, {
      hour: 'numeric',
      minute: 'numeric',
      second: hasNonZeroSeconds ? 'numeric' : undefined,
      hour12: true,
    }).format(date);
  } catch {
    return value;
  }
};

/**
 * DrupalDatetimeMultivalueForm component for datetime widgets within multivalue fields.
 *
 * This component wraps the entire datetime widget (date + time inputs) and displays them
 * as a single combined value in the list view, with separate date and time inputs in the popover.
 */
const DrupalDatetimeMultivalueForm = ({
  children,
  fieldLabel = '',
}: {
  children?: React.ReactNode;
  fieldLabel?: string;
}) => {
  const contentWrapperRef = useRef<HTMLDivElement | null>(null);
  const triggerRowRef = useRef<HTMLDivElement | null>(null);
  const [popoverOpen, setPopoverOpen] = useState(false);
  const [values, setValues] = useState({
    date: '',
    time: '',
    tempDate: '',
    tempTime: '',
  });
  const popoverDateFieldWrapperRef = useRef<HTMLDivElement | null>(null);
  const popoverTimeFieldWrapperRef = useRef<HTMLDivElement | null>(null);
  // Store refs to the hidden date and time inputs.
  const hiddenDateInputRef = useRef<HTMLInputElement | null>(null);
  const hiddenTimeInputRef = useRef<HTMLInputElement | null>(null);

  // Read initial values from the rendered inputs.
  useLayoutEffect(() => {
    if (!contentWrapperRef.current) return;

    const dateInput = contentWrapperRef.current.querySelector(
      'input[type="date"]',
    ) as HTMLInputElement;
    const timeInput = contentWrapperRef.current.querySelector(
      'input[type="time"]',
    ) as HTMLInputElement;

    // Store refs to hidden inputs so we can access their attributes.
    hiddenDateInputRef.current = dateInput;
    hiddenTimeInputRef.current = timeInput;

    const dateVal = dateInput?.value || dateInput?.defaultValue || '';
    const timeVal = timeInput?.value || timeInput?.defaultValue || '';

    setValues({
      date: dateVal,
      time: timeVal,
      tempDate: dateVal,
      tempTime: timeVal,
    });
  }, [children]);

  /**
   * Helper function to validate an input ref.
   * Returns true if valid, false if invalid (and shows validation message).
   */
  const checkValid = (ref: React.RefObject<HTMLDivElement>): boolean => {
    const input = ref.current?.querySelector('input');
    if (input && !input.checkValidity()) {
      input.reportValidity();
      return false;
    }
    return true;
  };

  /**
   * Helper function to update the hidden date and time inputs.
   */
  const updateHiddenInputs = (date: string, time: string) => {
    updateInputValue(hiddenDateInputRef.current, date);
    if (hiddenTimeInputRef.current) {
      updateInputValue(hiddenTimeInputRef.current, time);
    }
  };

  // Commit the temporary values to the actual inputs and display.
  const handleCommitValues = () => {
    // Validate the date input.
    if (!checkValid(popoverDateFieldWrapperRef)) return;

    // Validate the time input (only if not date-only).
    if (hiddenTimeInputRef.current && !checkValid(popoverTimeFieldWrapperRef))
      return;

    setValues((prev) => {
      // Update the hidden inputs with the temp values before updating state.
      updateHiddenInputs(prev.tempDate, prev.tempTime);

      return {
        ...prev,
        date: prev.tempDate,
        time: prev.tempTime,
      };
    });
  };

  // Handle Enter key press in popover inputs.
  const handleKeyDown = (e: React.KeyboardEvent<HTMLInputElement>) => {
    if (e.key === 'Enter') {
      e.preventDefault();

      // Determine which input triggered the event.
      const inputElement = e.target as HTMLInputElement;

      // Validate the input before committing.
      if (inputElement && !inputElement.checkValidity()) {
        // Show validation error and keep popover open.
        inputElement.reportValidity();
        return;
      }

      // If date input is valid, also check time input if not date-only.
      if (
        inputElement.type === 'date' &&
        hiddenTimeInputRef.current &&
        !checkValid(popoverTimeFieldWrapperRef)
      ) {
        return;
      }

      // If time input is valid, also check date input.
      if (
        inputElement.type === 'time' &&
        !checkValid(popoverDateFieldWrapperRef)
      ) {
        return;
      }

      handleCommitValues();
      setPopoverOpen(false);
    }
  };

  // Handle popover open/close
  const handlePopoverOpenChange = (open: boolean) => {
    // Reset temp values to current values.
    setValues((prev) => ({
      ...prev,
      tempDate: prev.date,
      tempTime: prev.time,
    }));
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

    setValues({
      date: '',
      time: '',
      tempDate: '',
      tempTime: '',
    });

    // Clear both hidden inputs.
    updateHiddenInputs('', '');
  };

  // Create the combined display value.
  const combinedDisplayValue = (() => {
    if (!hiddenTimeInputRef.current) {
      // Date-only field: show just the formatted date.
      return formatDateForDisplay(values.date) || 'Empty';
    } else {
      // DateTime field: show date and time combined.
      if (values.date || values.time) {
        return `${formatDateForDisplay(values.date)}${values.date && values.time ? ', ' : ''}${formatTimeForDisplay(values.time)}`;
      }
      return 'Empty';
    }
  })();

  return (
    <>
      {/* Hidden datetime inputs - render the actual content off-screen */}
      <Box
        ref={contentWrapperRef}
        className={styles.visuallyHiddenInput}
        aria-hidden="true"
      >
        {children}
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
              className={styles.listItem}
              type="button"
              aria-label={`Edit ${fieldLabel}: ${combinedDisplayValue}`}
            >
              <Text size="2" className={styles.itemText}>
                {combinedDisplayValue}
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

          {/* Date Input Field */}
          <Box ref={popoverDateFieldWrapperRef}>
            <TextField
              {...{
                attributes: {
                  value: values.tempDate,
                  onChange: (e: React.ChangeEvent<HTMLInputElement>) => {
                    setValues((prev) => ({
                      ...prev,
                      tempDate: e.target.value,
                    }));
                    // Focus the input after value selection.
                    setTimeout(() => {
                      e.target.focus();
                    }, 0);
                  },
                  onKeyDown: handleKeyDown,
                  type: 'date',
                  ...copyInputAttributes(hiddenDateInputRef.current),
                },
              }}
            />
          </Box>

          {/* Time Input Field - only show for datetime fields, not date-only */}
          {hiddenTimeInputRef.current && (
            <Box
              className={styles.timeInputWrapper}
              ref={popoverTimeFieldWrapperRef}
            >
              <TextField
                {...{
                  attributes: {
                    value: values.tempTime,
                    onChange: (e: React.ChangeEvent<HTMLInputElement>) => {
                      setValues((prev) => ({
                        ...prev,
                        tempTime: e.target.value,
                      }));
                      // Focus the input after value selection
                      setTimeout(() => {
                        e.target.focus();
                      }, 0);
                    },
                    onKeyDown: handleKeyDown,
                    type: 'time',
                    ...copyInputAttributes(hiddenTimeInputRef.current),
                  },
                }}
              />
            </Box>
          )}

          {/* Remove Button - disabled when removing is not allowed */}
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

export default DrupalDatetimeMultivalueForm;
