import { useCallback } from 'react';
import { CollapsibleContent } from '@radix-ui/react-collapsible';
import * as Collapsible from '@radix-ui/react-collapsible';
import {
  CheckboxIcon,
  TriangleDownIcon,
  TriangleRightIcon,
} from '@radix-ui/react-icons';
import { Box, ContextMenu, Flex } from '@radix-ui/themes';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import SidebarNode from '@/components/sidePanel/SidebarNode';
import { UnifiedMenu } from '@/components/UnifiedMenu';
import {
  filterNonMarkerComponents,
  findExposedSlotEntry,
  isExposedSlotTarget,
} from '@/features/layout/exposedSlots';
import ComponentLayer from '@/features/layout/layers/ComponentLayer';
import LayersDropZone from '@/features/layout/layers/LayersDropZone';
import {
  revertSlotOverride,
  selectExposedSlots,
  selectIsPerContentMode,
  selectSlotOverrides,
} from '@/features/layout/layoutModelSlice';
import { SlotContextMenuContent } from '@/features/layout/preview/SlotContextMenu';
import {
  EditorFrameContext,
  selectCollapsedLayers,
  selectEditorFrameContext,
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
  // Per-content editing: true once an ancestor exposed slot has been entered, so
  // this subtree renders normally instead of being hidden as template chrome.
  insideExposedSlot?: boolean;
}

const SlotLayer: React.FC<SlotLayerProps> = ({
  slot,
  indent,
  parentNode,
  disableDrop = false,
  insideExposedSlot = false,
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
  const perContentMode = useAppSelector(selectIsPerContentMode);
  const slotOverrides = useAppSelector(selectSlotOverrides);
  const exposed =
    isTemplateContext && parentNode
      ? findExposedSlotEntry(exposedSlots, parentNode.uuid, slot.name)
      : null;
  const displayName = exposed
    ? exposed.definition.disabled
      ? `${exposed.definition.label} (disabled)`
      : exposed.definition.label
    : slotName;

  // Per-content editing: hide empty-override markers, and only let active
  // exposed slots accept drops (overriding inherited disableDrop from locked
  // chrome). Elsewhere the inherited disableDrop is respected.
  const isPerContentExposed =
    perContentMode && isExposedSlotTarget(slot, exposedSlots);
  // Per-content editing: this exposed slot's alias + override state, so its
  // layer row can offer "Revert to default" (matching the canvas overlay).
  const perContentEntry =
    perContentMode && parentNode
      ? findExposedSlotEntry(exposedSlots, parentNode.uuid, slot.name)
      : null;
  const perContentAlias = perContentEntry?.alias;
  const isOverridden = perContentAlias
    ? !!slotOverrides?.[perContentAlias]?.overridden
    : false;
  const visibleComponents = perContentMode
    ? filterNonMarkerComponents(slot.components)
    : slot.components;
  const layerDropDisabled = perContentMode ? !isPerContentExposed : disableDrop;

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

  // Per-content editing: a slot that is not an active exposed slot (and not
  // already inside one) is template chrome. Hide its row and render only its
  // components (promoted to this indent) so the surrounding template structure
  // does not appear in the layers tree; only exposed slots and their content do.
  if (perContentMode && !isPerContentExposed && !insideExposedSlot) {
    return (
      <>
        {visibleComponents.map((component, index) => (
          <ComponentLayer
            key={component.uuid}
            index={index}
            component={component}
            indent={indent}
            parentNode={slot}
            // Propagate the incoming drop state, NOT this non-exposed slot's own
            // layerDropDisabled: its row isn't rendered, so its drop-gating must
            // not leak down and disable the exposed slot this promotes toward.
            disableDrop={disableDrop}
            insideExposedSlot={false}
          />
        ))}
      </>
    );
  }

  // Per-content editing: an overridden exposed slot can be reverted to the
  // template default, offered both from the row's "..." menu and on right-click.
  const perContentRevertContent =
    perContentMode && isPerContentExposed && isOverridden && !!perContentAlias;
  const revertMenuItems = perContentRevertContent ? (
    <>
      <UnifiedMenu.Label>
        {perContentEntry?.definition.label ?? slotName}
      </UnifiedMenu.Label>
      <UnifiedMenu.Separator />
      <UnifiedMenu.Item
        onClick={() =>
          perContentAlias && dispatch(revertSlotOverride(perContentAlias))
        }
        data-testid={`slot-layer-revert-${slotId}`}
      >
        Revert to default
      </UnifiedMenu.Item>
    </>
  ) : null;

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
        open={!isCollapsed}
        // An active exposed slot is the editable target in per-content mode;
        // it must never render disabled (which would grey it and block its
        // contextual "Revert to default" menu via pointer-events: none).
        disabled={isPerContentExposed ? false : disableDrop}
        indent={indent}
        trailingContent={
          exposed ? (
            <Flex
              align="center"
              data-testid={`slot-layer-exposed-marker-${slotId}`}
              aria-label="Exposed slot"
            >
              <CheckboxIcon />
            </Flex>
          ) : undefined
        }
        dropdownMenuContent={
          isTemplateContext && parentNode ? (
            <SlotContextMenuContent
              slot={slot}
              parentComponent={parentNode}
              menuType="dropdown"
            />
          ) : revertMenuItems ? (
            <UnifiedMenu.Content menuType="dropdown">
              {revertMenuItems}
            </UnifiedMenu.Content>
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
              insideExposedSlot={insideExposedSlot || isPerContentExposed}
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
      aria-labelledby={`layer-${slotId}-name`}
      position="relative"
      onClick={(e) => {
        e.stopPropagation();
      }}
    >
      {revertMenuItems ? (
        <ContextMenu.Root>
          <ContextMenu.Trigger>{slotRow}</ContextMenu.Trigger>
          <UnifiedMenu.Content menuType="context" align="start" side="right">
            {revertMenuItems}
          </UnifiedMenu.Content>
        </ContextMenu.Root>
      ) : (
        slotRow
      )}
    </Box>
  );
};

export default SlotLayer;
