import styles from './Outline.module.css';
import type React from 'react';
import { useRef, useEffect, useState, useCallback } from 'react';
import {
  deleteNode,
  selectLayout,
  selectModel,
  shiftNode,
  duplicateNode,
} from '@/features/layout/layoutModelSlice';
import { useAppDispatch, useAppSelector } from '@/app/hooks';
import { Button, Grid, DropdownMenu } from '@radix-ui/themes';
import clsx from 'clsx';
import {
  setHoveredComponent,
  setSelectedComponent,
} from '@/features/ui/uiSlice';
import useSyncElementSize from '@/hooks/useSyncElementSize';
import { HamburgerMenuIcon } from '@radix-ui/react-icons';

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
  const [nodeType, setNodeType] = useState<'component' | 'slot'>('component'); //
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

    if (!selected && hoveredElementRef.current) {
      hoveredElementRef?.current?.addEventListener(
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
  function handleDuplicateClick() {
    if (elementId) {
      dispatch(duplicateNode({ uuid: elementId }));
    }
  }

  function handleMoveUpClick() {
    dispatch(shiftNode({ uuid: elementId, direction: 'up' }));
  }

  function handleMoveDownClick() {
    dispatch(shiftNode({ uuid: elementId, direction: 'down' }));
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
        setNodeType('slot');
      } else {
        setNodeType('component');
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
            [styles.xbSlotOutline]: nodeType === 'slot',
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
          {nodeType === 'component' && !selected && (
            <>
              <DropdownMenu.Root>
                <DropdownMenu.Trigger>
                  <Button size="1" radius="none">
                    <HamburgerMenuIcon />
                  </Button>
                </DropdownMenu.Trigger>
                <DropdownMenu.Content>
                  <DropdownMenu.Item shortcut="⌘ E" onClick={handleSelectClick}>
                    Edit
                  </DropdownMenu.Item>
                  <DropdownMenu.Item
                    shortcut="⌘ D"
                    onClick={handleDuplicateClick}
                  >
                    Duplicate
                  </DropdownMenu.Item>
                  <DropdownMenu.Separator />

                  <DropdownMenu.Sub>
                    <DropdownMenu.SubTrigger>Move</DropdownMenu.SubTrigger>
                    <DropdownMenu.SubContent>
                      <DropdownMenu.Item onClick={handleMoveUpClick}>
                        Move up
                      </DropdownMenu.Item>
                      <DropdownMenu.Item onClick={handleMoveDownClick}>
                        Move down
                      </DropdownMenu.Item>

                      <DropdownMenu.Separator />
                      <DropdownMenu.Item onClick={() => alert('Todo')}>
                        Move into
                      </DropdownMenu.Item>
                    </DropdownMenu.SubContent>
                  </DropdownMenu.Sub>
                  <DropdownMenu.Separator />
                  <DropdownMenu.Item
                    // shortcut="⌘ ⌫"
                    color="red"
                    onClick={handleDeleteClick}
                  >
                    Delete
                  </DropdownMenu.Item>
                </DropdownMenu.Content>
              </DropdownMenu.Root>
            </>
          )}
        </Grid>
      </>
    )
  );
};

export default Outline;
