import clsx from 'clsx';
import { Box, Button, Flex, SegmentedControl, Text } from '@radix-ui/themes';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import Avatar from '@/components/Avatar';
import { getAvatarInitialColor } from '@/components/review/utils';
import CommentBody from '@/features/comments/CommentBody';
import CommentComposer from '@/features/comments/CommentComposer';
import {
  selectActiveThreadId,
  selectCommentFilter,
  setActiveThread,
  setCommentFilter,
} from '@/features/comments/commentsSlice';
import {
  filterThreads,
  getThreadLabel,
} from '@/features/comments/commentThreadUtils';
import useCommentSurface from '@/features/comments/useCommentSurface';
import { selectLayout } from '@/features/layout/layoutModelSlice';
import { findComponentByUuid } from '@/features/layout/layoutUtils';
import { formatTimestamp } from '@/features/notifications/formatTimestamp';
import { selectSelectedComponentUuid } from '@/features/ui/uiSlice';
import useComponentSelection from '@/hooks/useComponentSelection';
import useGetComponentName from '@/hooks/useGetComponentName';
import {
  isPending,
  useCreateThreadMutation,
  useGetCommentsQuery,
  useReplyToThreadMutation,
  useSetThreadResolvedMutation,
} from '@/services/comments';
import { hasPermission } from '@/utils/permissions';

import type { CommentFilter } from '@/features/comments/commentsSlice';
import type { CommentThread } from '@/services/comments';

import styles from './CommentsPanel.module.css';

/** Converts the integer UNIX seconds of the API to a relative label. */
const relativeTime = (unixSeconds: number): string =>
  formatTimestamp(unixSeconds * 1000);

interface ThreadProps {
  thread: CommentThread;
  isActive: boolean;
  onSelect: (thread: CommentThread) => void;
}

const Thread = ({ thread, isActive, onSelect }: ThreadProps) => {
  const [replyToThread, { isLoading: isReplying }] = useReplyToThreadMutation();
  const [setThreadResolved, { isLoading: isResolving }] =
    useSetThreadResolvedMutation();
  const [openingComment, ...replies] = thread.comments;
  const replyCount = replies.length;
  const canWrite = hasPermission('createComments');
  // A pending thread cannot be replied to or resolved yet.
  const pending = isPending(thread);
  // Empty when the component is no longer in the layout.
  const layout = useAppSelector(selectLayout);
  const componentName = useGetComponentName(
    thread.componentUuid
      ? findComponentByUuid(layout, thread.componentUuid)
      : null,
  );

  return (
    <Box
      asChild
      className={clsx(styles.thread, {
        [styles.threadActive]: isActive,
        [styles.pending]: pending,
      })}
      data-testid="canvas-comment-thread"
      data-comment-thread-id={thread.id}
      data-resolved={thread.resolved ? 'true' : 'false'}
    >
      <li>
        <button
          type="button"
          className={styles.threadSummary}
          aria-label={getThreadLabel(thread)}
          aria-expanded={isActive}
          data-testid="canvas-comment-thread-toggle"
          onClick={() => onSelect(thread)}
        >
          <Flex gap="2" align="start">
            <Avatar
              name={thread.author.displayName}
              color={getAvatarInitialColor(thread.author.uid)}
            />
            <Flex direction="column" gap="1" className={styles.threadText}>
              <Flex gap="2" align="baseline">
                <Text size="2" weight="medium">
                  {thread.author.displayName}
                </Text>
                <Text size="1" color="gray">
                  {relativeTime(thread.created)}
                </Text>
              </Flex>
              <CommentBody
                body={openingComment?.body ?? ''}
                mentions={openingComment?.mentions ?? []}
                // Collapsed, a long opening comment is cut to a preview.
                clamped={!isActive}
                testId="canvas-comment-opening-body"
              />
              <Text size="1" color="gray" data-testid="canvas-comment-replies">
                {replyCount} {replyCount === 1 ? 'reply' : 'replies'}
                {thread.componentUuid
                  ? ` • on ${componentName || 'a component'}`
                  : ' • on this page'}
              </Text>
            </Flex>
          </Flex>
        </button>

        {isActive && (
          <Box className={styles.threadDetail}>
            {replies.map((comment) => (
              <Flex
                key={comment.id}
                gap="2"
                align="start"
                className={clsx(styles.reply, {
                  [styles.pending]: isPending(comment),
                })}
                data-testid="canvas-comment-reply"
              >
                <Avatar
                  name={comment.author.displayName}
                  color={getAvatarInitialColor(comment.author.uid)}
                />
                <Flex direction="column" gap="1" className={styles.threadText}>
                  <Flex gap="2" align="baseline">
                    <Text size="1" weight="medium">
                      {comment.author.displayName}
                    </Text>
                    <Text size="1" color="gray">
                      {relativeTime(comment.created)}
                    </Text>
                  </Flex>
                  <CommentBody
                    body={comment.body}
                    mentions={comment.mentions}
                  />
                </Flex>
              </Flex>
            ))}

            {/* Replying and resolving both write. */}
            {canWrite && (
              <CommentComposer
                placeholder="Reply…"
                ariaLabel="Reply to this comment thread"
                testId="canvas-comment-reply-input"
                errorMessage="Your reply could not be posted."
                errorTestId="canvas-comment-reply-error"
                disabled={isReplying || pending}
                post={(comment) =>
                  replyToThread({ threadId: thread.id, ...comment }).unwrap()
                }
              >
                {(submit) => (
                  <Flex gap="2" mt="2">
                    <Button
                      size="1"
                      data-testid="canvas-comment-reply-submit"
                      {...submit}
                    >
                      Reply
                    </Button>
                    <Button
                      size="1"
                      variant="soft"
                      color="gray"
                      disabled={isResolving || pending}
                      data-testid="canvas-comment-resolve"
                      onClick={() =>
                        setThreadResolved({
                          threadId: thread.id,
                          resolved: !thread.resolved,
                        })
                      }
                    >
                      {thread.resolved ? 'Reopen' : 'Resolve'}
                    </Button>
                  </Flex>
                )}
              </CommentComposer>
            )}
          </Box>
        )}
      </li>
    </Box>
  );
};

