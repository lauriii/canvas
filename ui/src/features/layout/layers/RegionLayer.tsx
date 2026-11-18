import { useCallback } from 'react';
import { useParams } from 'react-router';
import { ExternalLinkIcon } from '@radix-ui/react-icons';
import { Badge, Box, ContextMenu, Flex } from '@radix-ui/themes';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import { useIndentContext } from '@/components/sidePanel/ListIndentContext';
import SidebarNode from '@/components/sidePanel/SidebarNode';
import { UnifiedMenu } from '@/components/UnifiedMenu';
import {
  filterNonMarkerComponents,
  getSlotRegionState,
} from '@/features/layout/exposedSlots';
import ComponentLayer from '@/features/layout/layers/ComponentLayer';
import {
  selectExposedSlots,
  selectIsPerContentMode,
  selectSlotDefaults,
  selectSlotOverrides,
} from '@/features/layout/layoutModelSlice';
import SlotOverrideMenuItems from '@/features/layout/preview/SlotOverrideMenuItems';
import { buildContentEditActions } from '@/features/navigator/templatedContent';
import {
  EditorFrameContext,
  selectEditorFrameContext,
  selectIsComponentHovered,
  selectIsMultiSelect,
  selectSelectedComponentUuid,
  setHoveredComponent,
  unsetHoveredComponent,
} from '@/features/ui/uiSlice';
import useComponentSelection from '@/hooks/useComponentSelection';
import useEditorNavigation from '@/hooks/useEditorNavigation';
import { useGetPreviewContentEntitiesQuery } from '@/services/componentAndLayout';
import { PAGE_VARIANT_ENTITY_TYPE } from '@/services/pageVariants';

import type React from 'react';
import type { SlotRegionState } from '@/features/layout/exposedSlots';
import type { RegionNode } from '@/features/layout/layoutModelSlice';

const SLOT_STATE_BADGES: Record<
  SlotRegionState,
  { label: string; title: string; color: 'gray' | 'blue' }
> = {
  inherited: {
    label: 'Inherited',
    title: 'Shows the template default. Override it to change it here.',
    color: 'gray',
  },
  overridden: {
    label: 'Overridden',
    title: 'Shows content for this item instead of the template default.',
    color: 'blue',
  },
  empty: {
    label: 'Empty',
    title:
      'The template default is empty. Drop components here to fill this slot.',
    color: 'gray',
  },
};

