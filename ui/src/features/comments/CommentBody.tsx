import clsx from 'clsx';
import { Text } from '@radix-ui/themes';

import { splitBodyIntoSegments } from '@/features/comments/mentionUtils';

import type { CommentMention } from '@/services/comments';

import styles from './CommentsPanel.module.css';

interface CommentBodyProps {
  body: string;
  mentions: CommentMention[];
  /** Clamps to a few lines, for the collapsed thread preview. */
  clamped?: boolean;
  testId?: string;
}

/**
 * Renders a comment body, turning its `@[user:123]` tokens into names.
 *
 * The tokens are resolved against the names the API sent, so a mention shows
 * whatever the user is called now rather than what they were called when the
 * comment was written.
 */
const CommentBody = ({ body, mentions, clamped, testId }: CommentBodyProps) => (
  <Text
    size="1"
    className={clsx(styles.body, { [styles.bodyClamped]: clamped })}
    data-testid={testId}
  >
    {splitBodyIntoSegments(body, mentions).map((segment, index) =>
      segment.mention ? (
        <span
          key={index}
          className={clsx(styles.mention, {
            [styles.mentionUnknown]: segment.mention.displayName === null,
          })}
          data-testid="canvas-comment-mention"
          data-mention-uid={segment.mention.uid}
        >
          {segment.text}
        </span>
      ) : (
        segment.text
      ),
    )}
  </Text>
);

export default CommentBody;
