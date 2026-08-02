import { createApi } from '@reduxjs/toolkit/query/react';

import { baseQuery } from '@/services/baseQuery';

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
}

export interface ReplyToThreadArgs {
  threadId: string;
  body: string;
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
  createThread: (args: CreateThreadArgs): FetchArgs => ({
    url: COMMENTS_ENDPOINT,
    method: 'POST',
    body: args,
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
    }),
    replyToThread: builder.mutation<CommentThreadResponse, ReplyToThreadArgs>({
      query: commentRequests.replyToThread,
      invalidatesTags: invalidatesCommentList,
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

export const {
  useGetCommentsQuery,
  useGetMentionableUsersQuery,
  useCreateThreadMutation,
  useReplyToThreadMutation,
  useSetThreadResolvedMutation,
} = commentsApi;
