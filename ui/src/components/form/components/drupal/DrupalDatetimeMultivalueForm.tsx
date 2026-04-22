import { useEffect, useRef, useState } from 'react';
import clsx from 'clsx';
import { ArrowRightIcon, Cross2Icon, TrashIcon } from '@radix-ui/react-icons';
import * as Popover from '@radix-ui/react-popover';
import { Button, Flex, Text } from '@radix-ui/themes';

import { useAppDispatch } from '@/app/hooks';
import { removeFieldValue } from '@/features/form/formStateSlice';

import {
  dispatchSyntheticChange,
  isRemoveButtonEnabled,
  triggerDrupalRemoveButton,
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
  const dispatch = useAppDispatch();
  const triggerRowRef = useRef<HTMLDivElement | null>(null);
  const triggerButtonRef = useRef<HTMLButtonElement | null>(null);
  const [popoverOpen, setPopoverOpen] = useState(false);
  const [displayDate, setDisplayDate] = useState('');
  const [displayTime, setDisplayTime] = useState('');
  const valueAtOpenRef = useRef<{ date: string; time: string }>({
    date: '',
    time: '',
  });
  const shouldRevertOnCloseRef = useRef(true);
  // Tracks whether a time input exists — derived from the real DOM via
  // useEffect  rather than queried at render time, so it is stable across
  // renders and correct before the popover container is first populated.
  const [hasTime, setHasTime] = useState(false);

  // This state is set when Radix mounts the popover content, which
  // may be deferred past the first render commit. Using state (rather than a
  // ref) means the useEffect below re-runs reliably as soon as the node is
  // available.
  const [popoverContainer, setPopoverContainer] =
    useState<HTMLDivElement | null>(null);

  // Returns the date input from the popover container, or null.
  const getDateInput = (): HTMLInputElement | null =>
    popoverContainer?.querySelector('input[type="date"]') ?? null;

  // Returns the time input from the popover container, or null.
  const getTimeInput = (): HTMLInputElement | null =>
    popoverContainer?.querySelector('input[type="time"]') ?? null;

  const setPopoverOpenAndRefocus = (open: boolean) => {
    setPopoverOpen(open);
    if (!open) {
      setTimeout(() => triggerButtonRef.current?.focus(), 30);
    }
  };

  const pausePreviewUpdates = () => {
    getDateInput()?.setAttribute('data-canvas-stage-changes', 'true');
    getTimeInput()?.setAttribute('data-canvas-stage-changes', 'true');
  };

  const resumePreviewUpdates = () => {
    getDateInput()?.removeAttribute('data-canvas-stage-changes');
    getTimeInput()?.removeAttribute('data-canvas-stage-changes');
  };

  // Read initial values from the inputs once the popover container is in the DOM.
  useEffect(() => {
    if (!popoverContainer) return;

    const dateInput = popoverContainer.querySelector(
      'input[type="date"]',
    ) as HTMLInputElement | null;
    const timeInput = popoverContainer.querySelector(
      'input[type="time"]',
    ) as HTMLInputElement | null;

    setDisplayDate(dateInput?.value || dateInput?.defaultValue || '');
    setDisplayTime(timeInput?.value || timeInput?.defaultValue || '');
    setHasTime(!!timeInput);
  }, [popoverContainer]);
  // Handle Enter and Escape key presses in popover inputs.
  const handleKeyDown = (e: React.KeyboardEvent<HTMLElement>) => {
    // Handle Escape key to close popover without committing changes
    if (e.key === 'Escape') {
      shouldRevertOnCloseRef.current = true;
      handlePopoverOpenChange(false);
      return;
    }

    if (e.key !== 'Enter') return;

    e.preventDefault();

    if (popoverContainer?.querySelector('[data-has-field-error="true"]')) {
      return;
    }

    const dateInput = getDateInput();
    const timeInput = getTimeInput();
    setDisplayDate(dateInput?.value ?? '');
    setDisplayTime(timeInput?.value ?? '');
    shouldRevertOnCloseRef.current = false;

    resumePreviewUpdates();

    // Dispatch synthetic change events so the preview handler sees the new values.
    setTimeout(() => {
      if (dateInput) dispatchSyntheticChange(dateInput, dateInput.value);
      if (timeInput) dispatchSyntheticChange(timeInput, timeInput.value);
      handlePopoverOpenChange(false);
    });
  };

  const handlePopoverOpenChange = (open: boolean) => {
    if (open) {
      pausePreviewUpdates();
      shouldRevertOnCloseRef.current = true;
      const dateInput = getDateInput();
      const timeInput = getTimeInput();
      valueAtOpenRef.current = {
        date: dateInput?.value ?? displayDate,
        time: timeInput?.value ?? displayTime,
      };
      setTimeout(() => {
        const firstInput = dateInput || timeInput;
        firstInput?.focus();
      }, 0);
    } else if (shouldRevertOnCloseRef.current) {
      // Cancel any in-progress edit: reset inputs back to the values at open.
      const dateInput = getDateInput();
      const timeInput = getTimeInput();
      if (dateInput)
        dispatchSyntheticChange(dateInput, valueAtOpenRef.current.date);
      if (timeInput)
        dispatchSyntheticChange(timeInput, valueAtOpenRef.current.time);
    }
    setPopoverOpenAndRefocus(open);
    if (!open) {
      resumePreviewUpdates();
      shouldRevertOnCloseRef.current = true;
    }
  };

  const handleRemove = (e: React.MouseEvent) => {
    e.preventDefault();
    const triggerElement = triggerRowRef.current;
    if (!triggerElement) return;
    setPopoverOpenAndRefocus(false);
    resumePreviewUpdates();
    setTimeout(() => {
      const dateInput = getDateInput();
      const { name } = dateInput || {};

      const removeButtonName: string | null = triggerDrupalRemoveButton(
        triggerRowRef.current,
      );
      const formId = dateInput?.getAttribute('data-form-id');
      console.log('form id', formId);
      if (removeButtonName && name && formId) {
        const fieldNames: string[] = [name];

        // Add the time field name if it exists (replace [date] with [time])
        if (name.includes('[date]')) {
          const timeName = name.replace('[date]', '[time]');
          fieldNames.push(timeName);
        }

        // Add the weight field (replace [value][date] with [_weight])
        if (name.includes('[value][date]')) {
          const weightName = name.replace('[value][date]', '[_weight]');
          fieldNames.push(weightName);
        }

        // Add the remove button name
        fieldNames.push(removeButtonName);

        dispatch(
          removeFieldValue({
            formId: formId as any,
            fieldName: fieldNames,
          }),
        );
        return;
      }
    });
  };

  // Build the combined display value.
  const combinedDisplayValue = (() => {
    if (!hasTime) {
      return formatDateForDisplay(displayDate) || 'Empty';
    }
    if (displayDate || displayTime) {
      return `${formatDateForDisplay(displayDate)}${displayDate && displayTime ? ', ' : ''}${formatTimeForDisplay(displayTime)}`;
    }
    return 'Empty';
  })();

  return (
    <div style={{ display: 'contents' }}>
      <Popover.Root open={popoverOpen} onOpenChange={handlePopoverOpenChange}>
        <Flex
          ref={triggerRowRef}
          align="center"
          gap="2"
          className={styles.itemRow}
        >
          {/* List Item View - Trigger */}
          <Popover.Trigger asChild>
            <button
              ref={triggerButtonRef}
              className={styles.listItem}
              type="button"
              aria-label={`Edit ${fieldLabel}: ${combinedDisplayValue}`}
            >
              <Text
                size="2"
                className={styles.itemText}
                data-canvas-multivalue-label
              >
                {combinedDisplayValue}
              </Text>
              <ArrowRightIcon className={styles.arrowIcon} />
            </button>
          </Popover.Trigger>
        </Flex>

        {/* Edit Popover */}
        <Popover.Content
          forceMount={true}
          ref={setPopoverContainer}
          side="left"
          align="start"
          sideOffset={6}
          className={clsx(styles.popoverContent, [
            !popoverOpen && styles.visuallyHiddenInput,
          ])}
          onKeyDown={handleKeyDown}
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

          {/* Date and time inputs rendered by Drupal */}
          {children}

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
    </div>
  );
};

export default DrupalDatetimeMultivalueForm;
