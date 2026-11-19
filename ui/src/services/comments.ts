import { createApi } from '@reduxjs/toolkit/query/react';

import { baseQuery } from '@/services/baseQuery';
import { getCanvasSettings } from '@/utils/drupal-globals';

import type { ThunkDispatch, UnknownAction } from '@reduxjs/toolkit';
import type { FetchArgs } from '@reduxjs/toolkit/query/react';

export interface CommentAuthor {
  uid: number;
  displayName: string;
  avatar: string | null;
}

export interface CommentMention {
  uid: number;
  /** `null` once the mentioned user no longer exists. */
  displayName: string | null;
}

export interface Comment {
  id: string;
  /** Mentions are stored as `@[user:123]`; see `mentions` for their names. */
  body: string;
  /** Integer UNIX seconds. */
  created: number;
  /** Integer UNIX seconds. */
  changed: number;
  author: CommentAuthor;
  /** The users named in `body`, resolved at read time. */
  mentions: CommentMention[];
}

export interface MentionableUsersResponse {
  users: CommentAuthor[];
}

export interface MentionableUsersArgs {
  surfaceType: string;
  surfaceId: string;
  /** What the user has typed after the `@`. */
  query: string;
}

export interface CommentThread {
  id: string;
  uuid: string;
  surfaceType: string;
  surfaceId: string;
  /** `null` identifies a surface-level thread that is not tied to a component. */
  componentUuid: string | null;
  /**
   * Where in the component the comment was left, from 0 to 1 across its box.
   *
   * A fraction rather than a canvas coordinate: the preview reflows and is
   * rendered at several widths at once, so an absolute point would land
   * somewhere different in each. `null` when the thread was started from the
   * sidebar, which has no point to record.
   */
  offsetX: number | null;
  offsetY: number | null;
  resolved: boolean;
  /** Integer UNIX seconds. */
  created: number;
  /** Integer UNIX seconds. */
  changed: number;
  author: CommentAuthor;
  /** Oldest first; index 0 is the opening comment. */
  comments: Comment[];
}

export interface CommentThreadsResponse {
  threads: CommentThread[];
}

export interface CommentThreadResponse {
  thread: CommentThread;
}

export interface GetCommentsArgs {
  surfaceType: string;
  surfaceId: string;
  includeResolved?: boolean;
}

export interface CreateThreadArgs {
  surfaceType: string;
  surfaceId: string;
  componentUuid: string | null;
  /** Where in the component it was left; both or neither, and only with a component. */
  offsetX?: number;
  offsetY?: number;
  body: string;
  /**
   * The names behind the mention tokens in `body`. Never sent: they are only
   * used to show the comment before the server has answered.
   */
  mentions?: CommentMention[];
}

export interface ReplyToThreadArgs {
  threadId: string;
  body: string;
  /** See `CreateThreadArgs.mentions`. */
  mentions?: CommentMention[];
}

export interface SetThreadResolvedArgs {
  threadId: string;
  resolved: boolean;
  /**
   * The arguments of the `getComments` cache entry to patch optimistically.
   * Omit to skip the optimistic update.
   */
  listArgs?: GetCommentsArgs;
}

export const COMMENTS_ENDPOINT = '/canvas/api/v0/comments';

/**
 * Builds the URL listing the threads of a single surface.
 *
 * @param args - The surface to list threads for.
 * @returns The URL, including the query string.
 */
export const buildListUrl = ({
  surfaceType,
  surfaceId,
  includeResolved = false,
}: GetCommentsArgs): string => {
  const params = new URLSearchParams({
    surfaceType,
    surfaceId,
    includeResolved: includeResolved ? '1' : '0',
  });
  return `${COMMENTS_ENDPOINT}?${params.toString()}`;
};

/**
 * Builds the URL of a single thread.
 *
 * @param threadId - The thread ID.
 * @returns The URL.
 */
export const buildThreadUrl = (threadId: string): string =>
  `${COMMENTS_ENDPOINT}/${encodeURIComponent(threadId)}`;

/**
 * Builds the URL that replies of a single thread are posted to.
 *
 * @param threadId - The thread ID.
 * @returns The URL.
 */
export const buildRepliesUrl = (threadId: string): string =>
  `${buildThreadUrl(threadId)}/replies`;

