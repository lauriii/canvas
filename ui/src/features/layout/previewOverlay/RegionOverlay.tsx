import { useEffect, useState } from 'react';
import clsx from 'clsx';
import { useParams } from 'react-router';
import { LockClosedIcon } from '@radix-ui/react-icons';
import { ContextMenu } from '@radix-ui/themes';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import UnifiedMenu from '@/components/UnifiedMenu';
import {
  filterNonMarkerComponents,
  isLockedSlotRegion,
} from '@/features/layout/exposedSlots';
import {
  overrideSlotDefaultContent,
  revertSlotOverride,
  selectExposedSlots,
  selectIsPerContentMode,
  selectLayoutForRegion,
  selectSlotDefaults,
  selectSlotOverrides,
} from '@/features/layout/layoutModelSlice';
import { useDataToHtmlMapValue } from '@/features/layout/preview/DataToHtmlMapContext';
import { RegionNameTag } from '@/features/layout/preview/NameTag';
import RegionContextMenu from '@/features/layout/preview/RegionContextMenu';
import ComponentOverlay from '@/features/layout/previewOverlay/ComponentOverlay';
import EmptyRegionDropZone from '@/features/layout/previewOverlay/EmptyRegionDropZone';
import RegionDropZone from '@/features/layout/previewOverlay/RegionDropZone';
import {
  DEFAULT_REGION,
  selectDragging,
  selectEditorViewPortScale,
  selectIsComponentHovered,
  selectSelectedComponentUuid,
  selectTargetSlot,
  setHoveredComponent,
  unsetHoveredComponent,
} from '@/features/ui/uiSlice';
import useComponentSelection from '@/hooks/useComponentSelection';
import useEditorNavigation from '@/hooks/useEditorNavigation';
import useSyncPreviewElementSize from '@/hooks/useSyncPreviewElementSize';

import type React from 'react';
import type { RegionNode } from '@/features/layout/layoutModelSlice';

import styles from './PreviewOverlay.module.css';

interface RegionOverlayProps {
  iframeRef: React.RefObject<HTMLIFrameElement>;
  regionId: string;
  regionName: string;
  region: RegionNode;
}

