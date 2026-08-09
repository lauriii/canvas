import { useCallback } from 'react';
import { CollapsibleContent } from '@radix-ui/react-collapsible';
import * as Collapsible from '@radix-ui/react-collapsible';
import { TriangleDownIcon, TriangleRightIcon } from '@radix-ui/react-icons';
import { Box, Flex } from '@radix-ui/themes';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import SidebarNode from '@/components/sidePanel/SidebarNode';
import ComponentLayer from '@/features/layout/layers/ComponentLayer';
import LayersDropZone from '@/features/layout/layers/LayersDropZone';
import { selectModel } from '@/features/layout/layoutModelSlice';
import { filterSlotComponentsForPreview } from '@/features/layout/personalizationUtils';
import {
  selectCollapsedLayers,
  selectPreviewedVariants,
  setHoveredComponent,
  toggleCollapsedLayer,
  unsetHoveredComponent,
} from '@/features/ui/uiSlice';
import useGetComponentName from '@/hooks/useGetComponentName';

import type React from 'react';
import type { CollapsibleTriggerProps } from '@radix-ui/react-collapsible';
import type {
  ComponentNode,
  SlotNode,
} from '@/features/layout/layoutModelSlice';

interface SlotLayerProps {
  slot: SlotNode;
  children?: false | React.ReactElement<CollapsibleTriggerProps>;
  indent: number;
  parentNode?: ComponentNode;
  disableDrop?: boolean;
  /**
   * Renders only the slot's children and drop zones, without the slot's own
   * row. Used to collapse personalization plumbing in the layer tree while
   * keeping this slot as the structural parent, so drop paths are unchanged.
   */
  hideRow?: boolean;
}

const SlotLayer: React.FC<SlotLayerProps> = ({
  slot,
  indent,
  parentNode,
  disableDrop = false,
  hideRow = false,
}) => {
  const dispatch = useAppDispatch();
  const slotName = useGetComponentName(slot, parentNode);
  const collapsedLayers = useAppSelector(selectCollapsedLayers);
  const model = useAppSelector(selectModel);
  const previewedVariants = useAppSelector(selectPreviewedVariants);
  const slotId = slot.id;
  const isCollapsed = collapsedLayers.includes(slotId);
  // Inside a personalization switch, only the previewed variant's case is
  // shown in the layer tree.
  const visibleComponents = filterSlotComponentsForPreview(
    slot,
    parentNode,
    model,
    previewedVariants,
  );

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

  // Map over all components so hidden variants keep the original
  // indices used to build drop paths.
  const childLayers = slot.components.map((component, index) =>
    visibleComponents.includes(component) ? (
      <ComponentLayer
        key={component.uuid}
        index={index}
        component={component}
        indent={indent + 1}
        parentNode={slot}
        disableDrop={disableDrop}
      />
    ) : null,
  );

  const emptySlotDropZone = !visibleComponents.length && !disableDrop && (
    <LayersDropZone layer={slot} position={'bottom'} indent={indent + 1} />
  );

  if (hideRow) {
    // Without a visible row there is no collapse trigger, so the children
    // render unconditionally instead of inside a collapsible.
    return (
      <Box
        data-canvas-uuid={slotId}
        data-canvas-type={slot.nodeType}
        position="relative"
        role="tree"
        onClick={(e) => {
          e.stopPropagation();
        }}
      >
        {childLayers}
        {emptySlotDropZone}
      </Box>
    );
  }

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
          leadingContent={
            <Flex>
              <Box width="var(--space-4)" mr="1">
                {visibleComponents.length > 0 ? (
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

        {visibleComponents.length > 0 && (
          <CollapsibleContent role="tree">{childLayers}</CollapsibleContent>
        )}
        {emptySlotDropZone}
      </Collapsible.Root>
    </Box>
  );
};

export default SlotLayer;
