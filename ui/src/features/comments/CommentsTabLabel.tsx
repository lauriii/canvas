import { Text } from '@radix-ui/themes';

import useCommentSurface from '@/features/comments/useCommentSurface';
import { useGetCommentsQuery } from '@/services/comments';

import styles from './CommentsPanel.module.css';

/**
 * The "Comments" tab label, with the number of open threads on this surface.
 */
const CommentsTabLabel = () => {
  const { surfaceType, surfaceId, hasSurface } = useCommentSurface();
  const { data } = useGetCommentsQuery(
    { surfaceType, surfaceId },
    { skip: !hasSurface },
  );
  const openThreadCount =
    data?.threads.filter(({ resolved }) => !resolved).length ?? 0;

  return (
    <>
      Comments
      {openThreadCount > 0 && (
        <Text
          size="1"
          className={styles.tabCount}
          data-testid="canvas-comments-count"
          aria-label={`${openThreadCount} open`}
        >
          {openThreadCount}
        </Text>
      )}
    </>
  );
};

export default CommentsTabLabel;
