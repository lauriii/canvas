import { useState } from 'react';
import clsx from 'clsx';
import { Cross2Icon, TargetIcon } from '@radix-ui/react-icons';
import * as Popover from '@radix-ui/react-popover';
import { Flex, Text } from '@radix-ui/themes';

import FocalPointPicker, { formatFocalPoint } from './FocalPointPicker';

import type { ReactNode } from 'react';
import type { FocalPoint } from './FocalPointPicker';

import multivalueStyles from './DrupalInputMultivalueForm.module.css';
import styles from './FocalPointPicker.module.css';

/**
 * The focal point of one image in a media library widget.
 *
 * Renders under the image as a row summarizing the point, which opens the
 * picker in a popover. The popover is always mounted so the x and y inputs
 * (`children`) stay registered with the form.
 *
 * @see \Drupal\canvas\Plugin\Canvas\ComponentSource\JsonSchemaPropsComponentSourceBase::addFocalPointInputs()
 */
const DrupalFocalPoint = ({
  src,
  children,
}: {
  src?: string;
  children?: ReactNode;
}) => {
  const [open, setOpen] = useState(false);
  const [point, setPoint] = useState<FocalPoint | null>(null);

  return (
    <div className={styles.root} data-canvas-focal-point>
      <Popover.Root open={open} onOpenChange={setOpen}>
        <Popover.Trigger asChild>
          <button
            type="button"
            className={styles.trigger}
            data-canvas-focal-point-trigger
            aria-label={`Edit focal point: ${formatFocalPoint(point)}`}
          >
            <TargetIcon aria-hidden="true" />
            <Text size="1" className={styles.triggerLabel}>
              Focal point
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
          <FocalPointPicker src={src} onPointChange={setPoint}>
            {children}
          </FocalPointPicker>
        </Popover.Content>
      </Popover.Root>
    </div>
  );
};

export default DrupalFocalPoint;
