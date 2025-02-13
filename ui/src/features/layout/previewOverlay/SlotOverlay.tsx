import type React from 'react';
import { useEffect, useRef, useState, useMemo } from 'react';
import styles from './PreviewOverlay.module.css';
import { useAppSelector } from '@/app/hooks';
import {
  selectCanvasViewPortScale,
  selectHoveredComponent,
  selectTargetSlot,
} from '@/features/ui/uiSlice';
import clsx from 'clsx';
import NameTag from '@/features/layout/preview/NameTag';
import ComponentOverlay from '@/features/layout/previewOverlay/ComponentOverlay';
import type {
  ComponentNode,
  SlotNode,
} from '@/features/layout/layoutModelSlice';
import { getDistanceBetweenElements } from '@/utils/function-utils';
import useGetComponentName from '@/hooks/useGetComponentName';
import useSyncPreviewElementSize from '@/hooks/useSyncPreviewElementSize';
import { useDataToHtmlMapValue } from '@/features/layout/preview/DataToHtmlMapContext';
import useXbParams from '@/hooks/useXbParams';

export interface SlotOverlayProps {
  slot: SlotNode;
  iframeRef: React.RefObject<HTMLIFrameElement>;
  parentComponent: ComponentNode;
}

const SlotOverlay: React.FC<SlotOverlayProps> = (props) => {
  const { slot, parentComponent, iframeRef } = props;
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
  const { componentId: selectedComponent } = useXbParams();
  const hoveredComponent = useAppSelector(selectHoveredComponent);
  const targetSlot = useAppSelector(selectTargetSlot);
  const canvasViewPortScale = useAppSelector(selectCanvasViewPortScale);
  const nameTagElRef = useRef<HTMLDivElement | null>(null);
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
      setElementOffset({
        ...getDistanceBetweenElements(
          parentElementInsideIframe,
          elementInsideIframe,
        ),
        paddingTop: computedStyle.paddingTop,
        paddingBottom: computedStyle.paddingBottom,
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

  return (
    <div
      aria-label={`${parentComponentName}: ${slotName}`}
      className={clsx('slotOverlay', styles.slotOverlay, {
        [styles.selected]: slotId === selectedComponent,
        [styles.hovered]: slotId === hoveredComponent,
        [styles.dropTarget]: slotId === targetSlot,
      })}
      data-xb-type="slot"
      style={{
        height: elementRect.height * canvasViewPortScale,
        width: elementRect.width * canvasViewPortScale,
        top: elementOffset.verticalDistance * canvasViewPortScale,
        left: elementOffset.horizontalDistance * canvasViewPortScale,
      }}
    >
      {(targetSlot === slotId || hoveredComponent === slotId) && (
        <div
          ref={nameTagElRef}
          className={clsx(styles.xbNameTag, styles.xbNameTagSlot)}
        >
          <NameTag
            name={slotName}
            componentUuid={slotId}
            selected={selectedComponent === slotId}
            nodeType={slot.nodeType}
          />
        </div>
      )}

      {slot.components.map((childComponent: ComponentNode) => (
        <ComponentOverlay
          key={childComponent.uuid}
          iframeRef={iframeRef}
          parentSlot={slot}
          component={childComponent}
        />
      ))}
    </div>
  );
};

export default SlotOverlay;
