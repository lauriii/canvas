import { DropdownMenu, Text } from '@radix-ui/themes';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import UnifiedMenu from '@/components/UnifiedMenu';
import {
  _addNewComponentToLayout,
  addNewPatternToLayout,
  selectLayout,
} from '@/features/layout/layoutModelSlice';
import { findNodePathByUuid } from '@/features/layout/layoutUtils';
import { componentIdFromNodeType } from '@/features/layout/slot-utils';
import useComponentSelection from '@/hooks/useComponentSelection';
import {
  useSlotCandidates,
  useSlotRejection,
  useSlotRule,
  useSlotTitle,
} from '@/hooks/useSlotRestrictions';
import { useGetComponentsQuery } from '@/services/componentAndLayout';
import { useGetPatternsQuery } from '@/services/patterns';

import type React from 'react';
import type { SlotNode } from '@/features/layout/layoutModelSlice';
import type { CanvasComponent } from '@/types/Component';
import type { Pattern } from '@/types/Pattern';

import styles from './SlotAddMenu.module.css';

/**
 * Adds a component to a restricted slot, offering only what the slot accepts.
 *
 * The menu is the accessible counterpart to dragging: it is reachable by
 * keyboard, it needs no pointer precision, and because it lists nothing that
 * does not fit there is no refusal to explain. A slot that restricts nothing
 * has no menu — the component library already offers everything, and the
 * presence of this affordance is itself a signal that the slot is governed.
 *
 * @see \Drupal\canvas\SlotRestrictions
 */

/**
 * States the slot's cardinality in plain language, always, not only when it is
 * nearly exhausted.
 */
const describeCapacity = (
  occupancy: number,
  maxItems: number | null,
  minItems: number | null,
): string | null => {
  if (maxItems !== null) {
    return `${occupancy} of ${maxItems} used`;
  }
  if (minItems !== null && occupancy < minItems) {
    return `${occupancy} used, needs at least ${minItems}`;
  }
  return null;
};

export interface SlotAddMenuProps {
  slot: SlotNode;
  /** The element that opens the menu. */
  children: React.ReactNode;
}

const SlotAddMenu: React.FC<SlotAddMenuProps> = ({ slot, children }) => {
  const dispatch = useAppDispatch();
  const layout = useAppSelector(selectLayout);
  const { setSelectedComponent } = useComponentSelection();
  const { data: components } = useGetComponentsQuery();
  const { data: patterns } = useGetPatternsQuery();
  const groups = useSlotCandidates(slot);
  const rule = useSlotRule(slot);
  const slotTitle = useSlotTitle(slot);
  const slotRejection = useSlotRejection();

  // Appending is the only insertion this menu offers; ordering afterwards is
  // what drag and the Layers panel are for.
  const insertionPath = () => {
    const path = findNodePathByUuid(layout, slot.id);
    return path ? [...path, slot.components.length] : null;
  };

  const addComponent = (component: CanvasComponent) => {
    const to = insertionPath();
    if (to) {
      dispatch(
        _addNewComponentToLayout({ to, component }, setSelectedComponent),
      );
    }
  };

  const addPattern = (pattern: Pattern) => {
    const to = insertionPath();
    if (to) {
      dispatch(
        addNewPatternToLayout(
          { to, layoutModel: pattern.layoutModel },
          setSelectedComponent,
        ),
      );
    }
  };

  // A pattern fits when every component it would place fits, which is the same
  // rule a dragged pattern is held to.
  const fittingPatterns = Object.values(patterns ?? {}).filter((pattern) => {
    const rootIds = (pattern.layoutModel?.layout ?? []).map((node) =>
      componentIdFromNodeType(node.type),
    );
    return rootIds.length > 0 && slotRejection(slot, rootIds) === null;
  });

  const isFull =
    rule.maxItems !== null && slot.components.length >= rule.maxItems;
  const capacity = describeCapacity(
    slot.components.length,
    rule.maxItems,
    rule.minItems,
  );

  return (
    <DropdownMenu.Root>
      <DropdownMenu.Trigger>{children}</DropdownMenu.Trigger>
      <UnifiedMenu.Content
        menuType="dropdown"
        className={styles.slotAddMenu}
        size="1"
      >
        <UnifiedMenu.Label>Add to {slotTitle}</UnifiedMenu.Label>
        {isFull ? (
          // Never a dead end: say what would have to change, rather than
          // presenting an empty menu.
          <Text as="p" className={styles.slotAddMenuNote}>
            {slotTitle} is full ({slot.components.length} of {rule.maxItems}).
            Remove something to add more.
          </Text>
        ) : (
          <>
            {groups.map((group) => (
              <UnifiedMenu.Group key={group.label}>
                <UnifiedMenu.Label>{group.label}</UnifiedMenu.Label>
                {group.componentIds.map((componentId) => (
                  <UnifiedMenu.Item
                    key={componentId}
                    onSelect={() => addComponent(components![componentId])}
                  >
                    {components![componentId].name}
                  </UnifiedMenu.Item>
                ))}
              </UnifiedMenu.Group>
            ))}
            {fittingPatterns.length > 0 && (
              <UnifiedMenu.Group>
                <UnifiedMenu.Label>Patterns that fit</UnifiedMenu.Label>
                {fittingPatterns.map((pattern) => (
                  <UnifiedMenu.Item
                    key={pattern.id}
                    onSelect={() => addPattern(pattern)}
                  >
                    {pattern.name}
                  </UnifiedMenu.Item>
                ))}
              </UnifiedMenu.Group>
            )}
          </>
        )}
        {capacity && (
          <>
            <UnifiedMenu.Separator />
            <Text as="p" className={styles.slotAddMenuNote}>
              {capacity}
            </Text>
          </>
        )}
      </UnifiedMenu.Content>
    </DropdownMenu.Root>
  );
};

export default SlotAddMenu;
