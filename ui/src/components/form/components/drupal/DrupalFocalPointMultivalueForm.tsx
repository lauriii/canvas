import { useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { TargetIcon, TrashIcon } from '@radix-ui/react-icons';
import { Button, Flex, Text } from '@radix-ui/themes';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import { toPropName } from '@/components/form/react-hook-form/fields/componentFormData';
import { removeFieldValue } from '@/features/form/formStateSlice';
import { isEvaluatedComponentModel } from '@/features/layout/layoutModelSlice';
import { selectEditorFrameContext } from '@/features/ui/uiSlice';
import useInputUIData from '@/hooks/useInputUIData';
import { useUpdateComponentMutation } from '@/services/preview';

import { FocalPointPopover, FoldedUnderImage } from './DrupalFocalPoint';
import { focalPointSlotKey, useFocalPointSlot } from './focalPointSlots';
import {
  buildModelWithItemRemoved,
  extractPositionFromFieldName,
  isRemoveButtonEnabled,
  triggerDrupalRemoveButton,
} from './multivalueFormUtils';

import type { ReactNode } from 'react';
import type { CoordinateRegistration } from './FocalPointPicker';

import styles from './DrupalInputMultivalueForm.module.css';
import pickerStyles from './FocalPointPicker.module.css';

// The list position to open once "Set focal point" has added it.
let pendingPosition: number | null = null;

/**
 * Adds list items until the one at a position exists, then opens it.
 *
 * Each "Add" is a Drupal AJAX request that adds one item, so this waits for
 * each to finish before adding the next.
 *
 * @param listName
 *   The list's input name prefix, such as `canvas_component_props[uuid][prop]`.
 * @param propName
 *   The prop name.
 * @param position
 *   The position to add items up to.
 */
const addFocalPointsUntil = (
  listName: string,
  propName: string,
  position: number,
) => {
  pendingPosition = position;
  const step = () => {
    if (
      document.querySelector(`input[name="${listName}[${position}][x]"]`) ||
      pendingPosition !== position
    ) {
      return;
    }
    const button = document.querySelector<HTMLInputElement>(
      `[data-multivalue-add-new][name$="_${propName}_add_more"]`,
    );
    if (!button) {
      return;
    }
    // The new item renders shortly after the request completes.
    document.addEventListener('drupalAjaxStop', () => setTimeout(step, 100), {
      once: true,
    });
    button.dispatchEvent(new MouseEvent('mousedown', { bubbles: true }));
    button.click();
  };
  step();
};

/**
 * Offers to set a focal point for an image that has no list item yet.
 */
const MissingFocalPoint = ({
  imageProp,
  position,
  onSet,
}: {
  imageProp: string;
  position: number;
  onSet: () => void;
}) => {
  const slot = useFocalPointSlot(focalPointSlotKey(imageProp, position));
  if (!slot) {
    return null;
  }
  return createPortal(
    <button
      type="button"
      className={pickerStyles.trigger}
      data-canvas-focal-point-add
      onClick={onSet}
    >
      <TargetIcon aria-hidden="true" />
      <Text size="1" className={pickerStyles.triggerLabel}>
        Set focal point
      </Text>
    </button>,
    slot,
  );
};

/**
 * The focal point widget for one item of a multivalue prop.
 *
 * With an image prop, the item renders under the image at the same position in
 * the paired list of images, like the single value widget. Without one, it
 * follows the other multivalue widgets: a list row that opens a popover with
 * the picker and a Remove button.
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
  const [popoverOpen, setPopoverOpen] = useState(false);
  const [inputs, setInputs] = useState<
    Partial<Record<'x' | 'y', CoordinateRegistration>>
  >({});
  // The item's position in the list, which pairs it with an image in a list
  // of images.
  const name = inputs.x?.name ?? '';
  const propName = toPropName(name, inputUIData.selectedComponent);
  const position = extractPositionFromFieldName(name, propName) ?? 0;

  // The last item also offers focal points for images without an item yet.
  const images = imageProp
    ? (inputUIData.model?.[inputUIData.selectedComponent] as any)?.resolved?.[
        imageProp
      ]
    : null;
  const imageCount = Array.isArray(images) ? images.length : 0;
  const itemCount = name
    ? document.querySelectorAll(
        `input[name^="${name.replace(/\[\d+\]\[x\]$/, '[')}"][name$="[x]"]`,
      ).length
    : 0;
  const isLast = !!name && position === itemCount - 1;

  // Open the item a "Set focal point" request added.
  useEffect(() => {
    if (name && pendingPosition === position) {
      pendingPosition = null;
      setPopoverOpen(true);
    }
  }, [name, position]);

  const handleRemove = (e: React.MouseEvent) => {
    e.preventDefault();
    const triggerElement = triggerRowRef.current;
    if (!triggerElement) return;
    setPopoverOpen(false);
    setTimeout(() => {
      const formId = inputs.x?.element?.getAttribute('data-form-id');
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

  const popover = (
    <FocalPointPopover
      label={imageProp ? 'Focal point' : fieldLabel}
      imageProp={imageProp}
      position={position}
      open={popoverOpen}
      onOpenChange={setPopoverOpen}
      // Keep the last known inputs: moving the control under its image
      // remounts the picker, which starts without registrations.
      onPointChange={(_, registrations) => {
        if (registrations.x) {
          setInputs(registrations);
        }
      }}
      footer={
        // Under an image, removing an item would shift the later focal points
        // onto other images, so those items offer "Reset to center" instead.
        !imageProp && (
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
        )
      }
    >
      {children}
    </FocalPointPopover>
  );

  if (!imageProp) {
    return (
      <Flex ref={triggerRowRef} align="center" className={styles.itemRow}>
        {popover}
      </Flex>
    );
  }

  return (
    <>
      <FoldedUnderImage imageProp={imageProp} position={name ? position : null}>
        {popover}
      </FoldedUnderImage>
      {isLast &&
        Array.from(
          { length: Math.max(0, imageCount - itemCount) },
          (_, i) => itemCount + i,
        ).map((missing) => (
          <MissingFocalPoint
            key={missing}
            imageProp={imageProp}
            position={missing}
            onSet={() =>
              addFocalPointsUntil(
                name.replace(/\[\d+\]\[x\]$/, ''),
                propName,
                missing,
              )
            }
          />
        ))}
    </>
  );
};

export default DrupalFocalPointMultivalueForm;
