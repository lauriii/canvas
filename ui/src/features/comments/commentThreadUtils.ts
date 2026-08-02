import type { CommentFilter } from '@/features/comments/commentsSlice';
import type { CommentThread } from '@/services/comments';

/**
 * Counts the replies of a thread, excluding its opening comment.
 *
 * @param thread - The thread.
 * @returns The number of replies.
 */
export const getReplyCount = (thread: CommentThread): number =>
  Math.max(thread.comments.length - 1, 0);

/**
 * Builds the accessible name of a thread's on-canvas pin and list entry.
 *
 * @param thread - The thread.
 * @returns A label naming the author and the number of replies.
 */
export const getThreadLabel = (thread: CommentThread): string => {
  const replies = getReplyCount(thread);
  return `Comment thread by ${thread.author.displayName}, ${replies} ${
    replies === 1 ? 'reply' : 'replies'
  }`;
};

/**
 * Applies the open/resolved filter and orders threads oldest first.
 *
 * @param threads - Every thread returned for the surface.
 * @param filter - The filter the user picked.
 * @returns The threads to display, oldest first.
 */
export const filterThreads = (
  threads: CommentThread[],
  filter: CommentFilter,
): CommentThread[] =>
  threads
    .filter((thread) => thread.resolved === (filter === 'resolved'))
    .sort((a, b) => a.created - b.created);
