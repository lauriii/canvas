import clsx from 'clsx';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import {
  selectActiveThreadId,
  selectCommentFilter,
  selectCommentModeActive,
  setActiveThread,
} from '@/features/comments/commentsSlice';
import {
  filterThreads,
  getReplyCount,
  getThreadLabel,
} from '@/features/comments/commentThreadUtils';
import useCommentSurface from '@/features/comments/useCommentSurface';
import { usePreviewGeometry } from '@/features/layout/preview/PreviewGeometryContext';
import {
  selectActivePanel,
  setActivePanel,
} from '@/features/ui/primaryPanelSlice';
import { selectEditorViewPortScale } from '@/features/ui/uiSlice';
import { useGetCommentsQuery } from '@/services/comments';

import styles from './CommentPinLayer.module.css';

export const COMMENTS_PANEL_ID = 'comments';

/**
 * Renders one clickable pin per component-anchored comment thread.
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
  const activePanel = useAppSelector(selectActivePanel);
  const commentModeActive = useAppSelector(selectCommentModeActive);
  const activeThreadId = useAppSelector(selectActiveThreadId);
  const filter = useAppSelector(selectCommentFilter);
  const { surfaceType, surfaceId, hasSurface } = useCommentSurface();

  // Pins are only relevant while the user is looking at comments, so the query
  // is skipped otherwise. The arguments match the ones the comments panel uses
  // so both share a single cache entry.
  const isRelevant = commentModeActive || activePanel === COMMENTS_PANEL_ID;
  // @todo Poll adaptively (faster while the panel is open, slower otherwise) instead of relying on tag invalidation alone, reusing the intervals in ui/src/features/notifications/constants.ts.
  const { data } = useGetCommentsQuery(
    { surfaceType, surfaceId, includeResolved: filter === 'resolved' },
    { skip: !hasSurface || !isRelevant },
  );

  if (!isRelevant || !data) {
    return null;
  }

  const threads = filterThreads(data.threads, filter);

  return (
    <div className={styles.pinLayer} data-testid="canvas-comment-pin-layer">
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
              dispatch(setActivePanel(COMMENTS_PANEL_ID));
            }}
          >
            {getReplyCount(thread) + 1}
          </button>
        );
      })}
    </div>
  );
};

export default CommentPinLayer;
