import {
  ArrowDownIcon,
  ArrowLeftIcon,
  ArrowRightIcon,
  ArrowTopLeftIcon,
  ArrowUpIcon,
  CopyIcon,
  DotsHorizontalIcon,
  TrashIcon,
} from '@radix-ui/react-icons';
import { DropdownMenu, Tooltip } from '@radix-ui/themes';

import { ComponentContextMenuContent } from '@/features/layout/preview/ComponentContextMenu';
import { ComponentTag } from '@/features/layout/preview/ComponentTag';
import useComponentActions from '@/hooks/useComponentActions';
import useComponentSelection from '@/hooks/useComponentSelection';
import { isMarkerComponentType } from '@/services/pageVariants';

import type React from 'react';
import type { DraggableSyntheticListeners } from '@dnd-kit/core';
import type { ComponentNode } from '@/features/layout/layoutModelSlice';

import styles from './ComponentToolbar.module.css';

interface ComponentToolbarProps {
  component: ComponentNode;
  name: string;
  // The component the component is in, if any.
  parentUuid?: string;
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
  parentUuid,
  index,
  siblingCount,
  horizontal,
  inside,
  dragListeners,
}) => {
  // Also true for components that contain a marker: they can't be
  // duplicated or deleted either.
  const { isMarker, deleteComponent, duplicateComponent, moveUp, moveDown } =
    useComponentActions(component);
  const { setSelectedComponent } = useComponentSelection();

  return (
    <ComponentTag
      name={name}
      id={component.uuid}
      isMarker={isMarkerComponentType(component.type)}
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
