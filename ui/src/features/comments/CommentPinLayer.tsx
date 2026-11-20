import { useState } from 'react';
import clsx from 'clsx';
import { PlusIcon } from '@radix-ui/react-icons';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import { getAvatarInitialColor } from '@/components/review/utils';
import CommentDraftComposer from '@/features/comments/CommentDraftComposer';
import {
  selectActiveThreadId,
  selectCommentFilter,
  selectCommentModeActive,
  selectCommentsPanelOpen,
  setActiveThread,
  setCommentFilter,
  setCommentMode,
  setCommentsPanelOpen,
} from '@/features/comments/commentsSlice';
import {
  filterThreads,
  getThreadLabel,
} from '@/features/comments/commentThreadUtils';
import useCommentSurface from '@/features/comments/useCommentSurface';
import { usePreviewGeometry } from '@/features/layout/preview/PreviewGeometryContext';
import { selectEditorViewPortScale } from '@/features/ui/uiSlice';
import { getCommentAuthor, useGetCommentsQuery } from '@/services/comments';
import { hasPermission } from '@/utils/permissions';

import type { CommentDraft } from '@/features/comments/CommentDraftComposer';
import type { CommentThread } from '@/services/comments';

import styles from './CommentPinLayer.module.css';

/**
 * Finds the component instance under a point in the preview.
 *
 * Component rectangles nest, so the smallest one containing the point is the
 * one the user meant: clicking a heading inside a section should comment on
 * the heading, not on the section wrapping it.
 *
 * @param geometryMap - The measured preview geometry.
 * @param x - The point's x coordinate, in unscaled preview pixels.
 * @param y - The point's y coordinate, in unscaled preview pixels.
 * @returns The component UUID, or null when the point is over no component.
 */
export const findComponentAtPoint = (
  geometryMap: ReturnType<typeof usePreviewGeometry>['geometryMap'],
  x: number,
  y: number,
): string | null => {
  let smallestUuid: string | null = null;
  let smallestArea = Infinity;
  Object.entries(geometryMap.component).forEach(([uuid, geometry]) => {
    const { top, right, bottom, left } = geometry.rect;
    if (x < left || x > right || y < top || y > bottom) {
      return;
    }
    const area = (right - left) * (bottom - top);
    if (area < smallestArea) {
      smallestArea = area;
      smallestUuid = uuid;
    }
  });
  return smallestUuid;
};

/**
 * The letter a pin carries: the initial of the thread's author.
 *
 * @param displayName - The thread author's name.
 * @returns The initial to draw, or a placeholder for an unnamed author.
 */
export const getAuthorInitial = (displayName: string): string =>
  displayName.trim().charAt(0).toUpperCase() || '?';

/**
 * Places a pin at the point in its component that the comment was left at.
 *
 * The stored offset is a fraction of the component's box, so the pin lands on
 * the same part of the component at every viewport width. A thread with no
 * recorded point is pinned to the component's top-left corner.
 *
 * @param rect - The component's measured box, in unscaled preview pixels.
 * @param thread - The thread being pinned.
 * @param scale - The editor's viewport scale.
 * @returns The `top` and `left` to render the pin at.
 */
export const pinPosition = (
  rect: { top: number; left: number; width: number; height: number },
  thread: Pick<CommentThread, 'offsetX' | 'offsetY'>,
  scale: number,
): { top: string; left: string } => {
  const { offsetX, offsetY } = thread;
  const x = rect.left + (offsetX === null ? 0 : offsetX * rect.width);
  const y = rect.top + (offsetY === null ? 0 : offsetY * rect.height);
  return { top: `${y * scale}px`, left: `${x * scale}px` };
};

/**
 * Renders one clickable pin per component-anchored comment thread.
 *
 * Pins are drawn whether or not the comments panel is open. A thread on the
 * surface itself, or on a component that is not currently measured, gets no
 * pin and is listed in the comments panel only.
 */
