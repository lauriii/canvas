import { useCallback } from 'react';
import { useParams } from 'react-router-dom';
import { ExternalLinkIcon } from '@radix-ui/react-icons';
import { Box, ContextMenu, Flex } from '@radix-ui/themes';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import SidebarNode from '@/components/sidePanel/SidebarNode';
import { UnifiedMenu } from '@/components/UnifiedMenu';
import ComponentLayer from '@/features/layout/layers/ComponentLayer';
import {
  selectExposedSlots,
  selectIsPerContentMode,
} from '@/features/layout/layoutModelSlice';
import RegionContextMenu, {
  RegionContextMenuContent,
} from '@/features/layout/preview/RegionContextMenu';
import { buildContentEditActions } from '@/features/navigator/templatedContent';
import {
  DEFAULT_REGION,
  EditorFrameContext,
  selectEditorFrameContext,
  selectIsComponentHovered,
  setHoveredComponent,
  unsetHoveredComponent,
} from '@/features/ui/uiSlice';
import useEditorNavigation from '@/hooks/useEditorNavigation';
import { useGetPreviewContentEntitiesQuery } from '@/services/componentAndLayout';

import type React from 'react';
import type { RegionNode } from '@/features/layout/layoutModelSlice';

const RegionLayer: React.FC<{ region: RegionNode; isPage?: boolean }> = ({
  region,
  isPage = false,
}) => {
  const {
    regionId: focusedRegion = DEFAULT_REGION,
    entityType,
    bundle,
    previewEntityId,
  } = useParams();
  const { setSelectedRegion, navigateToEditor } = useEditorNavigation();
  const dispatch = useAppDispatch();
  const isHovered = useAppSelector((state) => {
    return selectIsComponentHovered(state, region.id);
  });
  const isTemplateContext =
    useAppSelector(selectEditorFrameContext) === EditorFrameContext.TEMPLATE;
  const isPerContentMode = useAppSelector(selectIsPerContentMode);
  const exposedSlots = useAppSelector(selectExposedSlots);

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

  const handleRegionClick = useCallback(() => {
    if (focusedRegion !== region.id) {
      // Navigate into the clicked region if it's different
      setSelectedRegion(region.id);
    } else {
      // Else we are already focused in this region, so clicking again should take us back out to the content region.
      setSelectedRegion();
    }
  }, [focusedRegion, region.id, setSelectedRegion]);

  // Prevent selecting text when double-clicking regions in the layers panel (double-click normally selects text).
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

  const variant: 'page' | 'region' = isPage ? 'page' : 'region';
  // The content region's own edit menu wins over the global-region menu; a
  // non-focused ordinary region keeps the "Edit global region" menu.
  const dropdownMenuContent = contentEditItems ? (
    <UnifiedMenu.Content menuType="dropdown">
      {contentEditItems}
    </UnifiedMenu.Content>
  ) : region.id !== focusedRegion ? (
    <RegionContextMenuContent region={region} menuType="dropdown" />
  ) : undefined;

  const sidebarNode = (
    <SidebarNode
      onDoubleClick={handleRegionClick}
      onMouseDown={handleMouseDown}
      onMouseOver={handleMouseOver}
      onMouseOut={handleMouseOut}
      draggable={false}
      title={region.name}
      variant={variant}
      open={region.id === focusedRegion}
      hovered={isHovered}
      data-hovered={isHovered}
      dropdownMenuContent={dropdownMenuContent}
    />
  );

  // Right-click wrapper: the content region opens its edit menu; other
  // non-focused regions keep the global-region menu; a focused ordinary region
  // has no right-click menu.
  const withContextMenu = contentEditItems ? (
    <ContextMenu.Root>
      <ContextMenu.Trigger>{sidebarNode}</ContextMenu.Trigger>
      <UnifiedMenu.Content menuType="context" align="start" side="right">
        {contentEditItems}
      </UnifiedMenu.Content>
    </ContextMenu.Root>
  ) : region.id !== focusedRegion ? (
    <RegionContextMenu region={region}>{sidebarNode}</RegionContextMenu>
  ) : (
    sidebarNode
  );

  return (
    <Box>
      {region.id === focusedRegion ? (
        <>
          {withContextMenu}
          <Box role="tree">
            {region.components.map((component, index) => (
              <ComponentLayer
                index={index}
                key={component.uuid}
                component={component}
                parentNode={region}
                indent={1}
              />
            ))}
          </Box>
        </>
      ) : (
        withContextMenu
      )}
    </Box>
  );
};

export default RegionLayer;
