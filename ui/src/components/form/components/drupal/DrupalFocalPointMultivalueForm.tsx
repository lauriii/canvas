import { useRef, useState } from 'react';
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

import FocalPointPicker, {
  FocalPointThumbnail,
  formatFocalPoint,
  useFocalPointImage,
} from './FocalPointPicker';
import {
  buildModelWithItemRemoved,
  extractPositionFromFieldName,
  isRemoveButtonEnabled,
  triggerDrupalRemoveButton,
} from './multivalueFormUtils';

import type { ReactNode } from 'react';
import type { CoordinateRegistration, FocalPoint } from './FocalPointPicker';

import styles from './DrupalInputMultivalueForm.module.css';
import pickerStyles from './FocalPointPicker.module.css';

/**
 * The focal point widget for one item of a multivalue prop.
 *
 * Follows the other multivalue widgets: a list row summarizing the item opens
 * a popover with the picker and a Remove button.
 *
 * @see DrupalDatetimeMultivalueForm
 * @see DrupalFocalPoint
 */
const DrupalFocalPointMultivalueForm = ({
  children,
  fieldLabel = '',
  imageProp,
}: {
  children?: ReactNode;
  fieldLabel?: string;
  imageProp?: string;
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
  const [point, setPoint] = useState<FocalPoint | null>(null);
  const [inputs, setInputs] = useState<
    Partial<Record<'x' | 'y', CoordinateRegistration>>
  >({});
  // The item's position in the list, which pairs it with an image in a list
  // of images.
  const name = inputs.x?.name ?? '';
  const position =
    extractPositionFromFieldName(
      name,
      toPropName(name, inputUIData.selectedComponent),
    ) ?? 0;
  const src = useFocalPointImage(imageProp, position);

  const setPopoverOpenAndRefocus = (open: boolean) => {
    setPopoverOpen(open);
    if (!open) {
      setTimeout(() => triggerButtonRef.current?.focus(), 30);
    }
  };

  const handleRemove = (e: React.MouseEvent) => {
    e.preventDefault();
    const triggerElement = triggerRowRef.current;
    if (!triggerElement) return;
    setPopoverOpenAndRefocus(false);
    setTimeout(() => {
      const xInput = inputs.x?.element;
      const formId = xInput?.getAttribute('data-form-id');
      const removeButtonName: string | null = triggerDrupalRemoveButton(
        triggerElement,
        formId,
      );
      if (!removeButtonName || !name || !formId) {
        return;
      }
      const fieldNames = [
        name,
        name.replace(/\[x\]$/, '[y]'),
        name.replace(/\[x\]$/, '[_weight]'),
        removeButtonName,
      ];
      // If this is a component, update the model to reflect the removal
      // immediately, then fire the actual Drupal AJAX click.
      if (formId === 'component_instance_form') {
        const propName = toPropName(name, inputUIData.selectedComponent);
        const model = inputUIData.model?.[inputUIData.selectedComponent];
        if (!model || !isEvaluatedComponentModel(model)) {
          return;
        }
        patchComponent({
          type: editorFrameContext,
          componentInstanceUuid: inputUIData.selectedComponent,
          componentType: `${inputUIData.selectedComponentType}@${inputUIData.version}`,
          model: buildModelWithItemRemoved(model, propName, position),
        });
        // Second call: now that the model is patched, invoke the real
        // Drupal AJAX click (no formId passed so the click is not suppressed).
        setTimeout(() => triggerDrupalRemoveButton(triggerElement));
      }
      dispatch(
        removeFieldValue({ formId: formId as any, fieldName: fieldNames }),
      );
    });
  };

  return (
    <div style={{ display: 'contents' }}>
      <Popover.Root
        open={popoverOpen}
        onOpenChange={(open) => setPopoverOpenAndRefocus(open)}
      >
        <Flex
          ref={triggerRowRef}
          align="center"
          gap="2"
          className={styles.itemRow}
        >
          <Popover.Trigger asChild>
            <button
              data-multivalue-popover-trigger
              ref={triggerButtonRef}
              className={styles.listItem}
              type="button"
              aria-label={`Edit ${fieldLabel}: ${formatFocalPoint(point)}`}
            >
              <Flex align="center" gap="2" className={styles.itemText}>
                <FocalPointThumbnail src={src} point={point} />
                <Text size="2" data-canvas-multivalue-label>
                  {formatFocalPoint(point)}
                </Text>
              </Flex>
              <ArrowRightIcon className={styles.arrowIcon} />
            </button>
          </Popover.Trigger>
        </Flex>

        <Popover.Content
          forceMount={true}
          side="left"
          align="start"
          sideOffset={6}
          className={clsx(styles.popoverContent, pickerStyles.popover, [
            !popoverOpen && styles.visuallyHiddenInput,
          ])}
          onKeyDown={(e) => {
            if (e.key === 'Enter') {
              e.preventDefault();
              setPopoverOpenAndRefocus(false);
            }
          }}
        >
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

          <FocalPointPicker
            imageProp={imageProp}
            delta={position}
            onPointChange={(next, registrations) => {
              setPoint(next);
              setInputs(registrations);
            }}
          >
            {children}
          </FocalPointPicker>

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

export default DrupalFocalPointMultivalueForm;
