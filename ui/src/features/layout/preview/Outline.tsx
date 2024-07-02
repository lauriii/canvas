import styles from './Outline.module.css';
import type React from 'react';
import { useRef, useEffect, useState, useCallback } from 'react';
import {
  deleteNode,
  selectLayout,
  selectModel,
} from '@/features/layout/layoutModelSlice';
import { useAppDispatch, useAppSelector } from '@/app/hooks';
import { Button, Grid } from '@radix-ui/themes';
import clsx from 'clsx';
import {
  setHoveredComponent,
  setSelectedComponent,
} from '@/features/ui/uiSlice';
import useSyncElementSize from '@/hooks/useSyncElementSize';

interface OutlineProps {
  elementId: string | undefined; // the data-xb-uuid value of the dom element that was hovered.
  selected: boolean;
  iframeRef: React.RefObject<HTMLIFrameElement>;
}

const Outline: React.FC<OutlineProps> = (props) => {
  const { elementId, selected, iframeRef } = props;
  const layout = useAppSelector(selectLayout);
  const model = useAppSelector(selectModel);
  const hoveredElementRef = useRef<HTMLElement | null>(null);
  const outlineElRef = useRef<HTMLDivElement | null>(null);
  // const iframeElRef = useRef<HTMLIFrameElement | null>(null);
  const toolbarElRef = useRef<HTMLDivElement | null>(null);
  const [type, setType] = useState<'component' | 'slot'>('component'); //
  const dispatch = useAppDispatch();
  const elementRect = useSyncElementSize(iframeRef, elementId);

  const applyStyles = useCallback(() => {
    if (outlineElRef.current && elementRect) {
      outlineElRef.current.style.transform = `translate(${elementRect.left}px, ${elementRect.top}px)`;
      outlineElRef.current.style.width = `${elementRect.width}px`;
      outlineElRef.current.style.height = `${elementRect.height}px`;
    }

    if (toolbarElRef.current && elementRect) {
      toolbarElRef.current.style.transform = `translate(${elementRect.left}px, ${elementRect.top}px)`;
    }
  }, [elementRect]);

  const handleFrameScroll = useCallback(() => {
    if (!iframeRef.current || !hoveredElementRef.current) {
      return;
    }
    applyStyles();
  }, [applyStyles, iframeRef]);

  const bindEvents = useCallback(() => {
    if (!iframeRef.current) {
      return;
    }
    const iframeDocument = iframeRef.current.contentDocument;
    if (!iframeDocument) {
      return;
    }

    if (!selected && hoveredElementRef.current !== null) {
      hoveredElementRef.current.addEventListener(
        'mouseleave',
        function (event: MouseEvent) {
          event.stopPropagation();
          // When related target is null, assume the mouse moved onto a UI element that was not inside the iFrame.
          // when moving the mouse from one element inside the iframe to another the relatedTarget is the element the mouse
          // moved to.
          if (event.relatedTarget !== null) {
            dispatch(setHoveredComponent(undefined));
          }
        },
      );
    }

    // Attach the scroll event listener to the iframe's content window
    iframeDocument.addEventListener('scroll', handleFrameScroll);
  }, [dispatch, handleFrameScroll, iframeRef, selected]);

  function handleDeleteClick() {
    if (elementId) {
      dispatch(deleteNode(elementId));
    }
  }

  function handleSelectClick() {
    if (elementId) {
      dispatch(setSelectedComponent(elementId));
    }
  }

  useEffect(() => {
    applyStyles();
  }, [elementRect, applyStyles]);

  useEffect(() => {
    if (elementId) {
      hoveredElementRef.current =
        iframeRef.current?.contentDocument?.querySelectorAll(
          `[data-xb-uuid="${elementId}"]`,
        )[0] as HTMLElement | null;
      if (hoveredElementRef.current?.dataset.xbType === 'slot') {
        setType('slot');
      } else {
        setType('component');
      }
      applyStyles();
      bindEvents();
    }
  }, [elementId, applyStyles, bindEvents, iframeRef]);

  useEffect(() => {
    if (elementId) {
      if (!model[elementId]) {
        if (selected) {
          dispatch(setSelectedComponent());
        } else {
          dispatch(setHoveredComponent());
        }
      }
    }
  }, [layout, model, dispatch, elementId, selected, applyStyles]);

  if (elementId === undefined) {
    return null;
  }

  return (
    elementId && (
      <>
        <div
          ref={outlineElRef}
          className={clsx(styles.xbComponentOutline, {
            [styles.xbSlotOutline]: type === 'slot',
            [styles.selected]: selected,
          })}
          data-xb-component-outline=""
        />
        <Grid
          ref={toolbarElRef}
          columns="2"
          gap="1"
          className={styles.xbComponentToolbar}
        >
          {type === 'component' && (
            <>
              <Button
                data-xb-component-outline-button=""
                size="1"
                type="button"
                onClick={handleSelectClick}
              >
                Select
              </Button>
              <Button
                data-xb-component-outline-button=""
                size="1"
                type="button"
                onClick={handleDeleteClick}
              >
                Delete
              </Button>
            </>
          )}
        </Grid>
      </>
    )
  );
};

export default Outline;
