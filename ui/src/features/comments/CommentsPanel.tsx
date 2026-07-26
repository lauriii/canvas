import { useState } from 'react';
import clsx from 'clsx';
import { Box, Button, Flex, SegmentedControl, Text } from '@radix-ui/themes';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import Avatar from '@/components/Avatar';
import CommentBody from '@/features/comments/CommentBody';
import {
  clearActiveThread,
  selectActiveThreadId,
  selectCommentFilter,
  setActiveThread,
  setCommentFilter,
} from '@/features/comments/commentsSlice';
import {
  filterThreads,
  getReplyCount,
  getThreadLabel,
} from '@/features/comments/commentThreadUtils';
import MentionTextArea from '@/features/comments/MentionTextArea';
import { toStoredBody } from '@/features/comments/mentionUtils';
import useCommentSurface from '@/features/comments/useCommentSurface';
import { formatTimestamp } from '@/features/notifications/formatTimestamp';
import { selectSelectedComponentUuid } from '@/features/ui/uiSlice';
import useComponentSelection from '@/hooks/useComponentSelection';
import {
  useCreateThreadMutation,
  useGetCommentsQuery,
  useReplyToThreadMutation,
  useSetThreadResolvedMutation,
} from '@/services/comments';
import { hasPermission } from '@/utils/permissions';

import type { CommentFilter } from '@/features/comments/commentsSlice';
import type { CommentThread, GetCommentsArgs } from '@/services/comments';

import styles from './CommentsPanel.module.css';

/** Converts the integer UNIX seconds of the API to a relative label. */
const relativeTime = (unixSeconds: number): string =>
  formatTimestamp(unixSeconds * 1000);

interface ThreadProps {
  thread: CommentThread;
  isActive: boolean;
  onSelect: (thread: CommentThread) => void;
  listArgs: GetCommentsArgs;
}

const Thread = ({ thread, isActive, onSelect, listArgs }: ThreadProps) => {
  const [replyBody, setReplyBody] = useState('');
  const [replyMentions, setReplyMentions] = useState<Record<string, number>>(
    {},
  );
  const [replyError, setReplyError] = useState(false);
  const [replyToThread, { isLoading: isReplying }] = useReplyToThreadMutation();
  const [setThreadResolved, { isLoading: isResolving }] =
    useSetThreadResolvedMutation();
  const [openingComment, ...replies] = thread.comments;
  const replyCount = getReplyCount(thread);
  const canWrite = hasPermission('createComments');

  const submitReply = async () => {
    const body = replyBody.trim();
    if (!body) {
      return;
    }
    try {
      await replyToThread({
        threadId: thread.id,
        body: toStoredBody(body, replyMentions),
      }).unwrap();
      setReplyError(false);
      setReplyBody('');
      setReplyMentions({});
    } catch {
      // The composed text is deliberately kept so it is not lost.
      setReplyError(true);
    }
  };

  return (
    <Box
      asChild
      className={clsx(styles.thread, { [styles.threadActive]: isActive })}
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
              imageUrl={thread.author.avatar ?? undefined}
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
                // Collapsed, the opening comment is a preview: a very long
                // body would otherwise push every following thread thousands
                // of pixels down the panel. Expanding shows all of it.
                clamped={!isActive}
                testId="canvas-comment-opening-body"
              />
              <Text size="1" color="gray" data-testid="canvas-comment-replies">
                {replyCount} {replyCount === 1 ? 'reply' : 'replies'}
                {thread.componentUuid ? ' • on a component' : ' • on this page'}
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
                className={styles.reply}
                data-testid="canvas-comment-reply"
              >
                <Avatar
                  name={comment.author.displayName}
                  imageUrl={comment.author.avatar ?? undefined}
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

            {/* Replying and resolving both write, so both need the create
                permission. A viewer without it reads the thread instead of
                being offered buttons that can only fail. */}
            {canWrite && (
              <>
                <MentionTextArea
                  value={replyBody}
                  onChange={setReplyBody}
                  onMentionPicked={(displayName, uid) =>
                    setReplyMentions((current) => ({
                      ...current,
                      [displayName]: uid,
                    }))
                  }
                  placeholder="Reply…"
                  ariaLabel="Reply to this comment thread"
                  testId="canvas-comment-reply-input"
                  onSubmit={submitReply}
                />
                {replyError && (
                  <Text
                    size="1"
                    color="red"
                    data-testid="canvas-comment-reply-error"
                  >
                    Your reply could not be posted.
                  </Text>
                )}
                <Flex gap="2" mt="2">
                  <Button
                    size="1"
                    disabled={isReplying || !replyBody.trim()}
                    data-testid="canvas-comment-reply-submit"
                    onClick={submitReply}
                  >
                    Reply
                  </Button>
                  <Button
                    size="1"
                    variant="soft"
                    color="gray"
                    disabled={isResolving}
                    data-testid="canvas-comment-resolve"
                    onClick={() =>
                      setThreadResolved({
                        threadId: thread.id,
                        resolved: !thread.resolved,
                        listArgs,
                      })
                    }
                  >
                    {thread.resolved ? 'Reopen' : 'Resolve'}
                  </Button>
                </Flex>
              </>
            )}
          </Box>
        )}
      </li>
    </Box>
  );
};

