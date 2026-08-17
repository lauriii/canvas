import clsx from 'clsx';
import { PlusIcon } from '@radix-ui/react-icons';

import { slotOccupancy } from '@/features/layout/slot-utils';
import SlotAddMenu from '@/features/layout/SlotAddMenu';
import {
  useSlotCandidates,
  useSlotRule,
  useSlotTitle,
} from '@/hooks/useSlotRestrictions';

import type React from 'react';
import type { SlotNode } from '@/features/layout/layoutModelSlice';

import styles from '@/features/layout/previewOverlay/PreviewOverlay.module.css';

export interface SlotAddControlsProps {
  slot: SlotNode;
  onMenuOpenChange: (open: boolean) => void;
}

/**
 * How full a governed slot is, and the way to add to it, inside its name tag.
 *
 * Rides on whichever name tag is showing rather than being a badge of its own:
 * Canvas shows one piece of canvas chrome at a time, and a second floating
 * badge both broke that rule and collided with the tag whenever both were up.
 *
 * On a slot's tag it describes that slot. On a component's tag it describes the
 * slot that component sits in, which is what makes it reachable at all for a
 * slot whose children fill it edge to edge — and reads naturally besides, since
 * pointing at a card and asking for one more is the whole gesture.
 *
 * @see \Drupal\canvas\SlotRestrictions
 */
const SlotAddControls: React.FC<SlotAddControlsProps> = ({
  slot,
  onMenuOpenChange,
}) => {
  const rule = useSlotRule(slot);
  const slotName = useSlotTitle(slot);
  const candidates = useSlotCandidates(slot);
  const occupancy = slotOccupancy(rule, slot.components.length);

  if (occupancy === null && candidates.length === 0) {
    return null;
  }

  return (
    <>
      {occupancy && (
        <span className={clsx(styles.slotTagCount, styles[occupancy.tone])}>
          {occupancy.label}
        </span>
      )}
      {candidates.length > 0 && (
        <SlotAddMenu slot={slot} onOpenChange={onMenuOpenChange}>
          <button
            type="button"
            className={styles.slotTagAdd}
            aria-label={`Add to ${slotName}`}
            title={`Add to ${slotName}`}
            // The tag lives inside a draggable component overlay, whose pointer
            // sensor would otherwise claim this press as the start of a drag
            // and the menu would never open. Radix still sees the event: it
            // composes its own handler with this one on the same element.
            onPointerDown={(event) => event.stopPropagation()}
            onMouseDown={(event) => event.stopPropagation()}
            onClick={(event) => event.stopPropagation()}
          >
            <PlusIcon />
          </button>
        </SlotAddMenu>
      )}
    </>
  );
};

export default SlotAddControls;
