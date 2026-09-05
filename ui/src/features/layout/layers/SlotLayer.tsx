import { useCallback } from 'react';
import { CollapsibleContent } from '@radix-ui/react-collapsible';
import * as Collapsible from '@radix-ui/react-collapsible';
import {
  BoxIcon,
  TriangleDownIcon,
  TriangleRightIcon,
} from '@radix-ui/react-icons';
import { Box, ContextMenu, Flex } from '@radix-ui/themes';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import SidebarNode from '@/components/sidePanel/SidebarNode';
import { findExposedSlotEntry } from '@/features/layout/exposedSlots';
import ComponentLayer from '@/features/layout/layers/ComponentLayer';
import LayersDropZone from '@/features/layout/layers/LayersDropZone';
import { selectExposedSlots } from '@/features/layout/layoutModelSlice';
import { SlotContextMenuContent } from '@/features/layout/preview/SlotContextMenu';
import {
  EditorFrameContext,
  selectCollapsedLayers,
  selectEditorFrameContext,
  selectSelectedComponentUuid,
  setHoveredComponent,
  toggleCollapsedLayer,
  unsetHoveredComponent,
} from '@/features/ui/uiSlice';
import useComponentSelection from '@/hooks/useComponentSelection';
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

  // Template editor: relabel + mark exposed slots and expose the slot menu.
  const editorFrameContext = useAppSelector(selectEditorFrameContext);
  const isTemplateContext = editorFrameContext === EditorFrameContext.TEMPLATE;
  const exposedSlots = useAppSelector(selectExposedSlots);
  const exposed =
    isTemplateContext && parentNode
      ? findExposedSlotEntry(exposedSlots, parentNode.uuid, slot.name)
      : null;
  const displayName = exposed ? exposed.definition.label : slotName;

  const selectedComponent = useAppSelector(selectSelectedComponentUuid);
  const isSlotSelected = slotId === selectedComponent;
  const { setNonRoutedSelection } = useComponentSelection();
  const visibleComponents = slot.components;
  const layerDropDisabled = disableDrop;

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

  const slotRow = (
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
        title={displayName}
        draggable={false}
        variant="slot"
        selected={isSlotSelected}
        open={!isCollapsed}
        // Template editor: an exposed slot uses the exposed-slot icon in place
        // of the generic slot icon, so exposed slots read at a glance.
        icon={
          exposed ? (
            <Flex
              align="center"
              data-testid={`slot-layer-exposed-marker-${slotId}`}
              aria-label="Exposed slot"
            >
              <BoxIcon />
            </Flex>
          ) : undefined
        }
        disabled={disableDrop}
        indent={indent}
        dropdownMenuContent={
          isTemplateContext && parentNode ? (
            <SlotContextMenuContent
              slot={slot}
              parentComponent={parentNode}
              menuType="dropdown"
            />
          ) : undefined
        }
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
                      aria-label={isCollapsed ? `Expand slot` : `Collapse slot`}
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
        <CollapsibleContent role="tree">
          {visibleComponents.map((component, index) => (
            <ComponentLayer
              key={component.uuid}
              index={index}
              component={component}
              indent={indent + 1}
              parentNode={slot}
              disableDrop={layerDropDisabled}
            />
          ))}
        </CollapsibleContent>
      )}
      {!visibleComponents.length && !layerDropDisabled && (
        <LayersDropZone layer={slot} position={'bottom'} indent={indent + 1} />
      )}
    </Collapsible.Root>
  );

  return (
    <Box
      data-canvas-uuid={slotId}
      data-canvas-type={slot.nodeType}
      data-canvas-selected={isSlotSelected || undefined}
      aria-labelledby={`layer-${slotId}-name`}
      position="relative"
      onClick={(e) => {
        e.stopPropagation();
        // Template editor: any slot row is selectable, so its contextual panel
        // offers to expose it (or shows usage once exposed). Slot ids contain a
        // slash, so the selection is held in redux only
        // (@see setNonRoutedSelection).
        if (isTemplateContext) {
          setNonRoutedSelection(slotId);
        }
      }}
    >
      {isTemplateContext && parentNode ? (
        // Template editor: right-click offers the same slot menu as the canvas
        // overlay and the row's "..." button (Expose slot, or Edit label /
        // Detach once exposed).
        <ContextMenu.Root>
          <ContextMenu.Trigger>{slotRow}</ContextMenu.Trigger>
          <SlotContextMenuContent
            slot={slot}
            parentComponent={parentNode}
            menuType="context"
          />
        </ContextMenu.Root>
      ) : (
        slotRow
      )}
    </Box>
  );
};

export default SlotLayer;
