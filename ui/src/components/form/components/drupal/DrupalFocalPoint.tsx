import { useState } from 'react';
import clsx from 'clsx';
import { Cross2Icon } from '@radix-ui/react-icons';
import * as Popover from '@radix-ui/react-popover';
import { Flex, Text } from '@radix-ui/themes';

import FocalPointPicker, {
  FocalPointThumbnail,
  formatFocalPoint,
  useFocalPointImage,
} from './FocalPointPicker';

import type { ReactNode } from 'react';
import type { FocalPoint } from './FocalPointPicker';

import multivalueStyles from './DrupalInputMultivalueForm.module.css';
import styles from './FocalPointPicker.module.css';

/**
 * The focal point widget for a single value prop.
 *
 * A button summarizing the point opens a popover with the picker. The popover
 * is always mounted so the x and y inputs stay registered with the form.
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
  const [point, setPoint] = useState<FocalPoint | null>(null);
  const src = useFocalPointImage(imageProp, 0);

  return (
    <Popover.Root open={open} onOpenChange={setOpen}>
      <Popover.Trigger asChild>
        <button
          type="button"
          className={styles.trigger}
          data-canvas-focal-point-trigger
          aria-label={`Edit focal point: ${formatFocalPoint(point)}`}
        >
          <FocalPointThumbnail src={src} point={point} />
          <Text size="2">{formatFocalPoint(point)}</Text>
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
            setOpen(false);
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
            Focal point
          </Text>
          <Popover.Close aria-label="Close">
            <Cross2Icon />
          </Popover.Close>
        </Flex>
        <FocalPointPicker
          imageProp={imageProp}
          onPointChange={(next) => setPoint(next)}
        >
          {children}
        </FocalPointPicker>
      </Popover.Content>
    </Popover.Root>
  );
};

export default DrupalFocalPoint;