const CommentPinLayer = () => {
  const dispatch = useAppDispatch();
  const { geometryMap } = usePreviewGeometry();
  const editorViewPortScale = useAppSelector(selectEditorViewPortScale);
  const panelOpen = useAppSelector(selectCommentsPanelOpen);
  const commentModeActive = useAppSelector(selectCommentModeActive);
  const activeThreadId = useAppSelector(selectActiveThreadId);
  const filter = useAppSelector(selectCommentFilter);
  const { surfaceType, surfaceId, hasSurface } = useCommentSurface();
  const [draft, setDraft] = useState<CommentDraft | null>(null);
  // The component a comment placed now would be attached to.
  const [hoveredUuid, setHoveredUuid] = useState<string | null>(null);
  const canViewComments = hasPermission('viewComments');

  const toPreviewPoint = (event: React.MouseEvent<HTMLDivElement>) => {
    const bounds = event.currentTarget.getBoundingClientRect();
    // Geometry is in unscaled preview pixels.
    return {
      x: (event.clientX - bounds.left) / editorViewPortScale,
      y: (event.clientY - bounds.top) / editorViewPortScale,
    };
  };

  const handleLayerClick = (event: React.MouseEvent<HTMLDivElement>) => {
    const { x, y } = toPreviewPoint(event);
    const componentUuid = findComponentAtPoint(geometryMap, x, y);
    if (!componentUuid) {
      // Clicking empty canvas abandons the draft.
      setDraft(null);
      return;
    }
    const { rect } = geometryMap.component[componentUuid];
    setDraft({
      componentUuid,
      offsetX: rect.width === 0 ? 0 : (x - rect.left) / rect.width,
      offsetY: rect.height === 0 ? 0 : (y - rect.top) / rect.height,
      top: y * editorViewPortScale,
      left: x * editorViewPortScale,
    });
  };

  const closeDraft = () => setDraft(null);
  const finishDraft = () => {
    setDraft(null);
    dispatch(setCommentMode(false));
    // A new thread is open, so the panel has to be on the list it lands in.
    dispatch(setCommentFilter('open'));
    dispatch(setCommentsPanelOpen(true));
  };

  // The pins follow the panel's filter only while the panel is on screen.
  const effectiveFilter = panelOpen ? filter : 'open';
  // @todo Poll adaptively (faster while the panel is open, slower otherwise) instead of relying on tag invalidation alone, reusing the intervals in ui/src/features/notifications/constants.ts.
  const { data } = useGetCommentsQuery(
    { surfaceType, surfaceId },
    { skip: !hasSurface || !canViewComments },
  );

  // A skipped query can still hand back the last surface's threads, so the
  // surface is checked here as well as in `skip`.
  if (!canViewComments || !hasSurface || !data) {
    return null;
  }

  const threads = filterThreads(data.threads, effectiveFilter);
  const ownColor = getAvatarInitialColor(getCommentAuthor().uid);
  // The component a comment being placed or written belongs to is outlined.
  const targetUuid =
    draft?.componentUuid ?? (commentModeActive ? hoveredUuid : null);
  const targetRect = targetUuid
    ? geometryMap.component[targetUuid]?.rect
    : undefined;

  return (
    <div
      className={clsx(styles.pinLayer, {
        [styles.placing]: commentModeActive,
      })}
      data-testid="canvas-comment-pin-layer"
      data-comment-mode={commentModeActive ? 'true' : 'false'}
      onClick={commentModeActive ? handleLayerClick : undefined}
      onMouseMove={
        commentModeActive
          ? (event) => {
              const { x, y } = toPreviewPoint(event);
              setHoveredUuid(findComponentAtPoint(geometryMap, x, y));
              // The pointer pin is positioned on the element, not through
              // state, so a mouse move does not render every pin again. It is
              // hidden over a pin or the composer.
              const layer = event.currentTarget;
              layer.style.setProperty(
                '--pointer-x',
                `${x * editorViewPortScale}px`,
              );
              layer.style.setProperty(
                '--pointer-y',
                `${y * editorViewPortScale}px`,
              );
              layer.toggleAttribute('data-pointer-pin', event.target === layer);
            }
          : undefined
      }
      onMouseLeave={(event) => {
        setHoveredUuid(null);
        event.currentTarget.removeAttribute('data-pointer-pin');
      }}
    >
      {targetRect && (
        <div
          className={styles.target}
          data-testid="canvas-comment-target-outline"
          style={{
            top: `${targetRect.top * editorViewPortScale}px`,
            left: `${targetRect.left * editorViewPortScale}px`,
            width: `${targetRect.width * editorViewPortScale}px`,
            height: `${targetRect.height * editorViewPortScale}px`,
          }}
        />
      )}
      {threads.map((thread) => {
        if (!thread.componentUuid) {
          return null;
        }
        const geometry = geometryMap.component[thread.componentUuid];
        if (!geometry) {
          return null;
        }
        return (
          <button
            type="button"
            key={thread.id}
            className={clsx(styles.pin, {
              [styles.active]: thread.id === activeThreadId,
              [styles.resolved]: thread.resolved,
            })}
            data-accent-color={getAvatarInitialColor(thread.author.uid)}
            style={pinPosition(geometry.rect, thread, editorViewPortScale)}
            aria-label={getThreadLabel(thread)}
            data-testid="canvas-comment-pin"
            data-comment-thread-id={thread.id}
            onClick={(event) => {
              event.stopPropagation();
              dispatch(setActiveThread(thread.id));
              // The panel opens on the list this thread is in.
              dispatch(setCommentFilter(thread.resolved ? 'resolved' : 'open'));
              dispatch(setCommentsPanelOpen(true));
            }}
          >
            {getAuthorInitial(thread.author.displayName)}
          </button>
        );
      })}
      {commentModeActive && (
        // In comment mode the pointer is the pin about to be placed, held by
        // the corner that lands on the clicked point.
        <span
          className={clsx(styles.pin, styles.pointerPin)}
          data-accent-color={ownColor}
          data-testid="canvas-comment-pointer-pin"
          aria-hidden="true"
        >
          <PlusIcon width="12" height="12" />
        </span>
      )}
      {draft && (
        <>
          {/* The pin the comment will become, where it will be. */}
          <span
            className={styles.pin}
            data-accent-color={ownColor}
            style={{ top: `${draft.top}px`, left: `${draft.left}px` }}
            data-testid="canvas-comment-draft-pin"
            aria-hidden="true"
          >
            {getAuthorInitial(getCommentAuthor().displayName)}
          </span>
          <CommentDraftComposer
            draft={draft}
            surfaceType={surfaceType}
            surfaceId={surfaceId}
            onCancel={closeDraft}
            onPosted={finishDraft}
            onFailed={setDraft}
          />
        </>
      )}
    </div>
  );
};

export default CommentPinLayer;
