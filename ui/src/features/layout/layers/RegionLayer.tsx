import { useCallback } from 'react';
import { useParams } from 'react-router-dom';
import { Box } from '@radix-ui/themes';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import { useIndentContext } from '@/components/sidePanel/ListIndentContext';
import SidebarNode from '@/components/sidePanel/SidebarNode';
import ComponentLayer from '@/features/layout/layers/ComponentLayer';
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
import { PAGE_VARIANT_ENTITY_TYPE } from '@/services/pageVariants';

import type React from 'react';
import type { RegionNode } from '@/features/layout/layoutModelSlice';

const RegionLayer: React.FC<{ region: RegionNode }> = ({ region }) => {
  const dispatch = useAppDispatch();
  const isHovered = useAppSelector((state) => {
    return selectIsComponentHovered(state, region.id);
  });
  const selectedComponent = useAppSelector(selectSelectedComponentUuid);
  const isMultiSelect = useAppSelector(selectIsMultiSelect);
  const { unsetSelectedComponent } = useComponentSelection();
  const editorFrameContext = useAppSelector(selectEditorFrameContext);
  const { entityType } = useParams();
  // Templates and page variants have no entity form, so the region row is
  // never the "selected" target there (the panel hides when nothing is
  // selected).
  const hasEntityForm =
    editorFrameContext === EditorFrameContext.ENTITY &&
    entityType !== PAGE_VARIANT_ENTITY_TYPE;

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

  return (
    <Box>
      {/* Clicking the region deselects any component, which shows the entity
          (Page data) form in the contextual panel. */}
      <SidebarNode
        onMouseDown={handleMouseDown}
        onMouseOver={handleMouseOver}
        onMouseOut={handleMouseOut}
        onClick={unsetSelectedComponent}
        draggable={false}
        title={region.name}
        variant="page"
        open={true}
        hovered={isHovered}
        selected={hasEntityForm && !selectedComponent && !isMultiSelect}
        data-hovered={isHovered}
      />
      <Box role="tree">
        {region.components.map((component, index) => (
          <ComponentLayer
            index={index}
            key={component.uuid}
            component={component}
            parentNode={region}
            indent={indent + 1}
          />
        ))}
      </Box>
    </Box>
  );
};

export default RegionLayer;
