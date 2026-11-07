import clsx from 'clsx';
import {
  ArrowDownIcon,
  ArrowLeftIcon,
  ArrowRightIcon,
  ArrowTopLeftIcon,
  ArrowUpIcon,
  Component1Icon,
  CopyIcon,
  DiscIcon,
  DotsHorizontalIcon,
  DragHandleDots2Icon,
  TrashIcon,
} from '@radix-ui/react-icons';
import { DropdownMenu, Tooltip } from '@radix-ui/themes';

import { ComponentContextMenuContent } from '@/features/layout/preview/ComponentContextMenu';
import useComponentActions from '@/hooks/useComponentActions';
import useComponentSelection from '@/hooks/useComponentSelection';

import type React from 'react';
import type { DraggableSyntheticListeners } from '@dnd-kit/core';
import type {
  ComponentNode,
  SlotNode,
} from '@/features/layout/layoutModelSlice';

import styles from './ComponentToolbar.module.css';

interface ComponentToolbarProps {
  component: ComponentNode;
  name: string;
  parentSlot?: SlotNode;
  index: number;
  siblingCount: number;
  horizontal: boolean;
  // Places the toolbar inside the component, for components at the top of
  // the page that leave no room above them.
  inside: boolean;
  dragListeners?: DraggableSyntheticListeners;
}

const ToolbarButton: React.FC<{
  label: string;
  onClick: (ev: React.MouseEvent) => void;
  disabled?: boolean;
  children: React.ReactNode;
}> = ({ label, onClick, disabled, children }) => (
  <Tooltip content={label}>
    <button
      type="button"
      className={styles.button}
      aria-label={label}
      disabled={disabled}
      onClick={onClick}
    >
      {children}
    </button>
  </Tooltip>
);

interface ComponentTagProps {
  name: string;
  id: string;
  isMarker: boolean;
  inside: boolean;
  dragListeners?: DraggableSyntheticListeners;
  children?: React.ReactNode;
}

/**
 * The name of a component in the preview, with its actions if any.
 *
 * The name of a hovered component and the toolbar of the selected one look
 * the same and are in the same place: selecting a component adds its actions.
 */
export const ComponentTag: React.FC<ComponentTagProps> = ({
  name,
  id,
  isMarker,
  inside,
  dragListeners,
  children,
}) => {
  const label = (
    <div className={styles.name} {...dragListeners}>
      {/* The type of the component, or where to grab it to drag it. */}
      <span className={styles.icon} aria-hidden>
        {isMarker ? (
          <DiscIcon className={styles.typeIcon} />
        ) : (
          <Component1Icon className={styles.typeIcon} />
        )}
        <DragHandleDots2Icon className={styles.handle} />
      </span>
      <span id={`${id}-name`}>{name}</span>
    </div>
  );
  return (
    <div
      className={clsx(styles.toolbar, {
        [styles.inside]: inside,
        [styles.marker]: isMarker,
      })}
      data-testid={children ? 'canvas-component-toolbar' : 'canvas-name-tag'}
      role={children ? 'toolbar' : undefined}
      aria-label={children ? `${name} actions` : undefined}
      // Clicks on the toolbar are not clicks on the component.
      onClick={children ? (ev) => ev.stopPropagation() : undefined}
    >
      {dragListeners ? (
        <Tooltip content="Drag to move">{label}</Tooltip>
      ) : (
        label
      )}
      {children}
    </div>
  );
};

/**
 * The actions for the selected component, shown on it in the preview.
 */
const ComponentToolbar: React.FC<ComponentToolbarProps> = ({
  component,
  name,
  parentSlot,
  index,
  siblingCount,
  horizontal,
  inside,
  dragListeners,
}) => {
  const { isMarker, deleteComponent, duplicateComponent, moveUp, moveDown } =
    useComponentActions(component);
  const { setSelectedComponent } = useComponentSelection();
  // Slot ids are the uuid of their component and the slot name.
  const parentUuid = parentSlot?.id.split('/')[0];

  return (
    <ComponentTag
      name={name}
      id={component.uuid}
      isMarker={isMarker}
      inside={inside}
      dragListeners={dragListeners}
    >
      <span className={styles.divider} />
      {parentUuid && (
        <ToolbarButton
          label="Select parent"
          onClick={() => setSelectedComponent(parentUuid)}
        >
          <ArrowTopLeftIcon />
        </ToolbarButton>
      )}
      <ToolbarButton
        label={horizontal ? 'Move left' : 'Move up'}
        onClick={moveUp}
        disabled={index === 0}
      >
        {horizontal ? <ArrowLeftIcon /> : <ArrowUpIcon />}
      </ToolbarButton>
      <ToolbarButton
        label={horizontal ? 'Move right' : 'Move down'}
        onClick={moveDown}
        disabled={index === siblingCount - 1}
      >
        {horizontal ? <ArrowRightIcon /> : <ArrowDownIcon />}
      </ToolbarButton>
      {!isMarker && (
        <>
          <ToolbarButton label="Duplicate" onClick={duplicateComponent}>
            <CopyIcon />
          </ToolbarButton>
          <ToolbarButton label="Delete" onClick={deleteComponent}>
            <TrashIcon />
          </ToolbarButton>
        </>
      )}
      <DropdownMenu.Root>
        <Tooltip content="More actions">
          <DropdownMenu.Trigger>
            <button
              type="button"
              className={styles.button}
              aria-label="More actions"
            >
              <DotsHorizontalIcon />
            </button>
          </DropdownMenu.Trigger>
        </Tooltip>
        <ComponentContextMenuContent
          component={component}
          menuType="dropdown"
        />
      </DropdownMenu.Root>
    </ComponentTag>
  );
};

export default ComponentToolbar;
