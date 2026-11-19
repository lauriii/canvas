import { Text } from '@radix-ui/themes';

import useCommentSurface from '@/features/comments/useCommentSurface';
import { useGetCommentsQuery } from '@/services/comments';

import styles from './CommentsPanel.module.css';

/**
 * The "Comments" tab label, with the number of open threads on this surface.
 *
 * The count tells a user a page carries a conversation before they go
 * looking, including for threads that have no pin on the canvas. Notion and
 * Google Docs both put this marker on the same affordance that opens the
 * list, for the same reason.
 *
 * The open threads are fetched for as long as the surface is open. The pin
 * layer asks for the same thing while the panel is closed or on its "Open"
 * filter, and shares this cache entry when it does.
 */
const CommentsTabLabel = () => {
  const { surfaceType, surfaceId, hasSurface } = useCommentSurface();
  const { data } = useGetCommentsQuery(
    { surfaceType, surfaceId, includeResolved: false },
    { skip: !hasSurface },
  );
  const openThreadCount = data?.threads.length ?? 0;

  return (
    <>
      Comments
      {openThreadCount > 0 && (
        <Text
          size="1"
          className={styles.tabCount}
          data-testid="canvas-comments-count"
          // The count is decoration next to a label that already says what
          // this is, so it is spelled out once rather than read as a number.
          aria-label={`${openThreadCount} open`}
        >
          {openThreadCount}
        </Text>
      )}
    </>
  );
};

export default CommentsTabLabel;
