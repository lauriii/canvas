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
import { ContextMenu } from '@radix-ui/themes';
import clsx from 'clsx';
import {
  unsetHoveredComponent,
  setSelectedComponent,
  unsetSelectedComponent,
} from '@/features/ui/uiSlice';
import useSyncElementSize from '@/hooks/useSyncElementSize';
import NameTag from '@/features/layout/preview/NameTag';
import AddButton from '@/features/layout/preview/AddButton';

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
  const nameTagElRef = useRef<HTMLDivElement | null>(null);
  const addSectionButtonRef = useRef<HTMLDivElement | null>(null);
  const [nodeType, setNodeType] = useState<'component' | 'slot'>('component');
  const dispatch = useAppDispatch();
  const elementRect = useSyncElementSize(iframeRef, elementId);

  const applyStyles = useCallback(() => {
    if (outlineElRef.current && elementRect) {
      outlineElRef.current.style.transform = `translate(${elementRect.left}px, ${elementRect.top}px)`;
      outlineElRef.current.style.width = `${elementRect.width}px`;
      outlineElRef.current.style.height = `${elementRect.height}px`;
      outlineElRef.current.style.opacity = '1';
    }

    if (nameTagElRef.current && elementRect) {
      nameTagElRef.current.style.transform = `translate(${elementRect.left}px, ${elementRect.top - 25}px)`;
      nameTagElRef.current.style.opacity = '1';
    }
    if (addSectionButtonRef.current && elementRect && selected) {
      addSectionButtonRef.current.style.top = `${elementRect.top + elementRect.height}px`;
      addSectionButtonRef.current.style.left = `${elementRect.left + elementRect.width / 2}px`;
    }
  }, [elementRect, selected]);

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
          dispatch(unsetSelectedComponent());
        } else {
          dispatch(unsetHoveredComponent());
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
        {nodeType !== 'slot' && (
          <ContextMenu.Root>
            <ContextMenu.Trigger>
              <div ref={nameTagElRef} className={styles.xbNameTag}>
                <NameTag elementId={elementId} selected={selected} />
              </div>
            </ContextMenu.Trigger>
            {selected && (
              <div
                ref={addSectionButtonRef}
                className={styles.xbAddSectionButton}
              >
                <AddButton elementId={elementId} />
              </div>
            )}
            <ContextMenu.Content>
              <ContextMenu.Item shortcut="⌘ E" onClick={handleSelectClick}>
                Edit
              </ContextMenu.Item>
              <ContextMenu.Item shortcut="⌘ D" onClick={handleDuplicateClick}>
                Duplicate
              </ContextMenu.Item>
              <ContextMenu.Separator />

              <ContextMenu.Sub>
                <ContextMenu.SubTrigger>Move</ContextMenu.SubTrigger>
                <ContextMenu.SubContent>
                  <ContextMenu.Item onClick={handleMoveUpClick}>
                    Move up
                  </ContextMenu.Item>
                  <ContextMenu.Item onClick={handleMoveDownClick}>
                    Move down
                  </ContextMenu.Item>

                  <ContextMenu.Separator />
                  <ContextMenu.Item onClick={() => alert('Todo')}>
                    Move into
                  </ContextMenu.Item>
                </ContextMenu.SubContent>
              </ContextMenu.Sub>
              <ContextMenu.Separator />
              <ContextMenu.Item
                // shortcut="⌘ ⌫"
                color="red"
                onClick={handleDeleteClick}
              >
                Delete
              </ContextMenu.Item>
            </ContextMenu.Content>
          </ContextMenu.Root>
        )}
      </>
    )
  );
};

export default Outline;
