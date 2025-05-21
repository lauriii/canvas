import type React from 'react';
import { useEffect, useState, useMemo } from 'react';
import styles from './PreviewOverlay.module.css';
import { useAppSelector } from '@/app/hooks';
import {
  selectCanvasViewPortScale,
  selectIsComponentHovered,
  selectTargetSlot,
} from '@/features/ui/uiSlice';
import clsx from 'clsx';
import { SlotNameTag } from '@/features/layout/preview/NameTag';
import ComponentOverlay from '@/features/layout/previewOverlay/ComponentOverlay';
import type {
  ComponentNode,
  SlotNode,
} from '@/features/layout/layoutModelSlice';
import { getDistanceBetweenElements } from '@/utils/function-utils';
import useGetComponentName from '@/hooks/useGetComponentName';
import useSyncPreviewElementSize from '@/hooks/useSyncPreviewElementSize';
import { useDataToHtmlMapValue } from '@/features/layout/preview/DataToHtmlMapContext';
import EmptySlotDropZone from '@/features/layout/previewOverlay/EmptySlotDropZone';
import { useParams } from 'react-router';
// import SlotDropZone from '@/features/layout/previewOverlay/SlotDropZone';

export interface SlotOverlayProps {
  slot: SlotNode;
  iframeRef: React.RefObject<HTMLIFrameElement>;
  parentComponent: ComponentNode;
  disableDrop: boolean;
}

const SlotOverlay: React.FC<SlotOverlayProps> = (props) => {
  const { slot, parentComponent, iframeRef, disableDrop } = props;
  const { componentsMap, slotsMap } = useDataToHtmlMapValue();
  const slotId = slot.id;
  const slotElementArray = useMemo(() => {
    const element = slotsMap[slot.id]?.element;
    return element ? [element] : null;
  }, [slotsMap, slot.id]);
  const elementRect = useSyncPreviewElementSize(slotElementArray);
  const [elementOffset, setElementOffset] = useState({
    horizontalDistance: 0,
    verticalDistance: 0,
    paddingTop: '0px',
    paddingBottom: '0px',
  });
  const { componentId: selectedComponent } = useParams();
  const isHovered = useAppSelector((state) => {
    return selectIsComponentHovered(state, slotId);
  });
  const targetSlot = useAppSelector(selectTargetSlot);
  const canvasViewPortScale = useAppSelector(selectCanvasViewPortScale);
  const slotName = useGetComponentName(slot, parentComponent);
  const parentComponentName = useGetComponentName(parentComponent);

  useEffect(() => {
    const iframeDocument = iframeRef.current?.contentDocument;
    if (!iframeDocument) {
      return;
    }

    // Use querySelector to find the element inside the iframe
    const elementInsideIframe = slotsMap[slotId]?.element;

    const parentElementInsideIframe =
      componentsMap[parentComponent.uuid]?.elements[0];
    if (!elementInsideIframe) {
      return;
    }
    const computedStyle = window.getComputedStyle(elementInsideIframe);

    if (parentElementInsideIframe && elementInsideIframe) {
      setElementOffset((prevOffsets) => {
        // Only update the state if the offsets actually changes to prevent re-renders
        const newOffsets = {
          ...getDistanceBetweenElements(
            parentElementInsideIframe,
            elementInsideIframe,
          ),
          paddingTop: computedStyle.paddingTop,
          paddingBottom: computedStyle.paddingBottom,
        };

        if (
          prevOffsets.horizontalDistance !== newOffsets.horizontalDistance ||
          prevOffsets.verticalDistance !== newOffsets.verticalDistance ||
          prevOffsets.paddingTop !== newOffsets.paddingTop ||
          prevOffsets.paddingBottom !== newOffsets.paddingBottom
        ) {
          return newOffsets;
        }
        return prevOffsets;
      });
    }
  }, [
    componentsMap,
    slotsMap,
    elementRect,
    iframeRef,
    parentComponent.uuid,
    slotId,
  ]);

  const style: React.CSSProperties = useMemo(
    () => ({
      height: elementRect.height * canvasViewPortScale,
      width: elementRect.width * canvasViewPortScale,
      top: elementOffset.verticalDistance * canvasViewPortScale,
      left: elementOffset.horizontalDistance * canvasViewPortScale,
      pointerEvents: 'none',
    }),
    [
      elementRect.height,
      elementRect.width,
      canvasViewPortScale,
      elementOffset.verticalDistance,
      elementOffset.horizontalDistance,
    ],
  );

  return (
    <div
      aria-label={`${slotName} (${parentComponentName})`}
      className={clsx('slotOverlay', styles.slotOverlay, {
        [styles.selected]: slotId === selectedComponent,
        [styles.hovered]: isHovered,
        [styles.dropTarget]: slotId === targetSlot,
      })}
      data-xb-type="slot"
      style={style}
    >
      {(targetSlot === slotId || isHovered) && (
        <div className={clsx(styles.xbNameTag, styles.xbNameTagSlot)}>
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
        />
      )}

      {slot.components.map((childComponent: ComponentNode, index) => (
        <ComponentOverlay
          key={childComponent.uuid}
          iframeRef={iframeRef}
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
