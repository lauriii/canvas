import { useState } from 'react';
import clsx from 'clsx';
import { createPortal } from 'react-dom';
import { Cross2Icon, TargetIcon } from '@radix-ui/react-icons';
import * as Popover from '@radix-ui/react-popover';
import { Flex, Text } from '@radix-ui/themes';

import FocalPointPicker, {
  FocalPointThumbnail,
  formatFocalPoint,
  useFocalPointImage,
} from './FocalPointPicker';
import { focalPointSlotKey, useFocalPointSlot } from './focalPointSlots';

import type { ReactNode } from 'react';
import type { CoordinateRegistration, FocalPoint } from './FocalPointPicker';

import multivalueStyles from './DrupalInputMultivalueForm.module.css';
import styles from './FocalPointPicker.module.css';

/**
 * Renders a focal point control under the image it belongs to.
 *
 * The control (with the real x and y inputs) is portaled into the slot the
 * image widget registered, so the focal point reads as part of the image
 * rather than as a separate field. Until that slot exists, for example while
 * no image is selected, it stays mounted but hidden so the inputs keep their
 * form state. Without an image prop it renders in place.
 *
 * @see focalPointSlots.ts
 */
export const FoldedUnderImage = ({
  imageProp,
  position,
  children,
}: {
  imageProp?: string;
  // Null while not yet known: the control stays hidden in place.
  position: number | null;
  children: ReactNode;
}) => {
  const slot = useFocalPointSlot(
    imageProp && position !== null
      ? focalPointSlotKey(imageProp, position)
      : null,
  );
  if (!imageProp) {
    return <>{children}</>;
  }
  return slot ? createPortal(children, slot) : <div hidden>{children}</div>;
};

/**
 * A button summarizing a focal point, which opens the picker in a popover.
 */
export const FocalPointPopover = ({
  label,
  imageProp,
  position,
  onPointChange,
  open,
  onOpenChange,
  footer,
  children,
}: {
  label: string;
  imageProp?: string;
  position: number;
  onPointChange?: (
    point: FocalPoint | null,
    registrations: Partial<Record<'x' | 'y', CoordinateRegistration>>,
  ) => void;
  open: boolean;
  onOpenChange: (open: boolean) => void;
  footer?: ReactNode;
  children?: ReactNode;
}) => {
  const [point, setPoint] = useState<FocalPoint | null>(null);
  const src = useFocalPointImage(imageProp, position);

  return (
    <Popover.Root open={open} onOpenChange={onOpenChange}>
      <Popover.Trigger asChild>
        <button
          type="button"
          className={styles.trigger}
          data-canvas-focal-point-trigger
          aria-label={`Edit ${label}: ${formatFocalPoint(point)}`}
        >
          {imageProp ? (
            <TargetIcon aria-hidden="true" />
          ) : (
            <FocalPointThumbnail src={src} point={point} />
          )}
          <Text size="1" className={styles.triggerLabel}>
            {label}
          </Text>
          <Text size="1" className={styles.triggerValue}>
            {formatFocalPoint(point)}
          </Text>
        </button>
      </Popover.Trigger>
      <Popover.Content
        forceMount={true}
        side="left"
        align="start"
        sideOffset={6}
        className={clsx(multivalueStyles.popoverContent, styles.popover, [
          !open && multivalueStyles.visuallyHiddenInput,
        ])}
        onKeyDown={(e) => {
          if (e.key === 'Enter') {
            e.preventDefault();
            onOpenChange(false);
          }
        }}
      >
        <Flex
          justify="between"
          align="center"
          className={multivalueStyles.popoverHeader}
        >
          <Text
            size="1"
            weight="medium"
            className={multivalueStyles.popoverLabel}
          >
            {label}
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
            onPointChange?.(next, registrations);
          }}
        >
          {children}
        </FocalPointPicker>
        {footer}
      </Popover.Content>
    </Popover.Root>
  );
};

/**
 * The focal point widget for a single value prop.
 *
 * @see \Drupal\canvas\Plugin\Field\FieldWidget\CanvasFocalPointWidget
 * @see DrupalFocalPointMultivalueForm
 */
const DrupalFocalPoint = ({
  imageProp,
  children,
}: {
  imageProp?: string;
  children?: ReactNode;
}) => {
  const [open, setOpen] = useState(false);
  return (
    <FoldedUnderImage imageProp={imageProp} position={0}>
      <FocalPointPopover
        label="Focal point"
        imageProp={imageProp}
        position={0}
        open={open}
        onOpenChange={setOpen}
      >
        {children}
      </FocalPointPopover>
    </FoldedUnderImage>
  );
};

export default DrupalFocalPoint;
