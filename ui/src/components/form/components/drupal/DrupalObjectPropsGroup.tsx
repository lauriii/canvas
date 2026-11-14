import {
  Children,
  cloneElement,
  isValidElement,
  useEffect,
  useRef,
  useState,
} from 'react';
import clsx from 'clsx';
import {
  closestCenter,
  DndContext,
  KeyboardSensor,
  PointerSensor,
  useSensor,
  useSensors,
} from '@dnd-kit/core';
import {
  restrictToParentElement,
  restrictToVerticalAxis,
} from '@dnd-kit/modifiers';
import {
  arrayMove,
  SortableContext,
  sortableKeyboardCoordinates,
  useSortable,
  verticalListSortingStrategy,
} from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import {
  CopyIcon,
  Cross2Icon,
  DragHandleDots2Icon,
  PlusIcon,
  TrashIcon,
} from '@radix-ui/react-icons';
import * as Popover from '@radix-ui/react-popover';
import { Box, Button, Flex, Text } from '@radix-ui/themes';

import type { ReactElement, ReactNode } from 'react';
import type { DragEndEvent } from '@dnd-kit/core';
import type { Attributes } from '@/types/DrupalAttribute';

import styles from './DrupalObjectPropsGroup.module.css';

const ROW_SELECTOR = '[data-object-props-item-row]';
const HANDLE_SELECTOR = '[data-object-props-item-handle]';

/**
 * The name of the field that carries the target position of a moved item.
 *
 * @see \Drupal\canvas\Plugin\Canvas\ComponentSource\JsonSchemaPropsComponentSourceBase::objectPropsMoveItemSubmit()
 */
const POSITION_FIELD = 'canvas_object_props_position';

/**
 * The ID of the AJAX wrapper of an item list: the same before and after a
 * rebuild.
 */
const getListId = (element: Element | null) =>
  element?.closest('[id^="canvas-object-props-"]')?.id ?? '';

/**
 * The element to focus once the server has rebuilt an item list.
 *
 * The rebuilt list replaces the one that had focus, so keyboard users would
 * otherwise start over from the top of the page after every action.
 */
let focusAfterRebuild: {
  listId: string;
  selector: string;
  index: number;
} | null = null;

/**
 * Asks the rebuilt list to focus one of its rows.
 *
 * The request stands until the user does something else, because a rebuilt
 * list can render more than once.
 */
const requestFocusAfterRebuild = (
  trigger: Element | null,
  selector: string,
  index: number,
) => {
  focusAfterRebuild = { listId: getListId(trigger), selector, index };
  ['keydown', 'pointerdown'].forEach((type) =>
    document.addEventListener(type, () => (focusAfterRebuild = null), {
      capture: true,
      once: true,
    }),
  );
};

/**
 * The position of a row in its list.
 */
const getRowIndex = (trigger: Element | null) =>
  [
    ...(trigger
      ?.closest(`.${styles.itemList}`)
      ?.querySelectorAll(ROW_SELECTOR) ?? []),
  ].indexOf(trigger as Element);

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
 * styled "Add new" action to the hidden Drupal AJAX submit button. Rows are
 * reordered by dragging their handle, or from the keyboard: Enter on the handle
 * picks the row up, the arrow keys move it, Enter drops it and Escape cancels.
 * A drop clicks the hidden "Move" submit button of the row, so the server
 * rebuilds the list in the new order.
 *
 * @see themes/canvas_stark/templates/form/container--canvas-object-props-item-list.html.twig
 * @see DrupalMediaListContainer
 */
