import type React from 'react';
import { useCallback } from 'react';
import { Flex, Box } from '@radix-ui/themes';
import SidebarNode from '@/components/sidePanel/SidebarNode';
import { TriangleDownIcon, TriangleRightIcon } from '@radix-ui/react-icons';

import type {
  ComponentNode,
  SlotNode,
} from '@/features/layout/layoutModelSlice';
import useGetComponentName from '@/hooks/useGetComponentName';
import type { CollapsibleTriggerProps } from '@radix-ui/react-collapsible';
import { CollapsibleContent } from '@radix-ui/react-collapsible';
import * as Collapsible from '@radix-ui/react-collapsible';
import ComponentLayer from '@/features/layout/layers/ComponentLayer';
import {
  selectCollapsedLayers,
  setHoveredComponent,
  toggleCollapsedLayer,
  unsetHoveredComponent,
} from '@/features/ui/uiSlice';
import { useAppDispatch, useAppSelector } from '@/app/hooks';
import LayersDropZone from '@/features/layout/layers/LayersDropZone';

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
      data-xb-uuid={slotId}
      data-xb-type={slot.nodeType}
      aria-labelledby={`layer-${slotId}-name`}
      position="relative"
      onClick={(e) => {
        e.stopPropagation();
      }}
    >
      <Collapsible.Root
        className="xb--collapsible-root"
        open={!isCollapsed}
        onOpenChange={handleOpenChange}
        data-xb-uuid={slotId}
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
          leadingContent={
            <Flex>
              <Box
                width={`calc(${indent} * var(--space-2))`}
                className="xb-layer-indent"
              />
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
