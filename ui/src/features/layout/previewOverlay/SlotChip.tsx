import { useState } from 'react';
import clsx from 'clsx';
import { useParams } from 'react-router';
import { useDndContext } from '@dnd-kit/core';
import { PlusIcon } from '@radix-ui/react-icons';

import { useAppSelector } from '@/app/hooks';
import { slotOccupancy } from '@/features/layout/slot-utils';
import SlotAddMenu from '@/features/layout/SlotAddMenu';
import { selectHoveredComponent } from '@/features/ui/uiSlice';
import { useSlotCandidates, useSlotRule } from '@/hooks/useSlotRestrictions';

import type React from 'react';
import type {
  ComponentNode,
  SlotNode,
} from '@/features/layout/layoutModelSlice';

import styles from '@/features/layout/previewOverlay/PreviewOverlay.module.css';

export interface SlotChipProps {
  slot: SlotNode;
  slotName: string;
  parentComponent: ComponentNode;
}

/**
 * What a governed slot holds, and the way to add to it, on the canvas.
 *
 * Drawn on the slot rather than inside its empty state, so a slot that already
 * holds something — where an author most needs to see the limit and reach for
 * one more — gets the same affordance as an empty one.
 *
 * Shown only while the author is working on the component that owns the slot,
 * never at rest. Canvas draws one piece of canvas chrome at a time and reveals
 * it on demand; a badge pinned to every governed slot on the page would be a
 * scatter of permanent decoration in a surface built to stay quiet. The
 * always-on signal that a slot is governed lives in the Layers panel, which is
 * where the structure of the page belongs.
 *
 * @see \Drupal\canvas\SlotRestrictions
 * @see \Drupal\canvas\ui\src\features\layout\preview\NameTag.tsx
 */
const SlotChip: React.FC<SlotChipProps> = ({
  slot,
  slotName,
  parentComponent,
}) => {
  const rule = useSlotRule(slot);
  const candidates = useSlotCandidates(slot);
  // The chip is the one interactive hole in a pointer-transparent overlay, so
  // it has to get out of the way of a drag rather than swallow the drop.
  const { active: dragInFlight } = useDndContext();
  const { componentId: selectedComponent } = useParams();
  const hovered = useAppSelector(selectHoveredComponent);
  // The chip has to hold itself up once the pointer is on it. Everything
  // underneath is preview chrome that changes what counts as hovered, so a chip
  // that relied only on the surrounding hover state would be pulled out from
  // under the click that is trying to open it.
  const [isMenuOpen, setIsMenuOpen] = useState(false);
  const [isChipHovered, setIsChipHovered] = useState(false);

  // The chip belongs to whatever the author is working on: the component that
  // owns the slot, the slot itself, or any component already in it. That last
  // one matters most — reaching for a second card while pointing at the first
  // is the whole gesture, and the affordance must not vanish on the way there.
  const inPlay = (uuid: string | undefined) =>
    uuid !== undefined &&
    (uuid === parentComponent.uuid ||
      uuid === slot.id ||
      slot.components.some((child) => child.uuid === uuid));

  const isInPlay =
    isMenuOpen || isChipHovered || inPlay(hovered) || inPlay(selectedComponent);

  const occupancy = slotOccupancy(rule, slot.components.length);

  if (
    dragInFlight ||
    !isInPlay ||
    (occupancy === null && candidates.length === 0)
  ) {
    return null;
  }

  return (
    <div
      className={styles.slotChip}
      onMouseEnter={() => setIsChipHovered(true)}
      onMouseLeave={() => setIsChipHovered(false)}
      // Reaching for the add menu is not a click on the page behind it, and
      // above all it is not the start of a drag: the slot sits inside a
      // draggable component overlay, whose pointer sensor would otherwise
      // claim the press before the menu ever opens.
      onClick={(event) => event.stopPropagation()}
      onPointerDown={(event) => event.stopPropagation()}
      onMouseDown={(event) => event.stopPropagation()}
    >
      {occupancy && (
        <span className={clsx(styles.slotChipCount, styles[occupancy.tone])}>
          {occupancy.label}
        </span>
      )}
      {candidates.length > 0 && (
        <SlotAddMenu slot={slot} onOpenChange={setIsMenuOpen}>
          <button
            type="button"
            aria-label={`Add to ${slotName}`}
            title={`Add to ${slotName}`}
          >
            <PlusIcon />
          </button>
        </SlotAddMenu>
      )}
    </div>
  );
};

export default SlotChip;
