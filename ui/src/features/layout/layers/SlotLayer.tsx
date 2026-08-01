import { useCallback } from 'react';
import { CollapsibleContent } from '@radix-ui/react-collapsible';
import * as Collapsible from '@radix-ui/react-collapsible';
import {
  PlusIcon,
  TriangleDownIcon,
  TriangleRightIcon,
} from '@radix-ui/react-icons';
import { Box, Flex, Text } from '@radix-ui/themes';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import SidebarNode from '@/components/sidePanel/SidebarNode';
import ComponentLayer from '@/features/layout/layers/ComponentLayer';
import LayersDropZone from '@/features/layout/layers/LayersDropZone';
import { slotOccupancy } from '@/features/layout/slot-utils';
import SlotAddMenu from '@/features/layout/SlotAddMenu';
import {
  selectCollapsedLayers,
  setHoveredComponent,
  toggleCollapsedLayer,
  unsetHoveredComponent,
} from '@/features/ui/uiSlice';
import useGetComponentName from '@/hooks/useGetComponentName';
import { useSlotCandidates, useSlotRule } from '@/hooks/useSlotRestrictions';

import type React from 'react';
import type { CollapsibleTriggerProps } from '@radix-ui/react-collapsible';
import type {
  ComponentNode,
  SlotNode,
} from '@/features/layout/layoutModelSlice';

import styles from './SlotLayer.module.css';

interface SlotLayerProps {
  slot: SlotNode;
  children?: false | React.ReactElement<CollapsibleTriggerProps>;
  indent: number;
  parentNode?: ComponentNode;
  disableDrop?: boolean;
}

const SlotLayer: React.FC<SlotLayerProps> = ({
  slot,
  indent,
  parentNode,
  disableDrop = false,
}) => {
  const dispatch = useAppDispatch();
  const slotName = useGetComponentName(slot, parentNode);
  const collapsedLayers = useAppSelector(selectCollapsedLayers);
  const slotId = slot.id;
  const isCollapsed = collapsedLayers.includes(slotId);
  // A governed slot shows its occupancy, so the limit is legible before it is
  // reached. Slots without restrictions show nothing: the presence of a counter
  // is itself the signal that this slot has rules.
  // @see \Drupal\canvas\SlotRestrictions
  const rule = useSlotRule(slot);
  const candidates = useSlotCandidates(slot);
  const occupancy = slotOccupancy(rule, slot.components.length);

  const handleItemMouseEnter = useCallback(
    (event: React.MouseEvent<HTMLDivElement>) => {
      event.stopPropagation();
      dispatch(setHoveredComponent(slotId));
    },
    [dispatch, slotId],
  );

  const handleItemMouseLeave = useCallback(
    (event: React.MouseEvent<HTMLDivElement>) => {
      event.stopPropagation();
      dispatch(unsetHoveredComponent());
    },
    [dispatch],
  );

  const handleOpenChange = () => {
    dispatch(toggleCollapsedLayer(slotId));
  };

  return (
    <Box
      data-canvas-uuid={slotId}
      data-canvas-type={slot.nodeType}
      aria-labelledby={`layer-${slotId}-name`}
      position="relative"
      onClick={(e) => {
        e.stopPropagation();
      }}
    >
      <Collapsible.Root
        className="canvas--collapsible-root"
        open={!isCollapsed}
        onOpenChange={handleOpenChange}
        data-canvas-uuid={slotId}
      >
        <SidebarNode
          id={`layer-${slotId}-name`}
          onMouseEnter={handleItemMouseEnter}
          onMouseLeave={handleItemMouseLeave}
          title={slotName}
          draggable={false}
          variant="slot"
          open={!isCollapsed}
          disabled={disableDrop}
          indent={indent}
          trailingContent={
            occupancy || candidates.length > 0 ? (
              <Flex align="center" gap="1">
                {occupancy && (
                  <Text
                    size="1"
                    color={occupancy.tone === 'under' ? 'orange' : 'gray'}
                    weight={occupancy.tone === 'muted' ? 'regular' : 'medium'}
                  >
                    {occupancy.label}
                  </Text>
                )}
                {/* The keyboard-reachable way to fill a restricted slot, and
                    the only one that cannot offer something that does not fit.
                    @see \Drupal\canvas\SlotRestrictions */}
                {candidates.length > 0 && (
                  <SlotAddMenu slot={slot}>
                    <button
                      className={styles.slotAddButton}
                      aria-label={`Add to ${slotName}`}
                      title={`Add to ${slotName}`}
                      onClick={(e) => e.stopPropagation()}
                    >
                      <PlusIcon />
                    </button>
                  </SlotAddMenu>
                )}
              </Flex>
            ) : undefined
          }
          leadingContent={
            <Flex>
              <Box width="var(--space-4)" mr="1">
                {slot.components.length > 0 ? (
                  <Box>
                    <Collapsible.Trigger
                      asChild={true}
                      onClick={(e) => {
                        e.stopPropagation();
                      }}
                    >
                      <button
                        aria-label={
                          isCollapsed ? `Expand slot` : `Collapse slot`
                        }
                      >
                        {isCollapsed ? (
                          <TriangleRightIcon />
                        ) : (
                          <TriangleDownIcon />
                        )}
                      </button>
                    </Collapsible.Trigger>
                  </Box>
                ) : (
                  <Box />
                )}
              </Box>
            </Flex>
          }
        />

        {slot.components.length > 0 && (
          <CollapsibleContent role="tree">
            {slot.components.map((component, index) => (
              <ComponentLayer
                key={component.uuid}
                index={index}
                component={component}
                indent={indent + 1}
                parentNode={slot}
                disableDrop={disableDrop}
              />
            ))}
          </CollapsibleContent>
        )}
        {!slot.components.length && !disableDrop && (
          <LayersDropZone
            layer={slot}
            position={'bottom'}
            indent={indent + 1}
          />
        )}
      </Collapsible.Root>
    </Box>
  );
};

export default SlotLayer;
