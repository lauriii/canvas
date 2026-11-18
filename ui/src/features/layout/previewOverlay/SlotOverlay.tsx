import { useEffect, useMemo, useRef, useState } from 'react';
import clsx from 'clsx';
import { useParams } from 'react-router';
import { BoxIcon, BoxModelIcon } from '@radix-ui/react-icons';
import { Tooltip } from '@radix-ui/themes';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import { findExposedSlotEntry } from '@/features/layout/exposedSlots';
import {
  selectExposedSlots,
  selectPreviewSlotOverrides,
} from '@/features/layout/layoutModelSlice';
import ComponentContextMenu from '@/features/layout/preview/ComponentContextMenu';
import { SlotNameTag } from '@/features/layout/preview/NameTag';
import { usePreviewGeometry } from '@/features/layout/preview/PreviewGeometryContext';
import ComponentOverlay from '@/features/layout/previewOverlay/ComponentOverlay';
import EmptySlotDropZone from '@/features/layout/previewOverlay/EmptySlotDropZone';
import {
  EditorFrameContext,
  selectEditorFrameContext,
  selectEditorViewPortScale,
  selectHoveredComponent,
  selectIsComponentHovered,
  selectSelectedComponentUuid,
  selectSelection,
  selectTargetSlot,
  setHoveredComponent,
  unsetHoveredComponent,
} from '@/features/ui/uiSlice';
import useComponentSelection from '@/hooks/useComponentSelection';
import useGetComponentName from '@/hooks/useGetComponentName';
import { useGetPreviewContentEntitiesQuery } from '@/services/componentAndLayout';

import type React from 'react';
import type { DraggableSyntheticListeners } from '@dnd-kit/core';
import type {
  ComponentNode,
  SlotNode,
} from '@/features/layout/layoutModelSlice';

import nameTagStyles from '@/features/layout/preview/NameTag.module.css';
import styles from './PreviewOverlay.module.css';

// import SlotDropZone from '@/features/layout/previewOverlay/SlotDropZone';

// The height a name tag takes above its box.
// @see --name-tag-vertical-offset in PreviewOverlay.module.css
const NAME_TAG_HEIGHT = 24;

// How long a chip stays shown after the pointer leaves what shows it.
const CHIP_HIDE_DELAY_MS = 150;

export interface SlotOverlayProps {
  slot: SlotNode;
  parentComponent: ComponentNode;
  // The parent component's drag listeners: the slot's click target covers the
  // parent's drag handle, so pressing it must start the parent's drag.
  parentDragListeners?: DraggableSyntheticListeners;
  disableDrop: boolean;
}

