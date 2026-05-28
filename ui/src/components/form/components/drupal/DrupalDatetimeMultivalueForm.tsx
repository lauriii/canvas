import { useEffect, useRef, useState } from 'react';
import clsx from 'clsx';
import { ArrowRightIcon, Cross2Icon, TrashIcon } from '@radix-ui/react-icons';
import * as Popover from '@radix-ui/react-popover';
import { Button, Flex, Text } from '@radix-ui/themes';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import { toPropName } from '@/components/form/react-hook-form/fields/componentFormData';
import { removeFieldValue } from '@/features/form/formStateSlice';
import { isEvaluatedComponentModel } from '@/features/layout/layoutModelSlice';
import { selectEditorFrameContext } from '@/features/ui/uiSlice';
import useInputUIData from '@/hooks/useInputUIData';
import { useUpdateComponentMutation } from '@/services/preview';

import {
  buildModelWithItemRemoved,
  extractPositionFromFieldName,
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
  const inputUIData = useInputUIData();
  const [patchComponent] = useUpdateComponentMutation({
    fixedCacheKey: inputUIData?.selectedComponent || '-',
  });
  const editorFrameContext = useAppSelector(selectEditorFrameContext);

  const triggerRowRef = useRef<HTMLDivElement | null>(null);
  const triggerButtonRef = useRef<HTMLButtonElement | null>(null);
  const [popoverOpen, setPopoverOpen] = useState(false);
  const [displayDate, setDisplayDate] = useState('');
  const [displayTime, setDisplayTime] = useState('');

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

  const hasError = () =>
    popoverContainer &&
    !!popoverContainer.querySelector('[data-invalid-prop-value="true"]');

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
    // Since datetime is two inputs but one field, we can't listen for changes
    // as elegantly as we do in <DrupalInputMultivalueForm>, where we can just
    // add an `onInput` prop. Instead, we need to add dedicated input listeners
    // on both inputs.
    [dateInput, timeInput].forEach((input) => {
      // Using the __listenersAdded approach to preventing duplicate listeners,
      // instead of the more common useEffect cleanup, as popoverContainer is
      // the most reliable useEffect dependency. However, it can retrigger more
      // often than the need addEventListener, and can lead to gaps in
      // callback availability.
      if (input && !(input as any).__listenersAdded) {
        (input as any).__listenersAdded = true;
        input.addEventListener('input', (e) => {
          const target = e.target as HTMLInputElement;
          const { value, type } = target;
          if (type === 'date') {
            // A date input value is either '' or a valid ISO date (YYYY-MM-DD).
            // An empty string means the field was cleared; a non-empty value
            // from a date input is always structurally valid.
            setDisplayDate(value);
          } else if (type === 'time') {
            // A time input value is either '' or a valid time string (HH:MM or
            // HH:MM:SS). Validate by parsing; an invalid time produces NaN.
            if (!value || !isNaN(new Date(`2000-01-01T${value}`).getTime())) {
              setDisplayTime(value);
            }
          }
        });
      }
    });
  }, [popoverContainer]);

  const handleKeyDown = (e: React.KeyboardEvent<HTMLInputElement>) => {
    if (e.key === 'Escape' || e.key === 'Enter') {
      handlePopoverOpenChange(false);
      e.preventDefault();
      return;
    }
  };

  const handlePopoverOpenChange = (open: boolean) => {
    if (open) {
      const dateInput = getDateInput();
      const timeInput = getTimeInput();
      setTimeout(() => {
        const firstInput = dateInput || timeInput;
        firstInput?.focus();
      }, 0);
    }
    if (!hasError()) {
      setPopoverOpenAndRefocus(open);
    }
  };

  const handleRemove = (e: React.MouseEvent) => {
    e.preventDefault();
    const triggerElement = triggerRowRef.current;
    if (!triggerElement) return;
    setPopoverOpenAndRefocus(false);
    setTimeout(() => {
      const dateInput = getDateInput();
      const { name } = dateInput || {};
      const formId = dateInput?.getAttribute('data-form-id');

      const removeButtonName: string | null = triggerDrupalRemoveButton(
        triggerRowRef.current,
        formId as string,
      );
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
        // If this is a component, update the model to reflect the removal
        // immediately, then fire the actual Drupal AJAX click.
        if (formId === 'component_instance_form') {
          const propName = toPropName(name, inputUIData.selectedComponent);
          const position = extractPositionFromFieldName(name, propName);
          const model = inputUIData.model?.[inputUIData.selectedComponent];
          if (!model || !isEvaluatedComponentModel(model)) {
            return;
          }
          patchComponent({
            type: editorFrameContext,
            componentInstanceUuid: inputUIData.selectedComponent,
            componentType: `${inputUIData.selectedComponentType}@${inputUIData.version}`,
            model: buildModelWithItemRemoved(model, propName, position ?? 0),
          });
          // Second call: now that the model is patched, invoke the real
          // Drupal AJAX click (no formId passed so the click is not suppressed).
          setTimeout(() => triggerDrupalRemoveButton(triggerRowRef.current));
        }

        dispatch(
          removeFieldValue({
            formId: formId as any,
            fieldName: fieldNames,
          }),
        );
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
              data-multivalue-popover-trigger
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
              data-multivalue-remove-item="true"
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
