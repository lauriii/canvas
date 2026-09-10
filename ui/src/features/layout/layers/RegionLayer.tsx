import { useCallback } from 'react';
import { useParams } from 'react-router-dom';
import { ExternalLinkIcon, LockClosedIcon } from '@radix-ui/react-icons';
import { Box, ContextMenu, Flex } from '@radix-ui/themes';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import { useIndentContext } from '@/components/sidePanel/ListIndentContext';
import SidebarNode from '@/components/sidePanel/SidebarNode';
import { UnifiedMenu } from '@/components/UnifiedMenu';
import {
  filterNonMarkerComponents,
  isLockedSlotRegion,
} from '@/features/layout/exposedSlots';
import ComponentLayer from '@/features/layout/layers/ComponentLayer';
import {
  overrideSlotDefaultContent,
  revertSlotOverride,
  selectExposedSlots,
  selectIsPerContentMode,
  selectSlotDefaults,
  selectSlotOverrides,
} from '@/features/layout/layoutModelSlice';
import { buildContentEditActions } from '@/features/navigator/templatedContent';
import {
  DEFAULT_REGION,
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
import type { RegionNode } from '@/features/layout/layoutModelSlice';

const RegionLayer: React.FC<{ region: RegionNode }> = ({ region }) => {
  const { entityType, bundle, previewEntityId } = useParams();
  const { navigateToEditor } = useEditorNavigation();
  const dispatch = useAppDispatch();
  const isHovered = useAppSelector((state) => {
    return selectIsComponentHovered(state, region.id);
  });
  const selectedComponent = useAppSelector(selectSelectedComponentUuid);
  const isMultiSelect = useAppSelector(selectIsMultiSelect);
  const { unsetSelectedComponent } = useComponentSelection();
  const editorFrameContext = useAppSelector(selectEditorFrameContext);
  // Templates and page variants have no entity form, so the region row is
  // never the "selected" target there (the panel hides when nothing is
  // selected).
  const hasEntityForm =
    editorFrameContext === EditorFrameContext.ENTITY &&
    entityType !== PAGE_VARIANT_ENTITY_TYPE;
  const isTemplateContext =
    useAppSelector(selectEditorFrameContext) === EditorFrameContext.TEMPLATE;
  const isPerContentMode = useAppSelector(selectIsPerContentMode);
  const exposedSlots = useAppSelector(selectExposedSlots);
  const slotOverrides = useAppSelector(selectSlotOverrides);
  const slotDefaults = useAppSelector(selectSlotDefaults);

  // Per-content editing: an exposed slot presents as a top-level slot region.
  // A locked one (template default present, not overridden) is a single
  // non-expandable row offering Unlock; an overridden one offers Revert.
  const slotDefinition = isPerContentMode
    ? exposedSlots?.[region.id]
    : undefined;
  const isSlotRegion = !!slotDefinition;
  const isLocked =
    isSlotRegion &&
    isLockedSlotRegion(region.id, exposedSlots, slotOverrides, slotDefaults);
  const isOverridden = !!(
    isSlotRegion && slotOverrides?.[region.id]?.overridden
  );
  const visibleComponents = isSlotRegion
    ? filterNonMarkerComponents(region.components)
    : region.components;
  const slotMenuItems = isSlotRegion ? (
    isLocked ? (
      <>
        <UnifiedMenu.Label>{region.name}</UnifiedMenu.Label>
        <UnifiedMenu.Separator />
        <UnifiedMenu.Item
          onClick={() => dispatch(overrideSlotDefaultContent(region.id))}
          data-testid={`slot-layer-unlock-${region.id}`}
        >
          Unlock
        </UnifiedMenu.Item>
      </>
    ) : isOverridden ? (
      <>
        <UnifiedMenu.Label>{region.name}</UnifiedMenu.Label>
        <UnifiedMenu.Separator />
        <UnifiedMenu.Item
          onClick={() => dispatch(revertSlotOverride(region.id))}
          data-testid={`slot-layer-revert-${region.id}`}
        >
          Revert to default
        </UnifiedMenu.Item>
      </>
    ) : null
  ) : null;

  // Template editor: the top-level content region is where a user jumps to
  // editing the previewed entity, mirroring the page navigator's contextual
  // menu. Only shown for a templated bundle with exposed slots.
  const hasExposedSlots = Object.keys(exposedSlots ?? {}).length > 0;
  const isContentRegion =
    isTemplateContext && !isPerContentMode && region.id === DEFAULT_REGION;
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

  // The content region's own "edit this entity" menu, when the template
  // editor previews a templated bundle with exposed slots; a slot region gets
  // the unlock/revert menu instead.
  const menuItems = contentEditItems ?? slotMenuItems;
  const dropdownMenuContent = menuItems ? (
    <UnifiedMenu.Content menuType="dropdown">{menuItems}</UnifiedMenu.Content>
  ) : undefined;

  const sidebarNode = (
    <SidebarNode
      onMouseDown={handleMouseDown}
      onMouseOver={handleMouseOver}
      onMouseOut={handleMouseOut}
      // Clicking a non-slot region deselects any component, which shows the
      // entity (Page data) form in the contextual panel. Slot regions are not
      // selection targets.
      onClick={isSlotRegion ? undefined : unsetSelectedComponent}
      draggable={false}
      title={region.name}
      variant={isSlotRegion ? 'region' : 'page'}
      open={isSlotRegion ? !isLocked : true}
      hovered={isHovered}
      selected={
        !isSlotRegion && hasEntityForm && !selectedComponent && !isMultiSelect
      }
      data-hovered={isHovered}
      dropdownMenuContent={dropdownMenuContent}
      trailingContent={
        isLocked ? (
          <Flex
            align="center"
            data-testid={`slot-layer-locked-marker-${region.id}`}
            aria-label="Locked slot"
          >
            <LockClosedIcon />
          </Flex>
        ) : undefined
      }
    />
  );

  const withContextMenu = menuItems ? (
    <ContextMenu.Root>
      <ContextMenu.Trigger>{sidebarNode}</ContextMenu.Trigger>
      <UnifiedMenu.Content menuType="context" align="start" side="right">
        {menuItems}
      </UnifiedMenu.Content>
    </ContextMenu.Root>
  ) : (
    sidebarNode
  );

  return (
    <Box>
      {withContextMenu}
      {/* A locked slot is a single non-expandable row: its content is the
          template's default, not the entity's. */}
      {!isLocked && (
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