const SlotOverlay: React.FC<SlotOverlayProps> = ({
  slot,
  parentComponent,
  parentDragListeners,
  disableDrop,
}) => {
  const { geometryMap } = usePreviewGeometry();
  const slotId = slot.id;
  const slotGeometry = geometryMap.slot[slotId];
  const parentGeometry = geometryMap.component[parentComponent.uuid];
  const offsetLeft =
    slotGeometry && parentGeometry
      ? slotGeometry.rect.left - parentGeometry.rect.left
      : 0;
  const offsetTop =
    slotGeometry && parentGeometry
      ? slotGeometry.rect.top - parentGeometry.rect.top
      : 0;
  const {
    componentId: routedSelectedComponent,
    entityType,
    bundle,
    previewEntityId,
  } = useParams();
  const nonRoutedSelectedComponent = useAppSelector(
    selectSelectedComponentUuid,
  );
  const { setNonRoutedSelection, handleComponentSelection } =
    useComponentSelection();
  const selectionItems = useAppSelector(selectSelection).items;
  const isHovered = useAppSelector((state) => {
    return selectIsComponentHovered(state, slotId);
  });
  const targetSlot = useAppSelector(selectTargetSlot);
  const editorViewPortScale = useAppSelector(selectEditorViewPortScale);
  const slotName = useGetComponentName(slot, parentComponent);
  const parentComponentName = useGetComponentName(parentComponent);

  // Template editor: exposed-slot marking + management controls.
  const dispatch = useAppDispatch();
  const editorFrameContext = useAppSelector(selectEditorFrameContext);
  const isTemplateContext = editorFrameContext === EditorFrameContext.TEMPLATE;
  const selectedComponent = isTemplateContext
    ? nonRoutedSelectedComponent
    : routedSelectedComponent;
  const exposedSlots = useAppSelector(selectExposedSlots);
  const exposed = isTemplateContext
    ? findExposedSlotEntry(exposedSlots, parentComponent.uuid, slot.name)
    : null;
  // The preview shows the preview entity's content in a slot it overrides,
  // not the template default.
  const previewSlotOverrides = useAppSelector(selectPreviewSlotOverrides);
  const previewOverride = exposed
    ? previewSlotOverrides?.[exposed.fieldName]
    : undefined;
  const isOverriddenByPreview = !!previewOverride?.overridden;
  // The previewed item's label names what overrides the slot. The Topbar
  // fetches the same list, so this reads from the shared query cache.
  const { data: previewEntities } = useGetPreviewContentEntitiesQuery(
    { entityTypeId: entityType ?? '', bundle: bundle ?? '' },
    { skip: !isOverriddenByPreview || !entityType || !bundle },
  );
  const overridingItemLabel = previewEntityId
    ? previewEntities?.[previewEntityId]?.label
    : undefined;
  // The parent, one of its slots, or a component directly in one of its
  // slots, that is hovered or selected. All chips of a parent show together,
  // so a selected slot keeps its siblings' chips to switch to. Components
  // deeper down show the chips of their own slots.
  const hoveredComponent = useAppSelector(selectHoveredComponent);
  const isRelative = (id: string) =>
    id === parentComponent.uuid ||
    parentComponent.slots.some(
      (sibling) =>
        sibling.id === id ||
        sibling.components.some((component) => component.uuid === id),
    );
  const activeRelatives = [
    ...(hoveredComponent && isRelative(hoveredComponent)
      ? [hoveredComponent]
      : []),
    ...(selectedComponent && isRelative(selectedComponent)
      ? [selectedComponent]
      : []),
    ...selectionItems.filter(isRelative),
  ];
  const isActive = activeRelatives.length > 0 || targetSlot === slotId;
  const isFilledByPreview = isOverriddenByPreview && !previewOverride?.empty;

  // Template editor: a hover highlights what a click selects. The slot's
  // click target hovers the parent, and the chip hovers the slot.
  const slotTriggerRef = useRef<HTMLButtonElement>(null);
  const chipRef = useRef<HTMLDivElement>(null);
  const handleTriggerMouseOver = (event: React.MouseEvent) => {
    event.stopPropagation();
    dispatch(setHoveredComponent(parentComponent.uuid));
  };
  const handleSlotMouseOver = (event: React.MouseEvent) => {
    event.stopPropagation();
    dispatch(setHoveredComponent(slotId));
  };
  // Moving between the click target and the chip leaves the hover to the
  // mouseover of the one entered.
  const handleSlotMouseOut = (event: React.MouseEvent) => {
    event.stopPropagation();
    const next = event.relatedTarget;
    if (
      next instanceof Node &&
      (slotTriggerRef.current?.contains(next) ||
        chipRef.current?.contains(next))
    ) {
      return;
    }
    dispatch(unsetHoveredComponent());
  };
  const chipLabel = exposed ? exposed.definition.label : slotName;

  // Template editor: the chip shows while the parent, one of its slots or a
  // component in one of them is active, whether or not the slot is exposed
  // (an exposed slot is marked by its outline). It hides after a delay:
  // moving the pointer onto the chip first ends the hover that showed it, and
  // the chip would unmount before its own mouseover.
  const [isActiveLingering, setIsActiveLingering] = useState(false);
  useEffect(() => {
    if (isActive) {
      setIsActiveLingering(true);
      return;
    }
    const timeout = setTimeout(
      () => setIsActiveLingering(false),
      CHIP_HIDE_DELAY_MS,
    );
    return () => clearTimeout(timeout);
  }, [isActive]);
  const isChipActive = isActive || isActiveLingering;
  // An active chip sits inside the slot's top-left corner when it would be
  // outside the parent, which the pointer would leave on its way to the chip,
  // or where the name tag of an active relative is. An inactive chip stays
  // above the slot, so it does not cover the slot's content.
  const isLevelWithSlotTop = (top: number | undefined) =>
    !!slotGeometry &&
    top !== undefined &&
    Math.abs(slotGeometry.rect.top - top) * editorViewPortScale <
      NAME_TAG_HEIGHT;
  const isChipInside =
    isChipActive &&
    (isLevelWithSlotTop(parentGeometry?.rect.top) ||
      activeRelatives.some((uuid) =>
        isLevelWithSlotTop(geometryMap.component[uuid]?.rect.top),
      ));

  const style: React.CSSProperties = useMemo(
    () => ({
      height: (slotGeometry?.rect.height ?? 0) * editorViewPortScale,
      width: (slotGeometry?.rect.width ?? 0) * editorViewPortScale,
      top: offsetTop * editorViewPortScale,
      left: offsetLeft * editorViewPortScale,
      pointerEvents: 'none',
    }),
    [
      editorViewPortScale,
      offsetLeft,
      offsetTop,
      slotGeometry?.rect.height,
      slotGeometry?.rect.width,
    ],
  );

  // Template editor: the slot chip selects the slot, whose settings offer the
  // slot's actions. The slot id contains a slash, so the selection is held in
  // redux only (@see setNonRoutedSelection).
  const chipButton = (
    <button
      type="button"
      onClick={(event) => {
        // The parent component's overlay would select the component.
        event.stopPropagation();
        setNonRoutedSelection(slotId);
      }}
      className={clsx(
        nameTagStyles.nameTag,
        nameTagStyles.slot,
        styles.slotExposeChipButton,
      )}
      aria-label={
        exposed
          ? `Select editable slot ${chipLabel}${isOverriddenByPreview ? ` (overridden by ${overridingItemLabel ?? 'the previewed item'})` : ''}`
          : `Select slot ${slotName}`
      }
      data-testid={`slot-expose-chip-${slotId}`}
    >
      {exposed ? (
        <BoxIcon width={10} height={10} />
      ) : (
        <BoxModelIcon width={10} height={10} />
      )}
      <span>{chipLabel}</span>
      {isOverriddenByPreview && (
        <span
          className={styles.slotOverriddenLabel}
          data-testid={`slot-overridden-label-${slotId}`}
        >
          {overridingItemLabel
            ? `Overridden by ${overridingItemLabel}`
            : 'Overridden'}
        </span>
      )}
    </button>
  );

  if (!slotGeometry || !parentGeometry) {
    return null;
  }

  return (
    <div
      aria-label={`${slotName} (${parentComponentName})`}
      className={clsx('slotOverlay', styles.slotOverlay, {
        [styles.selected]: slotId === selectedComponent,
        [styles.hovered]: isHovered,
        [styles.dropTarget]: slotId === targetSlot,
        [styles.exposed]: !!exposed,
      })}
      data-canvas-type="slot"
      data-canvas-drop-container={slotId}
      data-canvas-stack-direction={slotGeometry.stackDirection}
      data-canvas-exposed={exposed ? true : undefined}
      style={style}
    >
      {/* Template editor: a full-cover trigger, on which a hover, a click, a
          right-click and a drag act on the parent, like on the parent's own
          overlay. Rendered first so drop zones and child component overlays
          remain interactive on top of it. */}
      {isTemplateContext && (
        <ComponentContextMenu component={parentComponent}>
          <button
            type="button"
            aria-label={`Slot ${slotName} (${parentComponentName})`}
            className={styles.slotContextTrigger}
            data-canvas-overlay="true"
            {...parentDragListeners}
            onClick={(event) => {
              event.stopPropagation();
              handleComponentSelection(parentComponent.uuid, event.metaKey);
            }}
            onMouseOver={handleTriggerMouseOver}
            onMouseOut={handleSlotMouseOut}
            ref={slotTriggerRef}
          />
        </ComponentContextMenu>
      )}
      {/* Template editor: the chip of a slot the previewed item overrides
          always shows, because the preview then lacks the template default. */}
      {isTemplateContext
        ? (isChipActive || isOverriddenByPreview) && (
            <div
              className={clsx(styles.canvasNameTag, styles.slotExposeChip, {
                [styles.slotExposeChipInside]: isChipInside,
              })}
              ref={chipRef}
              onMouseOver={handleSlotMouseOver}
              onMouseOut={handleSlotMouseOut}
            >
              {isOverriddenByPreview ? (
                <Tooltip
                  content={`${overridingItemLabel ?? 'The previewed item'} overrides this slot, so the preview shows its content, not the template default. Preview another item in the top bar to see the default.`}
                >
                  {chipButton}
                </Tooltip>
              ) : (
                chipButton
              )}
            </div>
          )
        : (targetSlot === slotId || isHovered) && (
            <div
              className={clsx(styles.canvasNameTag, styles.canvasNameTagSlot)}
            >
              <SlotNameTag
                name={`${slotName} (${parentComponentName})`}
                id={slotId}
                nodeType={slot.nodeType}
              />
            </div>
          )}
      {!slot.components.length && !disableDrop && (
        <EmptySlotDropZone
          slot={slot}
          slotName={slotName}
          parentComponent={parentComponent}
          isFilledByPreview={isFilledByPreview}
        />
      )}

      {slot.components.map((childComponent: ComponentNode, index) => (
        <ComponentOverlay
          key={childComponent.uuid}
          parentSlot={slot}
          component={childComponent}
          index={index}
          disableDrop={disableDrop}
        />
      ))}

      {/* @todo - these SlotDropZones might become useful in future for handling more complex nested "container" components */}
      {/*{!disableDrop && (*/}
      {/*<SlotDropZone*/}
      {/*  slot={slot}*/}
      {/*  position="before"*/}
      {/*  size={size}*/}
      {/*  parentComponent={parentComponent}*/}
      {/*/>*/}
      {/*<SlotDropZone*/}
      {/*  slot={slot}*/}
      {/*  position="after"*/}
      {/*  size={size}*/}
      {/*  parentComponent={parentComponent}*/}
      {/*/>*/}
      {/*)}*/}
    </div>
  );
};

export default SlotOverlay;