/**
 * The comments sidebar.
 *
 * This panel is the primary commenting surface: everything that can be done
 * with an on-canvas pin can also be done here, with the keyboard, including
 * reading, replying to, resolving and reopening threads that have no pin.
 */
export const CommentsPanel = () => {
  const dispatch = useAppDispatch();
  const filter = useAppSelector(selectCommentFilter);
  const activeThreadId = useAppSelector(selectActiveThreadId);
  const selectedComponentUuid = useAppSelector(selectSelectedComponentUuid);
  const { setSelectedComponent } = useComponentSelection();
  const { surfaceType, surfaceId, hasSurface } = useCommentSurface();
  const [newThreadBody, setNewThreadBody] = useState('');
  const [newThreadMentions, setNewThreadMentions] = useState<
    Record<string, number>
  >({});
  const [createError, setCreateError] = useState(false);
  const [createThread, { isLoading: isCreating }] = useCreateThreadMutation();
  const canWrite = hasPermission('createComments');

  const listArgs = {
    surfaceType,
    surfaceId,
    includeResolved: filter === 'resolved',
  };
  const { data, isLoading, isError } = useGetCommentsQuery(listArgs, {
    skip: !hasSurface,
  });

  const threads = data ? filterThreads(data.threads, filter) : [];

  const handleSelect = (thread: CommentThread) => {
    if (thread.id === activeThreadId) {
      dispatch(clearActiveThread());
      return;
    }
    dispatch(setActiveThread(thread.id));
    if (thread.componentUuid) {
      setSelectedComponent(thread.componentUuid);
    }
  };

  const submitNewThread = async () => {
    const body = newThreadBody.trim();
    if (!body) {
      return;
    }
    try {
      await createThread({
        surfaceType,
        surfaceId,
        componentUuid: selectedComponentUuid ?? null,
        body: toStoredBody(body, newThreadMentions),
      }).unwrap();
      setCreateError(false);
      setNewThreadBody('');
      setNewThreadMentions({});
    } catch {
      // The composed text is deliberately kept so it is not lost.
      setCreateError(true);
    }
  };

  return (
    <Flex direction="column" gap="3" data-testid="canvas-comments-panel">
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

      {/* Starting a thread writes, so it needs the create permission. Without
          it the panel is read-only rather than offering a composer whose
          submit can only ever be rejected by the API. */}
      {canWrite && (
        <Box className={styles.composer}>
          <MentionTextArea
            value={newThreadBody}
            onChange={setNewThreadBody}
            onMentionPicked={(displayName, uid) =>
              setNewThreadMentions((current) => ({
                ...current,
                [displayName]: uid,
              }))
            }
            placeholder="Add a comment…"
            ariaLabel="Add a comment"
            testId="canvas-comment-composer"
            onSubmit={submitNewThread}
          />
          {createError && (
            <Text size="1" color="red" data-testid="canvas-comment-error">
              Your comment could not be posted.
            </Text>
          )}
          <Flex align="center" justify="between" gap="2" mt="2">
            <Text size="1" color="gray" data-testid="canvas-comment-target">
              {selectedComponentUuid
                ? 'Will be attached to the selected component.'
                : 'Will be attached to this page.'}
            </Text>
            <Button
              size="1"
              disabled={!hasSurface || isCreating || !newThreadBody.trim()}
              data-testid="canvas-comment-submit"
              onClick={submitNewThread}
            >
              Comment
            </Button>
          </Flex>
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
              listArgs={listArgs}
            />
          ))}
        </ul>
      )}
    </Flex>
  );
};

export default CommentsPanel;
