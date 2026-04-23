import { useEffect, useRef, useState } from 'react';
import clsx from 'clsx';
import { ArrowRightIcon, Cross2Icon, TrashIcon } from '@radix-ui/react-icons';
import * as Popover from '@radix-ui/react-popover';
import { Box, Button, Flex, Text } from '@radix-ui/themes';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import DrupalInput from '@/components/form/components/drupal/DrupalInput';
import { toPropName } from '@/components/form/formUtil';
import { removeFieldValue } from '@/features/form/formStateSlice';
import { isEvaluatedComponentModel } from '@/features/layout/layoutModelSlice';
import { selectEditorFrameContext } from '@/features/ui/uiSlice';
import useInputUIData from '@/hooks/useInputUIData';
import { useUpdateComponentMutation } from '@/services/preview';
import { resolveEntityUri } from '@/utils/transforms';

import {
  buildModelWithItemRemoved,
  dispatchSyntheticChange,
  extractPositionFromFieldName,
  isAutocompleteMenuOpen,
  isRemoveButtonEnabled,
  triggerDrupalRemoveButton,
} from './multivalueFormUtils';

import type { NumericInputAttributes } from '@/types/DrupalAttribute';

import styles from './DrupalInputMultivalueForm.module.css';

type MultivalueRefs = {
  popoverInput: HTMLInputElement | null;
  popoverContainer: HTMLDivElement | null;
  triggerRow: HTMLDivElement | null;
  triggerButton: HTMLButtonElement | null;
  valueAtOpen: string;
  shouldRevertOnClose: boolean;
};
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
  const dispatch = useAppDispatch();
  const inputUIData = useInputUIData();
  const [patchComponent] = useUpdateComponentMutation({
    fixedCacheKey: inputUIData?.selectedComponent || '-',
  });
  const editorFrameContext = useAppSelector(selectEditorFrameContext);
  const isAutocomplete =
    attributes?.class instanceof Array &&
    attributes?.class?.includes('form-autocomplete');
  const initialValue = attributes.value || attributes.defaultValue || '';
  const refs = useRef<MultivalueRefs>({
    triggerRow: null,
    triggerButton: null,
    popoverInput: null,
    popoverContainer: null,
    valueAtOpen: initialValue as string,
    shouldRevertOnClose: true,
  });

  const [displayValue, setDisplayValue] = useState<string>(
    initialValue as string,
  );
  // Controlled popover state so we can close it programmatically on remove.
  const [popoverOpen, setPopoverOpen] = useState(false);
  const fieldLabel = attributes['data-field-label'] || '';

  const setPopoverOpenAndRefocus = (open: boolean) => {
    setPopoverOpen(open);
    if (!open) {
      setTimeout(() => refs.current.triggerButton?.focus(), 30);
    }
  };

  const pausePreviewUpdates = () => {
    refs.current?.popoverInput?.setAttribute(
      'data-canvas-stage-changes',
      'true',
    );
  };

  const resumePreviewUpdates = () => {
    refs.current?.popoverInput?.removeAttribute('data-canvas-stage-changes');
  };

  useEffect(() => {
    setTimeout(() => {
      if (
        isAutocomplete &&
        refs.current.popoverInput &&
        popoverOpen &&
        !refs.current.popoverInput.hasAttribute(
          'data-canvas-popover-autocomplete',
        )
      ) {
        refs.current.popoverInput.setAttribute(
          'data-canvas-popover-autocomplete',
          'true',
        );
        refs.current.popoverInput.addEventListener(
          'data-canvas-autocomplete-selected',
          (e: Event) => {
            const customEvent = e as CustomEvent<{ value: string }>;
            refs.current.popoverInput?.removeAttribute(
              'data-canvas-stage-changes',
            );
            setTimeout(() => {
              const forDisplay = customEvent.detail.value;
              setDisplayValue(forDisplay);
              setTimeout(() => {
                if (refs.current.popoverInput) {
                  dispatchSyntheticChange(
                    refs.current.popoverInput,
                    resolveEntityUri(forDisplay),
                    true,
                  );
                }
                setPopoverOpen(false);
              });
            });
          },
        );
      }
    });
  }, [isAutocomplete, refs.current.popoverInput, popoverOpen]);
  // Listen for the custom autocomplete-selected event dispatched by
  // autocomplete.extend.js when the user picks a suggestion.

  // Handle Enter and Escape key presses in popover input.
  const handleKeyDown = (e: React.KeyboardEvent<HTMLInputElement>) => {
    // Handle Escape key to close popover without committing changes
    if (e.key === 'Escape') {
      handlePopoverOpenChange(false);
      return;
    }

    if (e.key === 'Enter') {
      // If a jQuery UI autocomplete dropdown is currently open, do NOT
      // intercept Enter — let jQuery UI process the selection so that
      // the autocomplete select event fires. The data-canvas-autocomplete-selected
      // listener will then commit the correct suggestion value and close the popover.
      const inputElement =
        refs.current.popoverInput || (e.target as HTMLInputElement);

      if (isAutocompleteMenuOpen(inputElement)) {
        return;
      }

      e.preventDefault();

      setTimeout(() => {
        if (refs.current.popoverInput) {
          const hasError = !!refs.current.popoverInput.closest(
            '[data-has-field-error="true"]',
          );
          if (!hasError) {
            resumePreviewUpdates();
            const committedValue = refs.current.popoverInput.value;
            setPopoverOpen(false);
            dispatchSyntheticChange(
              refs.current.popoverInput,
              committedValue,
              isAutocomplete,
            );
            setDisplayValue(committedValue);
            refs.current.shouldRevertOnClose = false;
          }
        }
      });
    }
  };

  const handlePopoverOpenChange = (open: boolean) => {
    if (open) {
      pausePreviewUpdates();
      refs.current.shouldRevertOnClose = true;
      setTimeout(() => {
        const input = refs.current.popoverContainer?.querySelector(
          'input',
        ) as HTMLInputElement | null;
        if (input) {
          refs.current.popoverInput = input;
          refs.current.popoverInput.setAttribute(
            'data-canvas-stage-changes',
            'true',
          );
          refs.current.valueAtOpen = input.value;
          input.select();
        }
      }, 0);
    } else {
      // If we explicitly close the popover, the changes made thus far should
      // be undone, and the field reverted to its value prior to opening.
      if (refs.current.shouldRevertOnClose && refs.current.popoverInput) {
        dispatchSyntheticChange(
          refs.current.popoverInput,
          refs.current.valueAtOpen,
          isAutocomplete,
        );
      }

      setPopoverOpenAndRefocus(false);
      resumePreviewUpdates();
      refs.current.shouldRevertOnClose = true;
      return;
    }

    setPopoverOpenAndRefocus(true);
  };

  const handleRemove = (e: React.MouseEvent) => {
    e.preventDefault();
    const triggerElement = refs.current.triggerRow;
    if (!triggerElement) return;
    setPopoverOpenAndRefocus(false);
    resumePreviewUpdates();
    setTimeout(() => {
      const formId = attributes['data-form-id'] as string | undefined;
      const { name } = attributes;

      // First call: pass formId so the click is suppressed for
      // component_instance_form, but the button name is still returned.
      const removeButtonName: string | null = triggerDrupalRemoveButton(
        triggerElement,
        formId,
      );

      // If we successfully triggered the remove button and have the necessary
      // data, dispatch removeFieldValue.
      if (removeButtonName && formId && name) {
        const fieldNames: string[] = [name];

        // Add the _weight field (replace [value] with [_weight])
        if (name.includes('[value]')) {
          fieldNames.push(name.replace('[value]', '[_weight]'));
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
          setTimeout(() => triggerDrupalRemoveButton(triggerElement));
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

  return (
    <Popover.Root open={popoverOpen} onOpenChange={handlePopoverOpenChange}>
      <Flex
        ref={(node) => {
          refs.current.triggerRow = node;
        }}
        align="center"
        gap="2"
        className={styles.itemRow}
      >
        {/* List Item View - Trigger */}
        <Popover.Trigger asChild>
          <button
            ref={(node) => {
              refs.current.triggerButton = node;
            }}
            className={styles.listItem}
            type="button"
            aria-label={`Edit ${fieldLabel}: ${displayValue || 'Empty'}`}
          >
            <Text
              size="2"
              className={styles.itemText}
              data-canvas-multivalue-label
            >
              {displayValue || 'Empty'}
            </Text>
            <ArrowRightIcon className={styles.arrowIcon} />
          </button>
        </Popover.Trigger>
      </Flex>

      <Popover.Content
        forceMount={true}
        ref={(node) => {
          refs.current.popoverContainer = node;
        }}
        side="left"
        align="start"
        sideOffset={36}
        className={clsx(styles.popoverContent, [
          !popoverOpen && styles.visuallyHiddenInput,
        ])}
        style={{ maxWidth: '235px' }}
        onInteractOutside={(e) => {
          // Prevent the popover from closing when the user clicks on a
          // jQuery UI autocomplete suggestion. The dropdown is rendered in
          // a portal outside the popover, so Radix treats it as an outside
          // click and would close the popover before the selection is
          // committed.
          const target = e.target as Element | null;
          if (target?.closest('.ui-autocomplete, .ui-menu')) {
            e.preventDefault();
            return;
          }
        }}
      >
        {/* Popover Header */}
        <Flex justify="between" align="center" className={styles.popoverHeader}>
          <Text size="1" weight="medium" className={styles.popoverLabel}>
            {fieldLabel}
          </Text>
          <Popover.Close aria-label="Close">
            <Cross2Icon />
          </Popover.Close>
        </Flex>
        <Box>
          <DrupalInput
            attributes={{ ...attributes, onKeyDown: handleKeyDown }}
          />
        </Box>
        <Flex justify="center" className={styles.removeButtonContainer}>
          <Button
            data-multivalue-remove-item="true"
            variant="ghost"
            color="red"
            size="1"
            onClick={handleRemove}
            disabled={!isRemoveButtonEnabled(refs.current.triggerRow)}
          >
            <TrashIcon />
            Remove
          </Button>
        </Flex>
      </Popover.Content>
    </Popover.Root>
  );
};

export default DrupalInputMultivalueForm;