/**
 * The comments sidebar: lists the threads of the surface, including the ones
 * with no pin on the canvas, and starts, replies to and resolves them.
 */
export const CommentsPanel = () => {
  const dispatch = useAppDispatch();
  const filter = useAppSelector(selectCommentFilter);
  const activeThreadId = useAppSelector(selectActiveThreadId);
  const selectedComponentUuid = useAppSelector(selectSelectedComponentUuid);
  const { setSelectedComponent } = useComponentSelection();
  const { surfaceType, surfaceId, hasSurface } = useCommentSurface();
  const [createThread, { isLoading: isCreating }] = useCreateThreadMutation();
  const canWrite = hasPermission('createComments');
  const layout = useAppSelector(selectLayout);
  const selectedComponentName = useGetComponentName(
    selectedComponentUuid
      ? findComponentByUuid(layout, selectedComponentUuid)
      : null,
  );

  const { data, isLoading, isError } = useGetCommentsQuery(
    { surfaceType, surfaceId },
    { skip: !hasSurface },
  );
  const threads = data ? filterThreads(data.threads, filter) : [];

  const handleSelect = (thread: CommentThread) => {
    const collapsing = thread.id === activeThreadId;
    dispatch(setActiveThread(collapsing ? null : thread.id));
    if (!collapsing && thread.componentUuid) {
      setSelectedComponent(thread.componentUuid);
    }
  };

  return (
    // `pt` matches the breathing room the Page data tab's form leaves under
    // the tab strip, so switching between the two does not jump.
    <Flex direction="column" gap="3" pt="5" data-testid="canvas-comments-panel">
      <SegmentedControl.Root
        size="1"
        value={filter}
        onValueChange={(value) =>
          dispatch(setCommentFilter(value as CommentFilter))
        }
        data-testid="canvas-comments-filter"
      >
        <SegmentedControl.Item
          value="open"
          data-testid="canvas-comments-filter-open"
        >
          Open
        </SegmentedControl.Item>
        <SegmentedControl.Item
          value="resolved"
          data-testid="canvas-comments-filter-resolved"
        >
          Resolved
        </SegmentedControl.Item>
      </SegmentedControl.Root>

      {canWrite && (
        <Box className={styles.composer}>
          <CommentComposer
            placeholder="Add a comment…"
            ariaLabel="Add a comment"
            testId="canvas-comment-composer"
            errorMessage="Your comment could not be posted."
            errorTestId="canvas-comment-error"
            disabled={!hasSurface || isCreating}
            post={(comment) =>
              createThread({
                surfaceType,
                surfaceId,
                componentUuid: selectedComponentUuid ?? null,
                ...comment,
              }).unwrap()
            }
          >
            {(submit) => (
              <Flex align="center" justify="between" gap="2" mt="2">
                <Text size="1" color="gray" data-testid="canvas-comment-target">
                  {selectedComponentUuid
                    ? `Will be attached to ${selectedComponentName || 'the selected component'}.`
                    : 'Will be attached to this page.'}
                </Text>
                <Button
                  size="1"
                  data-testid="canvas-comment-submit"
                  {...submit}
                >
                  Comment
                </Button>
              </Flex>
            )}
          </CommentComposer>
        </Box>
      )}
      {isLoading && (
        <Text size="1" color="gray" data-testid="canvas-comments-loading">
          Loading comments…
        </Text>
      )}
      {isError && (
        <Text size="1" color="red" data-testid="canvas-comments-error">
          Comments could not be loaded.
        </Text>
      )}
      {!isLoading && !isError && threads.length === 0 && (
        <Text size="1" color="gray" data-testid="canvas-comments-empty">
          {filter === 'resolved'
            ? 'No resolved comments yet.'
            : 'No open comments yet.'}
        </Text>
      )}
      {threads.length > 0 && (
        <ul className={styles.list}>
          {threads.map((thread) => (
            <Thread
              key={thread.id}
              thread={thread}
              isActive={thread.id === activeThreadId}
              onSelect={handleSelect}
            />
          ))}
        </ul>
      )}
    </Flex>
  );
};

export default CommentsPanel;
