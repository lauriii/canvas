import { useEffect, useMemo, useRef, useState } from 'react';
import clsx from 'clsx';
import { useDraggable } from '@dnd-kit/core';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import ComponentContextMenu from '@/features/layout/preview/ComponentContextMenu';
import { ComponentNameTag } from '@/features/layout/preview/NameTag';
import { usePreviewDom } from '@/features/layout/preview/PreviewDomContext';
import { usePreviewGeometry } from '@/features/layout/preview/PreviewGeometryContext';
import ComponentDropZone from '@/features/layout/previewOverlay/ComponentDropZone';
import {
  ensureInlineEditingStyles,
  getActivePreviewIframe,
  useInlineEditableProps,
} from '@/features/layout/previewOverlay/inlineText/InlineTextEditing';
import {
  findMarkedPropNameAtPoint,
  getInlineEditingRejections,
  getTargetHostElement,
  getUnrenderedEmptyProps,
  humanizePropName,
  isDebugging,
  listMarkedPropTargets,
  targetHitRects,
  toIframePoint,
} from '@/features/layout/previewOverlay/inlineText/inlineTextEditing';
import {
  focusSidebarField,
  getSidebarLabel,
} from '@/features/layout/previewOverlay/inlineText/sidebarField';
import SlotOverlay from '@/features/layout/previewOverlay/SlotOverlay';
import {
  selectComponentIsSelected,
  selectDragging,
  selectEditorViewPortScale,
  selectIsComponentHovered,
  selectIsComponentUpdating,
  selectPreviewLoading,
  selectTextEditSession,
  setHoveredComponent,
  startTextEditSession,
  unsetHoveredComponent,
} from '@/features/ui/uiSlice';
import useComponentSelection from '@/hooks/useComponentSelection';
import useGetComponentName from '@/hooks/useGetComponentName';
import { isMarkerComponentType } from '@/services/pageVariants';

import type React from 'react';
import type { CanvasStackDirection } from '@drupal-canvas/preview-geometry';
import type {
  ComponentNode,
  RegionNode,
  SlotNode,
} from '@/features/layout/layoutModelSlice';

import styles from './PreviewOverlay.module.css';

export interface ComponentOverlayProps {
  component: ComponentNode;
  parentSlot?: SlotNode;
  parentRegion?: RegionNode;
  index: number;
  disableDrop?: boolean;
}

