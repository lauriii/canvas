import type React from 'react';
import { useState, useCallback } from 'react';
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
  setHoveredComponent,
  unsetHoveredComponent,
} from '@/features/ui/uiSlice';
import { useAppDispatch } from '@/app/hooks';
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
  const [open, setOpen] = useState(false);
  const slotId = slot.id;

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

  return (
    <Box
      data-xb-uuid={slotId}
      data-xb-type={slot.nodeType}
      aria-labelledby={`layer-${slotId}-name`}
      position="relative"
    >
      <Collapsible.Root
        className="xb--collapsible-root"
        open={open}
        onOpenChange={setOpen}
        data-xb-uuid={slotId}
      >
        <SidebarNode
          id={`layer-${slotId}-name`}
          onMouseEnter={handleItemMouseEnter}
          onMouseLeave={handleItemMouseLeave}
          title={slotName}
          draggable={false}
          variant="slot"
          open={open}
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
                    <Collapsible.Trigger asChild={true}>
                      <button
                        aria-label={open ? `Collapse slot` : `Expand slot`}
                      >
                        {open ? <TriangleDownIcon /> : <TriangleRightIcon />}
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
          <CollapsibleContent>
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