const RegionLayer: React.FC<{ region: RegionNode }> = ({ region }) => {
  const dispatch = useAppDispatch();
  const isHovered = useAppSelector((state) => {
    return selectIsComponentHovered(state, region.id);
  });
  const selectedComponent = useAppSelector(selectSelectedComponentUuid);
  const isMultiSelect = useAppSelector(selectIsMultiSelect);
  const { unsetSelectedComponent } = useComponentSelection();
  const editorFrameContext = useAppSelector(selectEditorFrameContext);
  const { entityType, bundle, previewEntityId } = useParams();
  const { navigateToEditor } = useEditorNavigation();
  // Templates and page variants have no entity form, so the region row is
  // never the "selected" target there (the panel hides when nothing is
  // selected).
  const hasEntityForm =
    editorFrameContext === EditorFrameContext.ENTITY &&
    entityType !== PAGE_VARIANT_ENTITY_TYPE;

  // Per-content editing: an exposed slot presents as a top-level slot region.
  // An inherited one (template default present, not overridden) is a single
  // non-expandable row offering Override; an overridden one offers Revert.
  const isPerContentMode = useAppSelector(selectIsPerContentMode);
  const exposedSlots = useAppSelector(selectExposedSlots);
  const slotOverrides = useAppSelector(selectSlotOverrides);
  const slotDefaults = useAppSelector(selectSlotDefaults);
  const slotDefinition = isPerContentMode
    ? exposedSlots?.[region.id]
    : undefined;
  const isSlotRegion = !!slotDefinition;
  const slotState = isSlotRegion
    ? getSlotRegionState(region.id, exposedSlots, slotOverrides, slotDefaults)
    : null;
  const isInherited = slotState === 'inherited';
  const isOverridden = slotState === 'overridden';
  const visibleComponents = isSlotRegion
    ? filterNonMarkerComponents(region.components)
    : region.components;
  const slotMenuItems =
    isSlotRegion && (isInherited || isOverridden) ? (
      <SlotOverrideMenuItems
        region={region}
        isInherited={isInherited}
        testIdPrefix="slot-layer"
      />
    ) : null;

  // Template editor: the content region is where a user jumps to editing the
  // previewed entity, mirroring the page navigator's contextual menu. Only
  // shown for a templated bundle with exposed slots.
  const hasExposedSlots = Object.keys(exposedSlots ?? {}).length > 0;
  const isContentRegion =
    editorFrameContext === EditorFrameContext.TEMPLATE && !isPerContentMode;
  const { data: previewEntities } = useGetPreviewContentEntitiesQuery(
    { entityTypeId: entityType ?? '', bundle: bundle ?? '' },
    { skip: !isContentRegion || !hasExposedSlots || !entityType || !bundle },
  );
  // The server annotates each entity with edit URLs it is permitted to use, so
  // the actions below are already permission-gated and entity-type-generic.
  const currentPreviewEntity = previewEntityId
    ? previewEntities?.[previewEntityId]
    : undefined;
  const editActions =
    isContentRegion && hasExposedSlots
      ? buildContentEditActions(
          navigateToEditor,
          entityType,
          currentPreviewEntity,
        )
      : [];
  const contentEditItems =
    editActions.length > 0 ? (
      <>
        <UnifiedMenu.Label>{region.name}</UnifiedMenu.Label>
        <UnifiedMenu.Separator />
        {editActions.map((action) => (
          <UnifiedMenu.Item
            key={action.key}
            onClick={action.run}
            data-testid={`content-${action.key}`}
          >
            <Flex gap="2" align="center">
              {action.label}
              {action.external && <ExternalLinkIcon />}
            </Flex>
          </UnifiedMenu.Item>
        ))}
      </>
    ) : null;
  const menuItems = contentEditItems ?? slotMenuItems;

  // Prevent selecting text when double-clicking the page node (double-click normally selects text).
  const handleMouseDown = useCallback((event: React.MouseEvent) => {
    if (event.detail > 1) {
      event.preventDefault();
    }
  }, []);

  const handleMouseOver = useCallback(
    (event: React.MouseEvent<HTMLDivElement>) => {
      event.stopPropagation();
      dispatch(setHoveredComponent(region.id));
    },
    [dispatch, region.id],
  );

  const handleMouseOut = useCallback(
    (event: React.MouseEvent<HTMLDivElement>) => {
      event.stopPropagation();
      dispatch(unsetHoveredComponent());
    },
    [dispatch],
  );

  const indent = useIndentContext();

  // Clicking the region deselects any component, which shows the entity (Page
  // data) form in the contextual panel.
  const sidebarNode = (
    <SidebarNode
      onMouseDown={handleMouseDown}
      onMouseOver={handleMouseOver}
      onMouseOut={handleMouseOut}
      onClick={unsetSelectedComponent}
      draggable={false}
      title={region.name}
      variant="page"
      open={!isInherited}
      hovered={isHovered}
      selected={
        hasEntityForm && !isSlotRegion && !selectedComponent && !isMultiSelect
      }
      data-hovered={isHovered}
      dropdownMenuContent={
        menuItems ? (
          <UnifiedMenu.Content menuType="dropdown">
            {menuItems}
          </UnifiedMenu.Content>
        ) : undefined
      }
      // Per-content editing: always label the slot's state, so an empty slot
      // (no Override menu, nothing to override) reads as intended.
      trailingContent={
        slotState ? (
          <Badge
            size="1"
            variant="soft"
            color={SLOT_STATE_BADGES[slotState].color}
            title={SLOT_STATE_BADGES[slotState].title}
            data-testid={`slot-layer-${slotState}-marker-${region.id}`}
          >
            {SLOT_STATE_BADGES[slotState].label}
          </Badge>
        ) : undefined
      }
    />
  );

  return (
    <Box>
      {menuItems ? (
        <ContextMenu.Root>
          <ContextMenu.Trigger>{sidebarNode}</ContextMenu.Trigger>
          <UnifiedMenu.Content menuType="context" align="start" side="right">
            {menuItems}
          </UnifiedMenu.Content>
        </ContextMenu.Root>
      ) : (
        sidebarNode
      )}
      {!isInherited && (
        <Box role="tree">
          {visibleComponents.map((component, index) => (
            <ComponentLayer
              index={index}
              key={component.uuid}
              component={component}
              parentNode={region}
              indent={indent + 1}
            />
          ))}
        </Box>
      )}
    </Box>
  );
};

export default RegionLayer;
