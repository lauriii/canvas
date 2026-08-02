import { Text } from '@radix-ui/themes';

import useCommentSurface from '@/features/comments/useCommentSurface';
import { useGetCommentsQuery } from '@/services/comments';

import styles from './CommentsPanel.module.css';

/**
 * The "Comments" tab label, with the number of open threads on this surface.
 *
 * The count is the only thing that tells a user a page carries a conversation
 * before they go looking: pins are drawn only while comments are on screen,
 * and there is no other standing signal. Notion and Google Docs both put this
 * marker on the same affordance that opens the list, for the same reason.
 *
 * This is the one place that fetches threads unconditionally. Everything else
 * skips the request until comments are actually being looked at, and shares
 * this cache entry when they are.
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