export const DrupalObjectPropsItemList = ({
  children,
}: {
  children?: ReactNode;
  attributes?: Attributes;
}) => {
  const wrapperRef = useRef<HTMLDivElement>(null);
  const [canAdd, setCanAdd] = useState(false);
  // The order of the rows after a drop, until the server has rebuilt the list.
  const [droppedOrder, setDroppedOrder] = useState<string[] | null>(null);

  // An AJAX response replaces this list with a rebuilt one, but React keeps
  // rendering the replaced list, out of sight. When the form reloads, it would
  // get rows whose deltas mean other items than in the list the author sees,
  // and write their values to the form state. A replaced list keeps its rows.
  const rowsRef = useRef(children);
  if (wrapperRef.current?.isConnected !== false) {
    rowsRef.current = children;
  }
  const childList = Children.toArray(rowsRef.current);
  const isItem = (child: ReactNode): child is ReactElement =>
    isValidElement(child) && child.type === DrupalObjectPropsItem;
  const items = childList.filter(isItem);
  const ids = items.map((_, index) => `item-${index}`);
  const order = droppedOrder?.length === ids.length ? droppedOrder : ids;

  const sensors = useSensors(
    // A click on a handle is not a drag.
    useSensor(PointerSensor, { activationConstraint: { distance: 3 } }),
    // The editor reserves the space bar for panning the preview.
    useSensor(KeyboardSensor, {
      coordinateGetter: sortableKeyboardCoordinates,
      // Tab leaves the handle, so it drops the row as well.
      keyboardCodes: {
        start: ['Enter'],
        cancel: ['Escape'],
        end: ['Enter', 'Tab'],
      },
    }),
  );

  useEffect(() => {
    const wrapper = wrapperRef.current;
    // The hidden "Add new" submit is only rendered while the group's
    // cardinality allows more items.
    setCanAdd(
      !!wrapper?.querySelector('input[data-object-props-add]:not(:disabled)'),
    );
    setDroppedOrder(null);
  }, [children]);

  // Tell screen reader users where a row is, instead of its internal ID.
  const positionOf = (id: string | number | undefined) =>
    `position ${order.indexOf(String(id)) + 1} of ${order.length}`;
  const announcements = {
    onDragStart: ({ active }: Pick<DragEndEvent, 'active'>) =>
      `Picked up the row at ${positionOf(active.id)}.`,
    onDragOver: ({ over }: Pick<DragEndEvent, 'over'>) =>
      over ? `The row is now at ${positionOf(over.id)}.` : undefined,
    onDragEnd: ({ over }: Pick<DragEndEvent, 'over'>) =>
      over ? `Dropped the row at ${positionOf(over.id)}.` : undefined,
    onDragCancel: () => 'Reordering was canceled.',
  };

  const handleAddNew = () => {
    const addButton = wrapperRef.current?.querySelector<HTMLInputElement>(
      'input[data-object-props-add]',
    );
    if (addButton) {
      triggerHiddenSubmit(addButton);
    }
  };

  const handleDragEnd = ({ active, over }: DragEndEvent) => {
    const wrapper = wrapperRef.current;
    const moveButton = wrapper?.querySelector<HTMLInputElement>(
      `[data-object-props-item="${active.id}"] input[data-object-props-move]:not(:disabled)`,
    );
    if (
      !wrapper ||
      !moveButton ||
      !over ||
      active.id === over.id ||
      // The list is about to be rebuilt.
      document.body.dataset.canvasAjaxBehaviors === 'true'
    ) {
      return;
    }
    const position = order.indexOf(String(over.id));
    setDroppedOrder(
      arrayMove(order, order.indexOf(String(active.id)), position),
    );
    // The hidden submit button says which item moves; this field says where to.
    wrapper
      .closest('form')
      ?.querySelectorAll(`input[name="${POSITION_FIELD}"]`)
      .forEach((input) => input.remove());
    const positionInput = document.createElement('input');
    positionInput.type = 'hidden';
    positionInput.name = POSITION_FIELD;
    positionInput.value = String(position);
    wrapper.append(positionInput);
    requestFocusAfterRebuild(wrapper, HANDLE_SELECTOR, position);
    triggerHiddenSubmit(moveButton);
  };

  return (
    <DndContext
      sensors={sensors}
      collisionDetection={closestCenter}
      modifiers={[restrictToVerticalAxis, restrictToParentElement]}
      accessibility={{
        announcements,
        screenReaderInstructions: {
          draggable:
            'To pick up a row, press Enter. Use the arrow keys to move it. Press Enter again to drop it, or press Escape to cancel.',
        },
      }}
      onDragEnd={handleDragEnd}
    >
      <SortableContext items={order} strategy={verticalListSortingStrategy}>
        <div ref={wrapperRef} className={styles.itemList}>
          {order.map((id) =>
            cloneElement(items[ids.indexOf(id)], { key: id, sortableId: id }),
          )}
          {childList.filter((child) => !isItem(child))}
          {canAdd && (
            <button
              type="button"
              className={styles.addNew}
              onClick={handleAddNew}
            >
              <PlusIcon />
              Add new
            </button>
          )}
        </div>
      </SortableContext>
    </DndContext>
  );
};

/**
 * One item of a multi-value group prop.
 *
 * Displays a compact row that opens a popover containing the item's
 * sub-property widgets and the "Duplicate" and "Remove" actions, which are
 * wired to hidden Drupal AJAX submit buttons. The server only renders the
 * buttons of the actions the list allows: no "Remove" at `minItems`, no
 * "Duplicate" at `maxItems` and no "Move" for a single row. The popover
 * content is force-mounted so the widgets stay in the form while the popover
 * is closed.
 *
 * @see themes/canvas_stark/templates/form/container--canvas-object-props-item.html.twig
 * @see DrupalInputMultivalueForm
 */