const ComponentOverlay: React.FC<ComponentOverlayProps> = (props) => {
  const {
    component,
    parentSlot,
    parentRegion,
    index,
    disableDrop = false,
  } = props;

  const componentsMap = usePreviewDom()?.componentsMap;
  const { geometryMap } = usePreviewGeometry();
  const componentGeometry = geometryMap.component[component.uuid];
  const parentGeometry = parentRegion?.id
    ? geometryMap.region[parentRegion.id]
    : parentSlot?.id
      ? geometryMap.slot[parentSlot.id]
      : undefined;
  const offsetLeft =
    componentGeometry && parentGeometry
      ? componentGeometry.rect.left - parentGeometry.rect.left
      : 0;
  const offsetTop =
    componentGeometry && parentGeometry
      ? componentGeometry.rect.top - parentGeometry.rect.top
      : 0;
  const isHovered = useAppSelector((state) => {
    return selectIsComponentHovered(state, component.uuid);
  });
  const isUpdating = useAppSelector((state) => {
    return selectIsComponentUpdating(state, component.uuid);
  });
  const editorViewPortScale = useAppSelector(selectEditorViewPortScale);
  const dispatch = useAppDispatch();
  const { setSelectedComponent, handleComponentSelection } =
    useComponentSelection();
  const { isDragging } = useAppSelector(selectDragging);
  const name = useGetComponentName(component);
  const {
    attributes,
    listeners,
    setNodeRef,
    isDragging: isComponentDragged,
  } = useDraggable({
    id: `${component.uuid}`,
    data: {
      origin: 'overlay',
      component: component,
      name: name,
      elementsInsideIframe: componentsMap?.[component.uuid]?.elements ?? [],
    },
  });

  const isSelected = useAppSelector((state) =>
    selectComponentIsSelected(state, component.uuid),
  );
  const editingSession = useAppSelector(selectTextEditSession);
  const isEditingText = editingSession?.componentUuid === component.uuid;
  const inlineEditable = useInlineEditableProps(component);
  // Stable across renders, unlike the object holding it.
  const listInlineEditable = inlineEditable.list;
  // The editable text under the pointer, in this overlay's coordinates.
  const [textHint, setTextHint] = useState<{
    top: number;
    left: number;
    width: number;
    height: number;
  } | null>(null);
  // Shown after clicking text that can only be edited in the sidebar.
  const [panelHint, setPanelHint] = useState<{
    label: string;
    top: number;
    left: number;
  } | null>(null);
  const pointer = useRef<{ x: number; y: number } | null>(null);
  const frame = useRef(0);
  useEffect(() => () => cancelAnimationFrame(frame.current), []);
  useEffect(() => {
    if (!panelHint) {
      return;
    }
    const timer = setTimeout(() => setPanelHint(null), 2500);
    return () => clearTimeout(timer);
  }, [panelHint]);
  useEffect(() => {
    if (!isSelected || editingSession) {
      setTextHint(null);
    }
  }, [editingSession, isSelected]);

  // While selected, editable text that is empty shows a placeholder, so it
  // can still be found and clicked, as long as the template renders its
  // element. Empty text whose element is not rendered gets an "Add" chip that
  // opens its sidebar field instead.
  // Marks go on the document being shown, so wait for a loading one.
  const previewLoading = useAppSelector(selectPreviewLoading);
  const [missingTexts, setMissingTexts] = useState<string[]>([]);
  useEffect(() => {
    const doc = getActivePreviewIframe()?.contentDocument;
    if (!isSelected || editingSession || previewLoading || !doc) {
      setMissingTexts((shown) => (shown.length ? [] : shown));
      return;
    }
    const marked = listInlineEditable()
      .props.map((prop) => {
        const element = getTargetHostElement(prop.target);
        if (!element || element.textContent?.trim()) {
          return null;
        }
        const label =
          getSidebarLabel(component.uuid, prop.propName) ??
          humanizePropName(prop.propName);
        element.setAttribute(
          'data-canvas-inline-placeholder',
          `Add ${label.toLowerCase()}`,
        );
        element.setAttribute('data-canvas-inline-empty-hint', '');
        return element;
      })
      .filter((element) => element !== null);
    if (marked.length) {
      ensureInlineEditingStyles(doc);
    }
    const missing = getUnrenderedEmptyProps(doc, component.uuid);
    setMissingTexts((shown) =>
      shown.join() === missing.join() ? shown : missing,
    );
    return () =>
      marked.forEach((element) => {
        element.removeAttribute('data-canvas-inline-empty-hint');
        element.removeAttribute('data-canvas-inline-placeholder');
      });
    // The preview document is replaced on updates; mark the new one.
  }, [
    component.uuid,
    editingSession,
    listInlineEditable,
    isSelected,
    previewLoading,
  ]);

  // With `localStorage['canvas.debugInlineEditing'] = '1'`, the selected
  // component outlines its editable text, and marked text that is not
  // editable, with the reason.
  const [debugBoxes, setDebugBoxes] = useState<
    Array<{
      propName: string;
      reason?: string;
      top: number;
      left: number;
      width: number;
      height: number;
    }>
  >([]);
  const overlayRef = useRef<HTMLDivElement>(null);
  useEffect(() => {
    const iframe = getActivePreviewIframe();
    const doc = iframe?.contentDocument;
    const overlay = overlayRef.current;
    if (
      !isSelected ||
      editingSession ||
      !isDebugging() ||
      !iframe ||
      !doc ||
      !overlay
    ) {
      setDebugBoxes((shown) => (shown.length ? [] : shown));
      return;
    }
    const editable = listInlineEditable().props;
    const rejected = getInlineEditingRejections(doc, component.uuid);
    const iframeRect = iframe.getBoundingClientRect();
    const scale = iframe.offsetWidth
      ? iframeRect.width / iframe.offsetWidth
      : 1;
    const bounds = overlay.getBoundingClientRect();
    const box = (rects: DOMRect[]) => {
      const top = Math.min(...rects.map((r) => r.top));
      const left = Math.min(...rects.map((r) => r.left));
      return {
        top: iframeRect.top + top * scale - bounds.top,
        left: iframeRect.left + left * scale - bounds.left,
        width: (Math.max(...rects.map((r) => r.right)) - left) * scale,
        height: (Math.max(...rects.map((r) => r.bottom)) - top) * scale,
      };
    };
    setDebugBoxes(
      [
        ...editable.map((prop) => ({
          propName: prop.propName,
          ...box(targetHitRects(prop.target)),
        })),
        ...listMarkedPropTargets(doc, component.uuid)
          .filter(({ propName }) => rejected[propName])
          .map(({ propName, target }) => ({
            propName,
            reason: rejected[propName],
            ...box(targetHitRects(target)),
          })),
      ].filter((entry) => Number.isFinite(entry.top)),
    );
  }, [
    component.uuid,
    componentGeometry,
    editingSession,
    listInlineEditable,
    isSelected,
  ]);

  function handleComponentClick(event: React.MouseEvent<HTMLElement>) {
    event.stopPropagation();
    // Clicking text of the selected component edits it in place, so a
    // double-click on any component goes straight to editing its text.
    if (isSelected && !event.metaKey && !event.shiftKey && event.detail > 0) {
      const hit = inlineEditable.propAt(event.clientX, event.clientY);
      if (hit) {
        dispatch(
          startTextEditSession({
            componentUuid: component.uuid,
            propName: hit.prop.propName,
            point: hit.point,
            // The second click of a double-click selects the word, as it
            // does in any text.
            selectWord: event.detail >= 2,
          }),
        );
        return;
      }
      // Text that cannot be edited in place, such as a prop linked to an
      // entity field: point the author to its field in the sidebar.
      const iframe = getActivePreviewIframe();
      const doc = iframe?.contentDocument;
      if (iframe && doc) {
        const point = toIframePoint(iframe, event.clientX, event.clientY);
        const propName = findMarkedPropNameAtPoint(
          doc,
          component.uuid,
          point.x,
          point.y,
        );
        if (propName && focusSidebarField(component.uuid, propName)) {
          const bounds = event.currentTarget.getBoundingClientRect();
          setPanelHint({
            label:
              getSidebarLabel(component.uuid, propName) ??
              humanizePropName(propName),
            top: event.clientY - bounds.top,
            left: event.clientX - bounds.left,
          });
          return;
        }
      }
    }
    handleComponentSelection(component.uuid, event.metaKey);
  }

  // Outlines editable text under the pointer of the selected component.
  // Measured once per frame at most.
  function handleMouseMove(event: React.MouseEvent<HTMLDivElement>) {
    if (!isSelected || isDragging || editingSession) {
      return;
    }
    event.stopPropagation();
    pointer.current = { x: event.clientX, y: event.clientY };
    const overlay = event.currentTarget;
    cancelAnimationFrame(frame.current);
    frame.current = requestAnimationFrame(() => {
      const hit = pointer.current
        ? inlineEditable.propAt(pointer.current.x, pointer.current.y)
        : null;
      if (!hit) {
        setTextHint(null);
        return;
      }
      // Outline exactly the area a click edits.
      const rects = targetHitRects(hit.prop.target);
      const rect = {
        top: Math.min(...rects.map((r) => r.top)),
        left: Math.min(...rects.map((r) => r.left)),
        width:
          Math.max(...rects.map((r) => r.right)) -
          Math.min(...rects.map((r) => r.left)),
        height:
          Math.max(...rects.map((r) => r.bottom)) -
          Math.min(...rects.map((r) => r.top)),
      };
      const iframe = getActivePreviewIframe();
      const iframeRect = iframe?.getBoundingClientRect();
      const bounds = overlay.getBoundingClientRect();
      if (!iframe || !iframeRect) {
        return;
      }
      const scale = iframe.offsetWidth
        ? iframeRect.width / iframe.offsetWidth
        : 1;
      const next = {
        top: Math.round(iframeRect.top + rect.top * scale - bounds.top),
        left: Math.round(iframeRect.left + rect.left * scale - bounds.left),
        width: Math.round(rect.width * scale),
        height: Math.round(rect.height * scale),
      };
      setTextHint((previous) =>
        previous &&
        previous.top === next.top &&
        previous.left === next.left &&
        previous.width === next.width &&
        previous.height === next.height
          ? previous
          : next,
      );
    });
  }

  function handleItemMouseOver(event: React.MouseEvent<HTMLDivElement>) {
    event.stopPropagation();
    if (!isDragging) {
      dispatch(setHoveredComponent(component.uuid));
    }
  }

  function handleItemMouseOut(event: React.MouseEvent<HTMLDivElement>) {
    event.stopPropagation();
    dispatch(unsetHoveredComponent());
    if (!event.currentTarget.contains(event.relatedTarget as Node | null)) {
      cancelAnimationFrame(frame.current);
      pointer.current = null;
      setTextHint(null);
    }
  }

  function handleKeyDown(event: React.KeyboardEvent<HTMLDivElement>) {
    if (event.code === 'Enter' || (event.code === 'Space' && !event.repeat)) {
      event.preventDefault(); // Prevents scrolling when space is pressed
      event.stopPropagation(); // Prevents key firing on a parent component
      // Enter on the selected component edits its first text prop in place.
      const [firstEditable] =
        isSelected && event.code === 'Enter' ? inlineEditable.list().props : [];
      if (firstEditable) {
        dispatch(
          startTextEditSession({
            componentUuid: component.uuid,
            propName: firstEditable.propName,
          }),
        );
        return;
      }
      setSelectedComponent(component.uuid);
    }
  }

  const style: React.CSSProperties = useMemo(
    () => ({
      height: (componentGeometry?.rect.height ?? 0) * editorViewPortScale,
      width: (componentGeometry?.rect.width ?? 0) * editorViewPortScale,
      top: offsetTop * editorViewPortScale,
      left: offsetLeft * editorViewPortScale,
    }),
    [
      componentGeometry?.rect.height,
      componentGeometry?.rect.width,
      editorViewPortScale,
      offsetLeft,
      offsetTop,
    ],
  );

  let stackDirection: CanvasStackDirection = 'vertical';
  if (parentSlot) {
    stackDirection =
      geometryMap.slot[parentSlot.id]?.stackDirection || 'vertical';
  }

  const [componentType] = component.type.split('@');

  if (!componentGeometry || !parentGeometry) {
    return null;
  }

  return (
    <div
      aria-label={`${name}`}
      tabIndex={0}
      onMouseOver={handleItemMouseOver}
      onMouseOut={handleItemMouseOut}
      onClick={handleComponentClick}
      onMouseMove={handleMouseMove}
      onKeyDown={handleKeyDown}
      ref={overlayRef}
      data-canvas-selected={isSelected}
      data-canvas-drop-item={component.uuid}
      className={clsx('componentOverlay', styles.componentOverlay, {
        [styles.selected]: isSelected,
        // While text is edited, other outlines would crowd it.
        [styles.hovered]: isHovered && !editingSession,
        [styles.dragging]: isComponentDragged,
        [styles.updating]: isUpdating,
        [styles.editingText]: isEditingText,
        [styles.inlineEditable]: !!textHint,
      })}
      style={style}
    >
      <button className="visually-hidden" onClick={handleComponentClick}>
        Select component
      </button>

      <ComponentContextMenu component={component}>
        <div
          aria-label={`Draggable component ${name}`}
          ref={setNodeRef}
          {...listeners}
          {...attributes}
          className={clsx('canvas--sortable-item', styles.sortableItem)}
          data-canvas-component-id={componentType}
          data-canvas-uuid={component.uuid}
          data-canvas-type={component.nodeType}
          data-canvas-overlay="true"
        />
      </ComponentContextMenu>
      {missingTexts.length > 0 && (
        <div className={styles.inlineAddTexts}>
          {missingTexts.map((propName) => {
            const label =
              getSidebarLabel(component.uuid, propName) ??
              humanizePropName(propName);
            return (
              <button
                key={propName}
                type="button"
                className={styles.inlineAddText}
                onClick={(event) => {
                  event.stopPropagation();
                  focusSidebarField(component.uuid, propName);
                }}
              >
                + Add {label.toLowerCase()}
              </button>
            );
          })}
        </div>
      )}
      {debugBoxes.map((entry) => (
        <div
          key={`${entry.propName}-${entry.reason ?? 'editable'}`}
          className={
            entry.reason
              ? styles.inlineDebugRejected
              : styles.inlineDebugEditable
          }
          style={{
            top: entry.top,
            left: entry.left,
            width: entry.width,
            height: entry.height,
          }}
          title={
            entry.reason ? `${entry.propName} ${entry.reason}` : entry.propName
          }
        >
          <span>
            {entry.propName}
            {entry.reason ? `: ${entry.reason}` : ''}
          </span>
        </div>
      ))}
      {textHint && (
        <div
          className={styles.inlineTextHint}
          style={textHint}
          data-canvas-inline-text-hint=""
        >
          <span className={styles.inlineTextHintLabel}>Click to edit</span>
        </div>
      )}
      {panelHint && (
        <div
          className={styles.inlinePanelHint}
          style={{ top: panelHint.top, left: panelHint.left }}
          role="status"
        >
          Edit {panelHint.label} in the panel
        </div>
      )}
      {((isHovered && !editingSession) || isSelected) && !isEditingText && (
        <div className={clsx(styles.canvasNameTag)}>
          <ComponentNameTag
            name={name}
            id={component.uuid}
            nodeType={
              isMarkerComponentType(component.type)
                ? 'marker'
                : component.nodeType
            }
          />
        </div>
      )}
      {component.slots.map((slot: SlotNode) => (
        <SlotOverlay
          key={slot.name}
          parentComponent={component}
          slot={slot}
          disableDrop={disableDrop || isComponentDragged}
        />
      ))}

      {!isComponentDragged && !disableDrop && !isUpdating && !isEditingText && (
        <>
          {index === 0 && (
            <ComponentDropZone
              component={component}
              position={stackDirection.startsWith('v') ? 'top' : 'left'}
              parentSlot={parentSlot}
            />
          )}
          <ComponentDropZone
            component={component}
            position={stackDirection.startsWith('v') ? 'bottom' : 'right'}
            parentSlot={parentSlot}
          />
        </>
      )}
    </div>
  );
};

export default ComponentOverlay;
