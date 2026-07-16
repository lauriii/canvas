import { useEffect, useRef, useState } from 'react';
import clsx from 'clsx';
import {
  Cross2Icon,
  DragHandleDots2Icon,
  PlusIcon,
  TrashIcon,
} from '@radix-ui/react-icons';
import * as Popover from '@radix-ui/react-popover';
import { Box, Button, Flex, Text } from '@radix-ui/themes';

import type { ReactNode } from 'react';
import type { Attributes } from '@/types/DrupalAttribute';

import styles from './DrupalObjectPropsGroup.module.css';

/**
 * Dispatches the mousedown + click sequence Drupal's AJAX system listens for
 * on a hidden submit button of a multi-value group prop.
 */
const triggerHiddenSubmit = (button: HTMLInputElement) => {
  document.body.setAttribute('data-canvas-ajax-behaviors', 'true');
  button.dispatchEvent(
    new MouseEvent('mousedown', { bubbles: true, cancelable: true }),
  );
  button.click();
};

/**
 * Item list of a multi-value group prop.
 *
 * Renders the item rows inside a bordered box and forwards clicks on the
 * styled "Add new" action to the hidden Drupal AJAX submit button.
 *
 * @see themes/canvas_stark/templates/form/container--canvas-object-props-item-list.html.twig
 */
export const DrupalObjectPropsItemList = ({
  children,
}: {
  children?: ReactNode;
  attributes?: Attributes;
}) => {
  const wrapperRef = useRef<HTMLDivElement>(null);
  const [canAdd, setCanAdd] = useState(false);

  // The hidden "Add new" submit is only rendered while the group's
  // cardinality allows more items.
  useEffect(() => {
    setCanAdd(
      !!wrapperRef.current?.querySelector('input[data-object-props-add]'),
    );
  }, [children]);

  const handleAddNew = () => {
    const addButton = wrapperRef.current?.querySelector<HTMLInputElement>(
      'input[data-object-props-add]',
    );
    if (addButton) {
      triggerHiddenSubmit(addButton);
    }
  };

  return (
    <div ref={wrapperRef} className={styles.itemList}>
      {children}
      {canAdd && (
        <button type="button" className={styles.addNew} onClick={handleAddNew}>
          <PlusIcon />
          Add new
        </button>
      )}
    </div>
  );
};

/**
 * One item of a multi-value group prop.
 *
 * Displays a compact row that opens a popover containing the item's
 * sub-property widgets and a "Remove" action wired to the hidden Drupal AJAX
 * submit button. The popover content is force-mounted so the widgets stay in
 * the form while the popover is closed.
 *
 * @see themes/canvas_stark/templates/form/container--canvas-object-props-item.html.twig
 * @see DrupalInputMultivalueForm
 */
export const DrupalObjectPropsItem = ({
  children,
  attributes = {},
}: {
  children?: ReactNode;
  attributes?: Attributes;
}) => {
  const [popoverOpen, setPopoverOpen] = useState(false);
  const [displayValue, setDisplayValue] = useState('');
  const triggerButtonRef = useRef<HTMLButtonElement>(null);
  const popoverContainerRef = useRef<HTMLDivElement>(null);
  const itemLabel = String(attributes['data-item-label'] || 'Item');
  const displayText = displayValue === '' ? itemLabel : displayValue;

  // Preview the first populated text-like sub-value in the row, like the
  // multivalue field rows do for their single value.
  const updateDisplayValue = () => {
    const container = popoverContainerRef.current;
    if (!container) {
      return;
    }
    const candidates = container.querySelectorAll<
      HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement
    >(
      'input[type="text"], input[type="url"], input[type="email"], input[type="number"], input[type="tel"], textarea',
    );
    for (const candidate of candidates) {
      if (candidate.value.trim() !== '') {
        setDisplayValue(candidate.value.trim());
        return;
      }
    }
    setDisplayValue('');
  };

  useEffect(() => {
    // Wait a tick so the force-mounted popover children have rendered.
    setTimeout(updateDisplayValue);
  }, [children]);

  const setPopoverOpenAndRefocus = (open: boolean) => {
    setPopoverOpen(open);
    if (!open) {
      setTimeout(() => triggerButtonRef.current?.focus(), 30);
    }
  };

  const handleRemove = (e: React.MouseEvent) => {
    e.preventDefault();
    setPopoverOpenAndRefocus(false);
    setTimeout(() => {
      const removeButton =
        popoverContainerRef.current?.querySelector<HTMLInputElement>(
          'input[data-object-props-remove]',
        );
      if (removeButton) {
        triggerHiddenSubmit(removeButton);
      }
    });
  };

  return (
    <Popover.Root open={popoverOpen} onOpenChange={setPopoverOpenAndRefocus}>
      <Popover.Trigger asChild>
        <button
          data-object-props-item-row
          ref={triggerButtonRef}
          className={styles.itemRow}
          type="button"
          aria-label={`Edit ${itemLabel}: ${displayText}`}
        >
          <DragHandleDots2Icon className={styles.dragIcon} />
          <Text size="2" className={styles.itemText}>
            {displayText}
          </Text>
        </button>
      </Popover.Trigger>
      <Popover.Content
        forceMount={true}
        ref={popoverContainerRef}
        side="left"
        align="start"
        sideOffset={36}
        className={clsx(styles.popoverContent, [
          !popoverOpen && styles.visuallyHiddenContent,
        ])}
        onInput={() => setTimeout(updateDisplayValue)}
        onInteractOutside={(e) => {
          // Keep the popover open while the user interacts with elements
          // rendered in portals outside it: jQuery UI autocomplete
          // suggestions and Drupal dialogs (e.g. the media library modal).
          const target = e.target as Element | null;
          if (
            target?.closest(
              '.ui-autocomplete, .ui-menu, .ui-dialog, .ui-widget-overlay',
            )
          ) {
            e.preventDefault();
          }
        }}
      >
        <Flex justify="between" align="center" className={styles.popoverHeader}>
          <Text size="1" weight="medium" className={styles.popoverLabel}>
            {itemLabel}
          </Text>
          <Popover.Close aria-label="Close">
            <Cross2Icon />
          </Popover.Close>
        </Flex>
        <Box>{children}</Box>
        <Flex justify="center" className={styles.removeButtonContainer}>
          <Button
            data-object-props-remove-item="true"
            variant="ghost"
            color="red"
            size="1"
            onClick={handleRemove}
          >
            <TrashIcon />
            Remove
          </Button>
        </Flex>
      </Popover.Content>
    </Popover.Root>
  );
};
