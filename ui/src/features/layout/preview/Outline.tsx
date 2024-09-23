import styles from './Outline.module.css';
import type React from 'react';
import { useRef, useEffect, useState, useCallback } from 'react';
import { selectLayout, selectModel } from '@/features/layout/layoutModelSlice';
import { useAppDispatch, useAppSelector } from '@/app/hooks';
import { Grid } from '@radix-ui/themes';
import clsx from 'clsx';
import {
  selectCanvasViewPort,
  unsetHoveredComponent,
  unsetSelectedComponent,
} from '@/features/ui/uiSlice';

import useSyncElementSize from '@/hooks/useSyncElementSize';
import _ from 'lodash';
import NameTag from './NameTag';
import AddButton from './AddButton';

interface OutlineProps {
  elementId: string | undefined; // the data-xb-uuid value of the dom element that was hovered.
  selected: boolean;
  iframeRef: React.RefObject<HTMLIFrameElement>;
}

const Outline: React.FC<OutlineProps> = (props) => {
  const { elementId, selected, iframeRef } = props;
  const layout = useAppSelector(selectLayout);
  const model = useAppSelector(selectModel);
  const canvasViewPort = useAppSelector(selectCanvasViewPort);
  const hoveredElementRef = useRef<HTMLElement | null>(null);
  const outlineElRef = useRef<HTMLDivElement | null>(null);
  const nameTagElRef = useRef<HTMLDivElement | null>(null);
  const addSectionButtonRef = useRef<HTMLDivElement | null>(null);
  const [nodeType, setNodeType] = useState<'component' | 'slot' | null>(null);
  const dispatch = useAppDispatch();
  const elementRect = useSyncElementSize(iframeRef.current, elementId);

  const applyStyles = useCallback(() => {
    if (outlineElRef.current && elementRect) {
      outlineElRef.current.style.transform = `translate(${elementRect.left * canvasViewPort.scale}px, ${elementRect.top * canvasViewPort.scale}px)`;
      outlineElRef.current.style.width = `${elementRect.width * canvasViewPort.scale}px`;
      outlineElRef.current.style.height = `${elementRect.height * canvasViewPort.scale}px`;
      outlineElRef.current.style.opacity = '1';
    }

    if (nameTagElRef.current && elementRect) {
      nameTagElRef.current.style.transform = `translate(${elementRect.left * canvasViewPort.scale}px, ${elementRect.top * canvasViewPort.scale - 25}px)`;
      nameTagElRef.current.style.opacity = '1';
    }
    if (addSectionButtonRef.current && elementRect && selected) {
      addSectionButtonRef.current.style.top = `${(elementRect.top + elementRect.height) * canvasViewPort.scale}px`;
      addSectionButtonRef.current.style.left = `${(elementRect.left + elementRect.width / 2) * canvasViewPort.scale}px`;
    }
  }, [elementRect, selected, canvasViewPort.scale]);

  const bindEvents = useCallback(() => {
    if (!iframeRef.current) {
      return;
    }
    const iframeDocument = iframeRef.current.contentDocument;
    if (!iframeDocument) {
      return;
    }

    if (!selected && hoveredElementRef.current) {
      hoveredElementRef?.current?.addEventListener(
        'mouseleave',
        function (event: MouseEvent) {
          event.stopPropagation();
          // When related target is null, assume the mouse moved onto a UI element that was not inside the iFrame.
          // when moving the mouse from one element inside the iframe to another the relatedTarget is the element the mouse
          // moved to.
          if (event.relatedTarget !== null) {
            dispatch(unsetHoveredComponent());
          }
        },
      );
    }
  }, [dispatch, iframeRef, selected]);

  useEffect(() => {
    applyStyles();
  }, [elementRect, applyStyles]);

  useEffect(() => {
    if (elementId) {
      hoveredElementRef.current =
        iframeRef.current?.contentDocument?.querySelectorAll(
          `[data-xb-uuid="${elementId}"]`,
        )[0] as HTMLElement | null;
      if (hoveredElementRef.current?.dataset.xbComponentId === 'slot') {
        setNodeType('slot');
      } else {
        setNodeType('component');
      }
      applyStyles();
      bindEvents();
    }
  }, [elementId, applyStyles, bindEvents, iframeRef]);

  useEffect(() => {
    // If we haven't got the model yet, don't update the selectedComponent because it was probably
    // set by the user hitting a /component/:componentId url.
    if (_.isEmpty(model)) {
      return;
    }
    if (elementId) {
      if (!model[elementId]) {
        if (selected) {
          dispatch(unsetSelectedComponent());
        }
      }
    }
  }, [layout, model, dispatch, elementId, selected, applyStyles]);

  // When the elementId changes, hide the outline immediately to prevent a flicker
  // when moving from one component to the next quickly.
  useEffect(() => {
    if (outlineElRef.current) {
      outlineElRef.current.style.opacity = '0';
    }

    if (nameTagElRef.current) {
      nameTagElRef.current.style.opacity = '0';
    }
  }, [elementId]);

  if (elementId === undefined) {
    return null;
  }

  return (
    elementId &&
    nodeType && (
      <div
        style={{
          transform: `scale(${1 / canvasViewPort.scale})`,
          transformOrigin: '0 0',
        }}
      >
        <div
          ref={outlineElRef}
          className={clsx(styles.xbComponentOutline, {
            [styles.xbSlotOutline]: nodeType === 'slot',
            [styles.selected]: selected,
          })}
          data-xb-component-outline=""
        />
        <Grid columns="2" gap="1">
          <div ref={nameTagElRef} className={styles.xbNameTag}>
            <NameTag
              elementId={elementId}
              selected={selected}
              nodeType={nodeType}
            />
          </div>
          {selected && (
            <div
              ref={addSectionButtonRef}
              className={styles.xbAddSectionButton}
            >
              <AddButton elementId={elementId} />
            </div>
          )}
        </Grid>
      </div>
    )
  );
};

export default Outline;