export const DrupalObjectPropsItem = ({
  children,
  attributes = {},
  sortableId = '',
}: {
  children?: ReactNode;
  attributes?: Attributes;
  // Set by DrupalObjectPropsItemList.
  sortableId?: string;
}) => {
  const [popoverOpen, setPopoverOpen] = useState(false);
  const [displayValue, setDisplayValue] = useState('');
  // The actions whose hidden submit button the server rendered.
  const [actions, setActions] = useState({
    remove: false,
    duplicate: false,
    move: false,
  });
  const triggerButtonRef = useRef<HTMLButtonElement>(null);
  const popoverContainerRef = useRef<HTMLDivElement>(null);
  const itemLabel = String(attributes['data-item-label'] || 'Item');
  const displayText = displayValue === '' ? itemLabel : displayValue;
  const {
    attributes: handleAttributes,
    listeners,
    setNodeRef,
    setActivatorNodeRef,
    transform,
    transition,
    isDragging,
  } = useSortable({ id: sortableId, disabled: !actions.move });

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
    setTimeout(() => {
      updateDisplayValue();
      const hasSubmit = (action: string) =>
        !!popoverContainerRef.current?.querySelector(
          `input[data-object-props-${action}]:not(:disabled)`,
        );
      setActions({
        remove: hasSubmit('remove'),
        duplicate: hasSubmit('duplicate'),
        move: hasSubmit('move'),
      });
    });
  }, [children]);

  // Take over the focus from the list this one replaces. The drag handle only
  // renders once the actions are known.
  useEffect(() => {
    const trigger = triggerButtonRef.current;
    if (focusAfterRebuild?.listId !== getListId(trigger)) {
      return;
    }
    const rows = trigger
      ?.closest(`.${styles.itemList}`)
      ?.querySelectorAll(ROW_SELECTOR);
    const target = trigger?.parentElement?.querySelector<HTMLElement>(
      focusAfterRebuild.selector,
    );
    if (
      target &&
      getRowIndex(trigger) ===
        Math.min(focusAfterRebuild.index, (rows?.length ?? 1) - 1)
    ) {
      target.focus();
    }
  }, [actions]);

  const setPopoverOpenAndRefocus = (open: boolean) => {
    setPopoverOpen(open);
    if (!open) {
      setTimeout(() => triggerButtonRef.current?.focus(), 30);
    }
  };

  // Clicks the hidden submit button of an action. The rebuilt list then
  // focuses the row `focusOffset` rows after this one: the copy of a
  // duplicated row, or the row that takes the place of a removed one.
  const handleAction =
    (action: 'remove' | 'duplicate', focusOffset: number) =>
    (e: React.MouseEvent) => {
      e.preventDefault();
      const trigger = triggerButtonRef.current;
      requestFocusAfterRebuild(
        trigger,
        ROW_SELECTOR,
        getRowIndex(trigger) + focusOffset,
      );
      setPopoverOpenAndRefocus(false);
      setTimeout(() => {
        const submit =
          popoverContainerRef.current?.querySelector<HTMLInputElement>(
            `input[data-object-props-${action}]:not(:disabled)`,
          );
        if (submit) {
          triggerHiddenSubmit(submit);
        }
      });
    };

  return (
    <Popover.Root open={popoverOpen} onOpenChange={setPopoverOpenAndRefocus}>
      {/* The popover is placed beside the whole row, not beside its label. */}
      <Popover.Anchor asChild>
        <div
          ref={setNodeRef}
          className={clsx(styles.itemRow, isDragging && styles.dragging)}
          style={{ transform: CSS.Translate.toString(transform), transition }}
        >
          {actions.move && (
            <button
              type="button"
              ref={setActivatorNodeRef}
              className={styles.dragHandle}
              data-object-props-item-handle
              aria-label={`Reorder ${itemLabel}: ${displayText}`}
              {...handleAttributes}
              {...listeners}
            >
              <DragHandleDots2Icon />
            </button>
          )}
          <Popover.Trigger asChild>
            <button
              data-object-props-item-row
              ref={triggerButtonRef}
              className={styles.itemTrigger}
              type="button"
              aria-label={`Edit ${itemLabel}: ${displayText}`}
            >
              <Text size="2" className={styles.itemText}>
                {displayText}
              </Text>
            </button>
          </Popover.Trigger>
        </div>
      </Popover.Anchor>
      <Popover.Content
        forceMount={true}
        ref={popoverContainerRef}
        data-object-props-item={sortableId}
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
          <Text size="1" weight="bold" className={styles.popoverLabel}>
            {itemLabel}
          </Text>
          <Popover.Close aria-label="Close">
            <Cross2Icon />
          </Popover.Close>
        </Flex>
        <Box>{children}</Box>
        {(actions.duplicate || actions.remove) && (
          <Flex justify="center" gap="5" className={styles.actions}>
            {actions.duplicate && (
              <Button
                data-object-props-duplicate-item="true"
                variant="ghost"
                size="1"
                onClick={handleAction('duplicate', 1)}
              >
                <CopyIcon />
                Duplicate
              </Button>
            )}
            {actions.remove && (
              <Button
                data-object-props-remove-item="true"
                variant="ghost"
                color="red"
                size="1"
                onClick={handleAction('remove', 0)}
              >
                <TrashIcon />
                Remove
              </Button>
            )}
          </Flex>
        )}
      </Popover.Content>
    </Popover.Root>
  );
};

/**
 * The widgets of a single-value group prop with
 * `x-canvas-object-display: inline`.
 *
 * Renders the sub-property widgets in the form, below the group label, instead
 * of in the popover of a row. A rule along their left edge groups them.
 *
 * @see themes/canvas_stark/templates/form/container--canvas-object-props-inline.html.twig
 */
export const DrupalObjectPropsInline = ({
  children,
  attributes = {},
}: {
  children?: ReactNode;
  attributes?: Attributes;
}) => (
  <div
    role="group"
    aria-label={String(attributes['data-item-label'] || 'Item')}
    className={styles.inline}
  >
    {children}
  </div>
);
