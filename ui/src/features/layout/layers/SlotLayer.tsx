import type React from 'react';
import { useState } from 'react';
import { Flex, Box } from '@radix-ui/themes';
import SidebarNode from '@/components/sidebar/SidebarNode';
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
import SortableContainer from '@/features/layout/layers/SortableContainer';

interface SlotLayerProps {
  slot: SlotNode;
  children?: false | React.ReactElement<CollapsibleTriggerProps>;
  indent: number;
  parentNode?: ComponentNode;
}

const SlotLayer: React.FC<SlotLayerProps> = ({ slot, indent, parentNode }) => {
  const dispatch = useAppDispatch();
  const slotName = useGetComponentName(slot, parentNode);
  const [open, setOpen] = useState(false);
  const slotId = slot.id;

  function handleItemMouseEnter(event: React.MouseEvent<HTMLDivElement>) {
    event.stopPropagation();
    dispatch(setHoveredComponent(slotId));
  }

  function handleItemMouseLeave(event: React.MouseEvent<HTMLDivElement>) {
    event.stopPropagation();
    dispatch(unsetHoveredComponent());
  }

  return (
    <Box
      data-xb-uuid={slotId}
      data-xb-type={slot.nodeType}
      aria-labelledby={`layer-${slotId}-name`}
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
          variant="slot"
          open={open}
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

        {slot.components.length > 0 ? (
          <CollapsibleContent>
            <SortableContainer slotId={slotId} indent={indent + 1}>
              {slot.components.map((component) => (
                <ComponentLayer
                  key={component.uuid}
                  component={component}
                  indent={indent + 1}
                  parentNode={slot}
                />
              ))}
            </SortableContainer>
          </CollapsibleContent>
        ) : (
          <SortableContainer slotId={slotId} indent={indent + 1} />
        )}
      </Collapsible.Root>
    </Box>
  );
};

export default SlotLayer;
