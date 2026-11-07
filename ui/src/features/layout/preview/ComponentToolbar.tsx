import clsx from 'clsx';
import {
  ArrowDownIcon,
  ArrowLeftIcon,
  ArrowRightIcon,
  ArrowTopLeftIcon,
  ArrowUpIcon,
  CopyIcon,
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
    <div
      className={clsx(styles.toolbar, { [styles.inside]: inside })}
      role="toolbar"
      aria-label={`${name} actions`}
      data-testid="canvas-component-toolbar"
      // Clicks on the toolbar are not clicks on the component.
      onClick={(ev) => ev.stopPropagation()}
    >
      <Tooltip content="Drag to move">
        <div className={styles.name} {...dragListeners}>
          <DragHandleDots2Icon aria-hidden />
          <span>{name}</span>
        </div>
      </Tooltip>
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
    </div>
  );
};

export default ComponentToolbar;