/**
 * Builds the URL of the mention autocomplete.
 *
 * @param query - What the user has typed after the `@`.
 * @returns The URL, including the query string.
 */
export const buildMentionableUsersUrl = ({
  surfaceType,
  surfaceId,
  query,
}: MentionableUsersArgs): string => {
  const params = new URLSearchParams({ surfaceType, surfaceId, q: query });
  return `${COMMENTS_ENDPOINT}/mentionable-users?${params.toString()}`;
};

/**
 * The request each endpoint sends, extracted so it can be asserted directly.
 *
 * RTK Query does not expose an endpoint's `query` function once the API is
 * created, so the request shapes live here and are handed to `createApi` below.
 */
export const commentRequests = {
  getComments: (args: GetCommentsArgs): string => buildListUrl(args),
  getMentionableUsers: (args: MentionableUsersArgs): string =>
    buildMentionableUsersUrl(args),
  createThread: ({
    surfaceType,
    surfaceId,
    componentUuid,
    offsetX,
    offsetY,
    body,
  }: CreateThreadArgs): FetchArgs => ({
    url: COMMENTS_ENDPOINT,
    method: 'POST',
    body: { surfaceType, surfaceId, componentUuid, offsetX, offsetY, body },
  }),
  replyToThread: ({ threadId, body }: ReplyToThreadArgs): FetchArgs => ({
    url: buildRepliesUrl(threadId),
    method: 'POST',
    body: { body },
  }),
  setThreadResolved: ({
    threadId,
    resolved,
  }: SetThreadResolvedArgs): FetchArgs => ({
    url: buildThreadUrl(threadId),
    method: 'PATCH',
    body: { resolved },
  }),
};

const invalidatesCommentList = [{ type: 'Comments' as const, id: 'LIST' }];

/** The ID prefix of a thread or comment the server has not confirmed yet. */
const PENDING_ID_PREFIX = 'pending-';
let pendingCount = 0;

/**
 * Whether a thread or comment is shown ahead of the server confirming it.
 *
 * It has no real ID yet, so it cannot be replied to or resolved.
 *
 * @param item - The thread or comment.
 * @returns True until the server's version has replaced it.
 */
export const isPending = ({ id }: { id: string }): boolean =>
  id.startsWith(PENDING_ID_PREFIX);

/**
 * The user writing comments in this editor.
 *
 * @returns The author to show on a comment the server has not confirmed yet.
 * @see \Drupal\canvas\Controller\CanvasController
 */
export const getCommentAuthor = (): CommentAuthor =>
  getCanvasSettings()?.commentAuthor ?? {
    uid: 0,
    displayName: '',
    avatar: null,
  };

const buildPendingComment = (
  body: string,
  mentions: CommentMention[] = [],
): Comment => {
  const now = Math.floor(Date.now() / 1000);
  pendingCount += 1;
  return {
    id: `${PENDING_ID_PREFIX}${pendingCount}`,
    body,
    created: now,
    changed: now,
    author: getCommentAuthor(),
    mentions,
  };
};

const replaceThread = (
  threads: CommentThread[],
  id: string,
  thread: CommentThread,
) => {
  const index = threads.findIndex((candidate) => candidate.id === id);
  if (index !== -1) {
    threads[index] = thread;
  }
};

