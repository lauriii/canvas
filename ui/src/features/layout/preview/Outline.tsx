import styles from './Outline.module.css';
import type React from 'react';
import { useRef, useEffect, useCallback, useState } from 'react';
import { deleteNode } from '../layoutSlice';
import { useAppDispatch } from '../../../app/hooks';
import { Button, Grid } from '@radix-ui/themes';
import classNames from 'classnames';
import { setHoveredComponent, setSelectedComponent } from '../../ui/uiSlice';

interface OutlineProps {
  elementId: string | undefined; // the data-xb-uuid value of the dom element that was hovered.
  selected: boolean;
}

const Outline: React.FC<OutlineProps> = (props) => {
  const { elementId, selected } = props;
  const hoveredElementRef = useRef<HTMLElement | null>(null);
  const outlineElRef = useRef<HTMLDivElement | null>(null);
  const iframeElRef = useRef<HTMLIFrameElement | null>(null);
  const toolbarElRef = useRef<HTMLDivElement | null>(null);
  const [type, setType] = useState<'component' | 'slot'>('component'); //
  const dispatch = useAppDispatch();

  useEffect(() => {
    const iframe = document.getElementById(
      'preview',
    ) as HTMLIFrameElement | null;
    iframeElRef.current = iframe;
  }, []);

  useEffect(() => {
    if (elementId) {
      hoveredElementRef.current =
        iframeElRef.current?.contentDocument?.querySelectorAll(
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
  }, [elementId]);

  const handleFrameScroll = () => {
    if (!iframeElRef.current || !hoveredElementRef.current) {
      return;
    }
    applyStyles();
  };

  const bindEvents = () => {
    if (!iframeElRef.current) {
      return;
    }
    const iframeDocument = iframeElRef.current.contentDocument;
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
  };

  const applyStyles = () => {
    const elRect = hoveredElementRef.current?.getBoundingClientRect();
    const iframeRect = iframeElRef.current?.getBoundingClientRect();

    if (outlineElRef.current && elRect && iframeRect) {
      outlineElRef.current.style.transform = `translate(${elRect.left + iframeRect.x}px, ${elRect.top + iframeRect.y}px)`;
      outlineElRef.current.style.width = `${elRect.width}px`;
      outlineElRef.current.style.height = `${elRect.height}px`;
    }

    if (toolbarElRef.current && elRect && iframeRect) {
      toolbarElRef.current.style.transform = `translate(${elRect.left + iframeRect.x}px, ${elRect.top + iframeRect.y}px)`;
    }
  };

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

  if (elementId === undefined) {
    return null;
  }

  return (
    elementId && (
      <>
        <div
          ref={outlineElRef}
          className={classNames(styles.xbComponentOutline, {
            [styles.xbSlotOutline]: type === 'slot',
            [styles.selected]: selected,
          })}
        />
        <Grid
          ref={toolbarElRef}
          columns="2"
          gap="1"
          className={styles.xbComponentToolbar}
        >
          {type === 'component' && (
            <>
              <Button size="1" type="button" onClick={handleSelectClick}>
                Select
              </Button>
              <Button size="1" type="button" onClick={handleDeleteClick}>
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
