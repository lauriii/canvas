import { useCallback, useState } from 'react';
import clsx from 'clsx';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import CommentDraftComposer from '@/features/comments/CommentDraftComposer';
import {
  selectActiveThreadId,
  selectCommentFilter,
  selectCommentModeActive,
  selectCommentsPanelOpen,
  setActiveThread,
  setCommentMode,
  setCommentsPanelOpen,
} from '@/features/comments/commentsSlice';
import {
  filterThreads,
  getReplyCount,
  getThreadLabel,
} from '@/features/comments/commentThreadUtils';
import useCommentSurface from '@/features/comments/useCommentSurface';
import { usePreviewGeometry } from '@/features/layout/preview/PreviewGeometryContext';
import { selectEditorViewPortScale } from '@/features/ui/uiSlice';
import { useGetCommentsQuery } from '@/services/comments';
import { hasPermission } from '@/utils/permissions';

import type { CommentDraft } from '@/features/comments/CommentDraftComposer';

import styles from './CommentPinLayer.module.css';

export const COMMENTS_PANEL_ID = 'comments';

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
 * Renders one clickable pin per component-anchored comment thread.
 *
 * Pins are drawn for as long as the surface is open, whether or not the panel
 * is: seeing that something has been commented on is the whole point of a pin,
 * and it cannot do that job from behind a closed panel.
 *
 * Surface-level threads (`componentUuid === null`) and threads anchored to a
 * component that is not currently measured have no position on the canvas, so
 * they get no pin. They remain listed in the comments panel, which is the
 * complete, accessible equivalent of this layer.
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
  // Pins used to be reachable only through the panel, which the permission
  // already gated. Drawn unconditionally they need their own check, or a user
  // without it would fetch comments and be refused.
  const canViewComments = hasPermission('viewComments');

  // In comment mode the layer swallows canvas clicks and turns the one the
  // user makes into an anchor, instead of letting it select a component.
  const handleLayerClick = useCallback(
    (event: React.MouseEvent<HTMLDivElement>) => {
      const bounds = event.currentTarget.getBoundingClientRect();
      // Pins are positioned in unscaled preview pixels multiplied by the
      // viewport scale, so the click has to be divided back down to match.
      const x = (event.clientX - bounds.left) / editorViewPortScale;
      const y = (event.clientY - bounds.top) / editorViewPortScale;
      const componentUuid = findComponentAtPoint(geometryMap, x, y);
      if (!componentUuid) {
        // Clicking empty canvas abandons the draft rather than anchoring a
        // thread to nothing: a surface-level thread is made from the panel.
        setDraft(null);
        return;
      }
      setDraft({
        componentUuid,
        top: y * editorViewPortScale,
        left: x * editorViewPortScale,
      });
    },
    [editorViewPortScale, geometryMap],
  );

  const closeDraft = useCallback(() => setDraft(null), []);
  const finishDraft = useCallback(() => {
    setDraft(null);
    dispatch(setCommentMode(false));
    dispatch(setCommentsPanelOpen(true));
  }, [dispatch]);

  // Pins are drawn whenever the surface is open, not only while the panel is,
  // because the pin is how a page says it has been commented on at all. That
  // is the job Figma and Notion give it, and with pins hidden nothing on the
  // canvas distinguished a page carrying a conversation from one that is not.
  //
  // The filter only follows the panel while the panel is on screen. With it
  // closed there is no visible control explaining why resolved pins would be
  // showing, so the canvas falls back to the open threads.
  const effectiveFilter = panelOpen ? filter : 'open';
  // @todo Poll adaptively (faster while the panel is open, slower otherwise) instead of relying on tag invalidation alone, reusing the intervals in ui/src/features/notifications/constants.ts.
  const { data } = useGetCommentsQuery(
    {
      surfaceType,
      surfaceId,
      includeResolved: effectiveFilter === 'resolved',
    },
    { skip: !hasSurface || !canViewComments },
  );

  if (!canViewComments || !data) {
    return null;
  }

  const threads = filterThreads(data.threads, effectiveFilter);

  return (
    <div
      className={clsx(styles.pinLayer, {
        [styles.placing]: commentModeActive,
      })}
      data-testid="canvas-comment-pin-layer"
      data-comment-mode={commentModeActive ? 'true' : 'false'}
      onClick={commentModeActive ? handleLayerClick : undefined}
    >
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
            style={{
              top: `${geometry.rect.top * editorViewPortScale}px`,
              left: `${geometry.rect.left * editorViewPortScale}px`,
            }}
            aria-label={getThreadLabel(thread)}
            data-testid="canvas-comment-pin"
            data-comment-thread-id={thread.id}
            onClick={(event) => {
              event.stopPropagation();
              dispatch(setActiveThread(thread.id));
              dispatch(setCommentsPanelOpen(true));
            }}
          >
            {getReplyCount(thread) + 1}
          </button>
        );
      })}
      {draft && (
        <CommentDraftComposer
          draft={draft}
          surfaceType={surfaceType}
          surfaceId={surfaceId}
          onCancel={closeDraft}
          onPosted={finishDraft}
        />
      )}
    </div>
  );
};

export default CommentPinLayer;
