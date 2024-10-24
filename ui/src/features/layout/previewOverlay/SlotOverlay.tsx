import type React from 'react';
import { useEffect, useRef, useState } from 'react';
import styles from './PreviewOverlay.module.css';
import useSyncElementSize from '@/hooks/useSyncElementSize';
import { useAppSelector } from '@/app/hooks';
import {
  selectCanvasViewPortScale,
  selectHoveredComponent,
  selectSelectedComponent,
  selectTargetSlot,
} from '@/features/ui/uiSlice';
import clsx from 'clsx';
import NameTag from '@/features/layout/preview/NameTag';
import ComponentOverlay from '@/features/layout/previewOverlay/ComponentOverlay';
import type { LayoutNode } from '@/features/layout/layoutModelSlice';
import { selectModel } from '@/features/layout/layoutModelSlice';
import { getDistanceBetweenElements } from '@/utils/function-utils';

const SlotOverlay: React.FC<SlotOverlayProps> = (props) => {
  const { slot, parentComponent, iframeRef } = props;
  const elementRect = useSyncElementSize(iframeRef.current, slot.uuid);
  const [elementOffset, setElementOffset] = useState({
    horizontalDistance: 0,
    verticalDistance: 0,
    paddingTop: '0px',
    paddingBottom: '0px',
  });
  const selectedComponent = useAppSelector(selectSelectedComponent);
  const hoveredComponent = useAppSelector(selectHoveredComponent);
  const targetSlot = useAppSelector(selectTargetSlot);
  const canvasViewPortScale = useAppSelector(selectCanvasViewPortScale);
  const nameTagElRef = useRef<HTMLDivElement | null>(null);
  const model = useAppSelector(selectModel);
  const parentComponentName =
    model[parentComponent.uuid].name || 'Unnamed component';

  useEffect(() => {
    const iframeDocument = iframeRef.current?.contentDocument;
    if (!iframeDocument) {
      return;
    }

    // Use querySelector to find the element inside the iframe
    const elementInsideIframe = iframeDocument.querySelector(
      `[data-xb-uuid="${slot.uuid}"]`,
    );
    const parentElementInsideIframe = iframeDocument.querySelector(
      `[data-xb-uuid="${parentComponent.uuid}"]`,
    );
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
  }, [slot.uuid, elementRect, iframeRef, parentComponent.uuid]);

  return (
    <div
      aria-label={`${parentComponentName} ${slot.name}`}
      className={clsx('slotOverlay', styles.slotOverlay, {
        [styles.selected]: slot.uuid === selectedComponent,
        [styles.hovered]: slot.uuid === hoveredComponent,
        [styles.dropTarget]: slot.uuid === targetSlot,
      })}
      data-xb-type="slot"
      style={{
        height: elementRect.height * canvasViewPortScale,
        width: elementRect.width * canvasViewPortScale,
        top: elementOffset.verticalDistance * canvasViewPortScale,
        left: elementOffset.horizontalDistance * canvasViewPortScale,
      }}
    >
      {targetSlot === slot.uuid && (
        <div
          ref={nameTagElRef}
          className={clsx(styles.xbNameTag, styles.xbNameTagSlot)}
        >
          <NameTag
            componentUuid={slot.uuid}
            selected={selectedComponent === slot.uuid}
            nodeType={slot.nodeType}
          />
        </div>
      )}

      {slot.children.map((childComponent: LayoutNode) => (
        <ComponentOverlay
          key={childComponent.uuid}
          iframeRef={iframeRef}
          parentComponent={slot}
          component={childComponent}
        />
      ))}
    </div>
  );
};

export interface SlotOverlayProps {
  slot: any;
  iframeRef: React.RefObject<HTMLIFrameElement>;
  parentComponent: any;
}

export default SlotOverlay;
