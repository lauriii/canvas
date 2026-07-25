import { useState } from 'react';
import clsx from 'clsx';
import {
  Box,
  Button,
  Flex,
  SegmentedControl,
  Text,
  TextArea,
} from '@radix-ui/themes';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import Avatar from '@/components/Avatar';
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
  const [replyToThread, { isLoading: isReplying }] = useReplyToThreadMutation();
  const [setThreadResolved, { isLoading: isResolving }] =
    useSetThreadResolvedMutation();
  const [openingComment, ...replies] = thread.comments;
  const replyCount = getReplyCount(thread);

  const submitReply = async () => {
    const body = replyBody.trim();
    if (!body) {
      return;
    }
    await replyToThread({ threadId: thread.id, body }).unwrap();
    setReplyBody('');
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
              <Text size="1" className={styles.body}>
                {openingComment?.body ?? ''}
              </Text>
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
                  <Text size="1" className={styles.body}>
                    {comment.body}
                  </Text>
                </Flex>
              </Flex>
            ))}

            <TextArea
              size="1"
              placeholder="Reply…"
              aria-label="Reply to this comment thread"
              data-testid="canvas-comment-reply-input"
              value={replyBody}
              onChange={(event) => setReplyBody(event.target.value)}
            />
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
  const [createThread, { isLoading: isCreating }] = useCreateThreadMutation();

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
    await createThread({
      surfaceType,
      surfaceId,
      componentUuid: selectedComponentUuid ?? null,
      body,
    }).unwrap();
    setNewThreadBody('');
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

      <Box className={styles.composer}>
        <TextArea
          size="1"
          placeholder="Add a comment…"
          aria-label="Add a comment"
          data-testid="canvas-comment-composer"
          value={newThreadBody}
          onChange={(event) => setNewThreadBody(event.target.value)}
        />
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
