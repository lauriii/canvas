import styles from './Outline.module.css';
import type React from 'react';
import { useRef, useEffect, useState, useCallback } from 'react';
import { deleteNode } from '@/features/layout/layoutModelSlice';
import { useAppDispatch } from '@/app/hooks';
import { Button, Grid } from '@radix-ui/themes';
import clsx from 'clsx';
import {
  setHoveredComponent,
  setSelectedComponent,
} from '@/features/ui/uiSlice';

interface OutlineProps {
  elementId: string | undefined; // the data-xb-uuid value of the dom element that was hovered.
  selected: boolean;
  iframeRef: React.RefObject<HTMLIFrameElement>;
}

const Outline: React.FC<OutlineProps> = (props) => {
  const { elementId, selected, iframeRef } = props;
  const hoveredElementRef = useRef<HTMLElement | null>(null);
  const outlineElRef = useRef<HTMLDivElement | null>(null);
  // const iframeElRef = useRef<HTMLIFrameElement | null>(null);
  const toolbarElRef = useRef<HTMLDivElement | null>(null);
  const [type, setType] = useState<'component' | 'slot'>('component'); //
  const dispatch = useAppDispatch();

  const applyStyles = useCallback(() => {
    const elRect = hoveredElementRef.current?.getBoundingClientRect();
    const iframeRect = iframeRef.current?.getBoundingClientRect();

    if (outlineElRef.current && elRect && iframeRect) {
      outlineElRef.current.style.transform = `translate(${elRect.left}px, ${elRect.top}px)`;
      outlineElRef.current.style.width = `${elRect.width}px`;
      outlineElRef.current.style.height = `${elRect.height}px`;
    }

    if (toolbarElRef.current && elRect && iframeRect) {
      toolbarElRef.current.style.transform = `translate(${elRect.left}px, ${elRect.top}px)`;
    }
  }, [iframeRef]);

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
          data-xb-component-outline=''
        />
        <Grid
          ref={toolbarElRef}
          columns="2"
          gap="1"
          className={styles.xbComponentToolbar}
        >
          {type === 'component' && (
            <>
              <Button data-xb-component-outline-button='' size="1" type="button" onClick={handleSelectClick}>
                Select
              </Button>
              <Button data-xb-component-outline-button='' size="1" type="button" onClick={handleDeleteClick}>
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
