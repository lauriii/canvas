import { useEffect, useState } from 'react';
import clsx from 'clsx';
import { kebabCase } from 'lodash';
import { useDndContext, useDroppable } from '@dnd-kit/core';
import { BoxModelIcon } from '@radix-ui/react-icons';

import { useAppSelector } from '@/app/hooks';
import { selectLayout } from '@/features/layout/layoutModelSlice';
import { findNodePathByUuid } from '@/features/layout/layoutUtils';
import { describeAllowed } from '@/features/layout/slot-utils';
import SlotAddMenu from '@/features/layout/SlotAddMenu';
import useGetComponentName from '@/hooks/useGetComponentName';
import {
  useDropRejection,
  useSlotCandidates,
  useSlotRule,
} from '@/hooks/useSlotRestrictions';
import { useGetComponentsQuery } from '@/services/componentAndLayout';

import type React from 'react';
import type {
  ComponentNode,
  SlotNode,
} from '@/features/layout/layoutModelSlice';

import styles from '@/features/layout/previewOverlay/PreviewOverlay.module.css';

export interface EmptySlotDropZoneProps {
  slot: SlotNode;
  slotName: string;
  parentComponent: ComponentNode;
}
const EmptySlotDropZone: React.FC<EmptySlotDropZoneProps> = (props) => {
  const { slot, slotName, parentComponent } = props;
  const layout = useAppSelector(selectLayout);
  const [activeName, setActiveName] = useState('');
  const [activeOrigin, setActiveOrigin] = useState('');
  const parentComponentName = useGetComponentName(parentComponent);

  const slotPath = findNodePathByUuid(layout, slot.id);
  if (!slotPath) {
    throw new Error(`Unable to ascertain 'path' to component ${slot.id}`);
  }
  // We want to drop into the first (0th) space in the empty slot.
  slotPath.push(0);

  const accepts = ['overlay', 'library'];
  // The slot's own metadata decides what it accepts, on top of where the drag
  // came from.
  // @see \Drupal\canvas\SlotRestrictions
  const rejection = useDropRejection(slot);
  const rule = useSlotRule(slot);
  const candidates = useSlotCandidates(slot);
  // The button is a hole in an otherwise pointer-transparent overlay, so it
  // must disappear while a drag is in flight rather than swallow the drop.
  const { active: dragInFlight } = useDndContext();
  const { data: components } = useGetComponentsQuery();

  const {
    setNodeRef: setDropRef,
    isOver,
    active,
  } = useDroppable({
    id: `${slot.id}`,
    // Registered even when it refuses, so the drag pill can say why.
    // @see \Drupal\canvas\SlotRestrictions
    disabled: !accepts.includes(activeOrigin),
    data: {
      rejection,
      component: parentComponent,
      parentSlot: slot,
      path: slotPath,
      accepts,
    },
  });

  useEffect(() => {
    // A refusing slot keeps advertising what it accepts rather than previewing
    // a component it will not take.
    if (isOver && active && rejection === null) {
      setActiveName(active.data?.current?.name);
    } else {
      setActiveName('');
    }
  }, [active, isOver, rejection]);

  useEffect(() => {
    if (active) {
      setActiveOrigin(active.data?.current?.origin);
    } else {
      setActiveOrigin('');
    }
  }, [active]);

  return (
    <div className={styles.emptySlotContainer} data-testid="canvas-empty-slot">
      <div
        className={clsx(styles.emptySlotDropZone, {
          [styles.isOver]: isOver && rejection === null,
        })}
        data-testid={`canvas-empty-slot-drop-zone-${kebabCase(parentComponentName)}:${kebabCase(slotName)}`}
        ref={setDropRef}
      >
        {activeName ? (
          activeName
        ) : (
          <>
            <BoxModelIcon />
            <div>{slotName}</div>
            {/* An empty restricted slot says what belongs in it, so that an
                author learns the rule before it is enforced. */}
            {rule.allowed !== null && (
              <div className={styles.emptySlotAccepts}>
                Accepts {describeAllowed(rule, components)}
              </div>
            )}
            {rule.maxItems !== null && (
              <div className={styles.emptySlotCount}>
                0 of {rule.maxItems}
                {rule.minItems ? `, at least ${rule.minItems}` : ''}
              </div>
            )}
            {/* Filling a restricted slot without dragging into it.
                @see \Drupal\canvas\SlotRestrictions */}
            {candidates.length > 0 && !dragInFlight && (
              <div className={styles.emptySlotAdd}>
                <SlotAddMenu slot={slot}>
                  <button type="button" aria-label={`Add to ${slotName}`}>
                    + Add
                  </button>
                </SlotAddMenu>
              </div>
            )}
          </>
        )}
      </div>
    </div>
  );
};

export default EmptySlotDropZone;
