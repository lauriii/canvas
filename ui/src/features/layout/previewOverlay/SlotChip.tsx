import clsx from 'clsx';
import { useDndContext } from '@dnd-kit/core';
import { PlusIcon } from '@radix-ui/react-icons';

import { slotOccupancy } from '@/features/layout/slot-utils';
import SlotAddMenu from '@/features/layout/SlotAddMenu';
import { useSlotCandidates, useSlotRule } from '@/hooks/useSlotRestrictions';

import type React from 'react';
import type { SlotNode } from '@/features/layout/layoutModelSlice';

import styles from '@/features/layout/previewOverlay/PreviewOverlay.module.css';

export interface SlotChipProps {
  slot: SlotNode;
  slotName: string;
}

/**
 * What a governed slot holds, and the way to add to it, on the canvas.
 *
 * Sits on the slot itself rather than inside its empty state, because a slot
 * that already holds something is exactly where an author needs to see the
 * limit and reach for one more. Nothing renders for a slot that governs
 * neither its contents nor its count, so the chip's presence is itself the
 * signal that the slot has rules.
 *
 * @see \Drupal\canvas\SlotRestrictions
 */
const SlotChip: React.FC<SlotChipProps> = ({ slot, slotName }) => {
  const rule = useSlotRule(slot);
  const candidates = useSlotCandidates(slot);
  // The chip is the one interactive hole in a pointer-transparent overlay, so
  // it has to get out of the way of a drag rather than swallow the drop.
  const { active: dragInFlight } = useDndContext();
  const occupancy = slotOccupancy(rule, slot.components.length);

  if (dragInFlight || (occupancy === null && candidates.length === 0)) {
    return null;
  }

  return (
    <div className={styles.slotChip}>
      {occupancy && (
        <span className={clsx(styles.slotChipCount, styles[occupancy.tone])}>
          {occupancy.label}
        </span>
      )}
      {candidates.length > 0 && (
        <SlotAddMenu slot={slot}>
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