const RegionOverlay: React.FC<RegionOverlayProps> = ({ iframeRef, region }) => {
  const layout = useAppSelector((state) =>
    selectLayoutForRegion(state, region.id),
  );
  const { regionsMap, slotsMap } = useDataToHtmlMapValue();
  const { regionId: focusedRegion = DEFAULT_REGION } = useParams();
  const editorViewPortScale = useAppSelector(selectEditorViewPortScale);
  const perContentMode = useAppSelector(selectIsPerContentMode);

  // Per-content editing: an exposed slot presents as a top-level slot region,
  // keyed by its backing field name. It is anchored by the slot marker the
  // template chrome's Twig emits (the chrome itself is inert, unmarked HTML),
  // and it is a single locked unit while the entity has not overridden a
  // non-empty template default.
  const exposedSlots = useAppSelector(selectExposedSlots);
  const slotOverrides = useAppSelector(selectSlotOverrides);
  const slotDefaults = useAppSelector(selectSlotDefaults);
  const slotDefinition = perContentMode ? exposedSlots?.[region.id] : undefined;
  const isSlotRegion = !!slotDefinition;
  const slotSelectionId = slotDefinition
    ? `${slotDefinition.componentUuid}/${slotDefinition.slotName}`
    : undefined;
  const isLocked =
    isSlotRegion &&
    isLockedSlotRegion(region.id, exposedSlots, slotOverrides, slotDefaults);
  const isOverridden = !!(
    isSlotRegion && slotOverrides?.[region.id]?.overridden
  );
  const selectedComponent = useAppSelector(selectSelectedComponentUuid);
  const { setNonRoutedSelection } = useComponentSelection();
  const isSlotRegionSelected =
    !!slotSelectionId && selectedComponent === slotSelectionId;

  const slotElement = slotSelectionId
    ? (slotsMap[slotSelectionId]?.element ?? null)
    : null;
  const { elementRect } = useSyncPreviewElementSize(
    isSlotRegion ? slotElement : (regionsMap[region.id]?.elements ?? null),
  );
  const [overlayStyles, setOverlayStyles] = useState({});
  const targetSlot = useAppSelector(selectTargetSlot);
  // Slot regions are always active; theme regions only when focused.
  const disableRegion = isSlotRegion ? isLocked : focusedRegion !== region.id;
  const dispatch = useAppDispatch();
  const { isDragging } = useAppSelector(selectDragging);
  const isHovered = useAppSelector((state) => {
    return selectIsComponentHovered(state, region.id);
  });
  const { setSelectedRegion } = useEditorNavigation();

  const showHovered = isHovered && focusedRegion === DEFAULT_REGION;
  // Marker nodes render nothing; hide them so an empty override shows empty.
  const visibleComponents = isSlotRegion
    ? filterNonMarkerComponents(layout.components)
    : layout.components;

  useEffect(() => {
    setOverlayStyles({
      top: `${elementRect.top * editorViewPortScale}px`,
      left: `${elementRect.left * editorViewPortScale}px`,
      width: `${elementRect.width * editorViewPortScale}px`,
      height: `${elementRect.height * editorViewPortScale}px`,
    });
  }, [elementRect, editorViewPortScale, region.id, disableRegion, regionsMap]);

  function handleItemMouseOver(event: React.MouseEvent<HTMLDivElement>) {
    event.stopPropagation();
    if (!isDragging) {
      dispatch(setHoveredComponent(region.id));
    }
  }

  function handleItemMouseOut(event: React.MouseEvent<HTMLDivElement>) {
    event.stopPropagation();
    dispatch(unsetHoveredComponent());
  }

  function handleRegionDblClick(event: React.MouseEvent<HTMLDivElement>) {
    event.stopPropagation();
    if (isSlotRegion) {
      // Slot regions are not navigable focus targets.
      return;
    }
    if (focusedRegion !== region.id) {
      // Navigate into the clicked region if it's different
      setSelectedRegion(region.id);
    } else {
      // Else we are already focused in this region, so clicking again should take us back out to the content region.
      setSelectedRegion();
    }
  }

  // If the DEFAULT_REGION is focused, then all regions should render otherwise only render if this is the focused region
  if (focusedRegion !== DEFAULT_REGION && focusedRegion !== region.id) {
    return null;
  }

  const isPage = region.id === DEFAULT_REGION;

  return (
    <div
      className={clsx(
        [isPage && styles.pageOverlay, !isPage && styles.regionOverlay],
        {
          [styles.dropTarget]: region.id === targetSlot,
          [styles.hovered]: showHovered,
        },
        `canvas--region-overlay__${region.id}`,
      )}
      style={overlayStyles}
      onMouseOver={handleItemMouseOver}
      onMouseOut={handleItemMouseOut}
      onDoubleClick={handleRegionDblClick}
    >
      {!isPage && !isSlotRegion && (
        <RegionContextMenu region={region}>
          <div
            aria-label={`Global region ${region.name}`}
            className={styles.regionItem}
            data-canvas-overlay="true"
          />
        </RegionContextMenu>
      )}

      {/* Per-content editing: a locked slot region is one selectable unit.
          Left-click selects it (its contextual panel offers Unlock and the
          template jump); right-click offers Unlock directly. */}
      {isSlotRegion && isLocked && slotSelectionId && (
        <>
          <ContextMenu.Root>
            <ContextMenu.Trigger>
              <button
                type="button"
                aria-label={`Locked slot ${region.name}`}
                className={styles.lockedSlotTrigger}
                data-testid={`slot-locked-${region.id}`}
                onClick={(event) => {
                  event.stopPropagation();
                  setNonRoutedSelection(slotSelectionId);
                }}
              />
            </ContextMenu.Trigger>
            <UnifiedMenu.Content
              menuType="context"
              align="start"
              side="right"
              aria-label={`Options for ${region.name}`}
            >
              <UnifiedMenu.Label>{region.name}</UnifiedMenu.Label>
              <UnifiedMenu.Separator />
              <UnifiedMenu.Item
                onClick={() => dispatch(overrideSlotDefaultContent(region.id))}
                data-testid={`slot-unlock-${region.id}`}
              >
                Unlock
              </UnifiedMenu.Item>
            </UnifiedMenu.Content>
          </ContextMenu.Root>
          {(isHovered || isSlotRegionSelected) && (
            <div className={styles.lockBadge} aria-hidden="true">
              <LockClosedIcon />
              <span>{region.name}</span>
            </div>
          )}
        </>
      )}

      {/* Per-content editing: reverting an override is a rare action, so it
          lives in the slot region's right-click menu. */}
      {isSlotRegion && isOverridden && (
        <ContextMenu.Root>
          <ContextMenu.Trigger>
            <div
              aria-label={`Slot ${region.name}`}
              className={styles.regionItem}
              data-canvas-overlay="true"
            />
          </ContextMenu.Trigger>
          <UnifiedMenu.Content
            menuType="context"
            align="start"
            side="right"
            aria-label={`Options for ${region.name}`}
          >
            <UnifiedMenu.Label>{region.name}</UnifiedMenu.Label>
            <UnifiedMenu.Separator />
            <UnifiedMenu.Item
              onClick={() => dispatch(revertSlotOverride(region.id))}
              data-testid={`slot-revert-${region.id}`}
            >
              Revert to default
            </UnifiedMenu.Item>
          </UnifiedMenu.Content>
        </ContextMenu.Root>
      )}

      <div className={clsx(styles.canvasNameTag)}>
        <RegionNameTag
          name={region.name}
          id={region.id}
          nodeType={isPage ? 'page' : 'region'}
        />
      </div>

      {!disableRegion && (
        <>
          {visibleComponents.map((component, index) => (
            <ComponentOverlay
              key={component.uuid}
              iframeRef={iframeRef}
              component={component}
              parentRegion={layout}
              index={index}
            />
          ))}

          {!visibleComponents.length && <EmptyRegionDropZone region={region} />}
          {!!visibleComponents.length && (
            <>
              <RegionDropZone region={region} position="before" />
              <RegionDropZone region={region} position="after" />
            </>
          )}
        </>
      )}
    </div>
  );
};

export default RegionOverlay;