export const commentsApi = createApi({
  reducerPath: 'commentsApi',
  // This uses the plain `baseQuery`, NOT `baseQueryWithAutoSaves`. Comments
  // must never participate in the auto-save-hash 409 protocol: a comment is
  // metadata *about* a page, never part of the page's auto-saved data. Sending
  // an `autoSaves` hash would let an unrelated conflict reject a comment, and
  // would let a comment mutate the conflict state of the page being edited.
  // This is a design requirement, not an accident.
  baseQuery,
  tagTypes: ['Comments'],
  endpoints: (builder) => ({
    getComments: builder.query<CommentThreadsResponse, GetCommentsArgs>({
      query: commentRequests.getComments,
      providesTags: [{ type: 'Comments', id: 'LIST' }],
    }),
    // Deliberately untagged: the mentionable set is not invalidated by posting
    // a comment, and re-fetching it on every keystroke of a reply would be
    // wasteful. RTK Query's own per-argument cache is the whole mechanism.
    getMentionableUsers: builder.query<
      MentionableUsersResponse,
      MentionableUsersArgs
    >({
      query: commentRequests.getMentionableUsers,
    }),
    createThread: builder.mutation<CommentThreadResponse, CreateThreadArgs>({
      query: commentRequests.createThread,
      invalidatesTags: invalidatesCommentList,
      // The thread is listed and pinned the moment it is posted, and taken
      // back out if the server refuses it.
      async onQueryStarted(args, { dispatch, queryFulfilled }) {
        const comment = buildPendingComment(args.body, args.mentions);
        const pending: CommentThread = {
          id: comment.id,
          uuid: comment.id,
          surfaceType: args.surfaceType,
          surfaceId: args.surfaceId,
          componentUuid: args.componentUuid,
          offsetX: args.offsetX ?? null,
          offsetY: args.offsetY ?? null,
          resolved: false,
          created: comment.created,
          changed: comment.changed,
          author: comment.author,
          comments: [comment],
        };
        const undo = dispatch(
          patchThreadLists((threads, list) => {
            if (
              list.surfaceType === args.surfaceType &&
              list.surfaceId === args.surfaceId
            ) {
              threads.push(pending);
            }
          }),
        );
        try {
          const { data } = await queryFulfilled;
          // The real thread replaces the pending one at once, so it can be
          // replied to without waiting for the list to be fetched again.
          dispatch(
            patchThreadLists((threads) =>
              replaceThread(threads, pending.id, data.thread),
            ),
          );
        } catch {
          undo();
        }
      },
    }),
    replyToThread: builder.mutation<CommentThreadResponse, ReplyToThreadArgs>({
      query: commentRequests.replyToThread,
      invalidatesTags: invalidatesCommentList,
      // The reply joins its thread the moment it is posted, and is taken back
      // out if the server refuses it.
      async onQueryStarted(
        { threadId, body, mentions },
        { dispatch, queryFulfilled },
      ) {
        const comment = buildPendingComment(body, mentions);
        const undo = dispatch(
          patchThreadLists((threads) => {
            threads.find(({ id }) => id === threadId)?.comments.push(comment);
          }),
        );
        try {
          const { data } = await queryFulfilled;
          dispatch(
            patchThreadLists((threads) =>
              replaceThread(threads, threadId, data.thread),
            ),
          );
        } catch {
          undo();
        }
      },
    }),
    setThreadResolved: builder.mutation<
      CommentThreadResponse,
      SetThreadResolvedArgs
    >({
      query: commentRequests.setThreadResolved,
      invalidatesTags: invalidatesCommentList,
      async onQueryStarted(
        { threadId, resolved, listArgs },
        { dispatch, queryFulfilled },
      ) {
        if (!listArgs) {
          return;
        }
        const patch = dispatch(
          commentsApi.util.updateQueryData('getComments', listArgs, (draft) => {
            const thread = draft.threads.find(({ id }) => id === threadId);
            if (thread) {
              thread.resolved = resolved;
            }
          }),
        );
        try {
          await queryFulfilled;
        } catch {
          patch.undo();
        }
      },
    }),
  }),
});

/**
 * Changes every cached thread list, ahead of the server.
 *
 * The panel, the pins and the tab label can each hold their own list of the
 * same surface, so a change made to one of them alone would show in one place
 * and not in another.
 *
 * @param recipe - The change, given a list's threads and what it lists.
 * @returns A thunk that applies the change and returns how to undo it.
 */
function patchThreadLists(
  recipe: (threads: CommentThread[], list: GetCommentsArgs) => void,
) {
  return (
    dispatch: ThunkDispatch<any, any, UnknownAction>,
    getState: () => any,
  ): (() => void) => {
    const patches = commentsApi.util
      .selectInvalidatedBy(getState(), invalidatesCommentList)
      .filter(({ endpointName }) => endpointName === 'getComments')
      .map(({ originalArgs }) =>
        dispatch(
          commentsApi.util.updateQueryData(
            'getComments',
            originalArgs,
            (draft) => recipe(draft.threads, originalArgs),
          ),
        ),
      );
    return () => patches.forEach((patch) => patch.undo());
  };
}

export const {
  useGetCommentsQuery,
  useGetMentionableUsersQuery,
  useCreateThreadMutation,
  useReplyToThreadMutation,
  useSetThreadResolvedMutation,
} = commentsApi;
