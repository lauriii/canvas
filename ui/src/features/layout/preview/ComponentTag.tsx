import clsx from 'clsx';
import {
  Component1Icon,
  DiscIcon,
  DragHandleDots2Icon,
} from '@radix-ui/react-icons';
import { Tooltip } from '@radix-ui/themes';

import type React from 'react';
import type { DraggableSyntheticListeners } from '@dnd-kit/core';

import styles from './ComponentToolbar.module.css';

// Moves focus between the buttons of a toolbar with the arrow keys.
function handleToolbarKeyDown(event: React.KeyboardEvent<HTMLDivElement>) {
  const buttons = Array.from(
    event.currentTarget.querySelectorAll<HTMLButtonElement>(
      'button:not(:disabled)',
    ),
  );
  const index = buttons.indexOf(event.target as HTMLButtonElement);
  const next = {
    ArrowLeft: index - 1,
    ArrowRight: index + 1,
    Home: 0,
    End: buttons.length - 1,
  }[event.key];
  if (index !== -1 && next !== undefined) {
    event.preventDefault();
    buttons[(next + buttons.length) % buttons.length]?.focus();
  }
}

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
      onKeyDown={children ? handleToolbarKeyDown : undefined}
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
