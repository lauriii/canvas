import { createApi } from '@reduxjs/toolkit/query/react';

import { baseQuery } from '@/services/baseQuery';
import { getCanvasSettings } from '@/utils/drupal-globals';

import type { ThunkDispatch, UnknownAction } from '@reduxjs/toolkit';

export interface CommentAuthor {
  uid: number;
  displayName: string;
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
  author: CommentAuthor;
  mentions: CommentMention[];
}

/** The entity that comments are read from and written to. */
interface CommentSurfaceArgs {
  surfaceType: string;
  surfaceId: string;
}

export interface CommentThread extends CommentSurfaceArgs {
  id: string;
  /** `null` for a thread on the surface itself, not on a component. */
  componentUuid: string | null;
  /** Where in the component the comment was left, from 0 to 1 across its box. */
  offsetX: number | null;
  offsetY: number | null;
  resolved: boolean;
  /** Integer UNIX seconds. */
  created: number;
  author: CommentAuthor;
  /** Oldest first; index 0 is the opening comment. */
  comments: Comment[];
}

interface ThreadResponse {
  thread: CommentThread;
}

/** `mentions` is never sent: it names the mention tokens of a pending comment. */
interface CommentBodyArgs {
  body: string;
  mentions?: CommentMention[];
}

interface CreateThreadArgs extends CommentSurfaceArgs, CommentBodyArgs {
  componentUuid: string | null;
  offsetX?: number;
  offsetY?: number;
}

const ENDPOINT = '/canvas/api/v0/comments';

const invalidatesCommentList = [{ type: 'Comments' as const, id: 'LIST' }];

/** The ID prefix of a thread or comment the server has not confirmed yet. */
const PENDING_ID_PREFIX = 'pending-';
let pendingCount = 0;

/** Whether a thread or comment is shown ahead of the server confirming it. */
export const isPending = ({ id }: { id: string }): boolean =>
  id.startsWith(PENDING_ID_PREFIX);

/**
 * The user writing comments in this editor.
 *
 * @see \Drupal\canvas\Controller\CanvasController
 */
export const getCommentAuthor = (): CommentAuthor =>
  getCanvasSettings()?.commentAuthor ?? { uid: 0, displayName: '' };

const buildPendingComment = ({ body, mentions = [] }: CommentBodyArgs) => {
  pendingCount += 1;
  return {
    id: `${PENDING_ID_PREFIX}${pendingCount}`,
    body,
    created: Math.floor(Date.now() / 1000),
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
  // The plain `baseQuery`, not `baseQueryWithAutoSaves`: a comment is not part
  // of the page's auto-saved data, so it must stay out of the auto-save
  // conflict protocol.
  baseQuery,
  tagTypes: ['Comments'],
  endpoints: (builder) => ({
    getComments: builder.query<
      { threads: CommentThread[] },
      CommentSurfaceArgs
    >({
      query: ({ surfaceType, surfaceId }) => ({
        url: ENDPOINT,
        params: { surfaceType, surfaceId },
      }),
      providesTags: invalidatesCommentList,
    }),
    // Not tagged: posting a comment does not change who can be mentioned.
    getMentionableUsers: builder.query<
      { users: CommentAuthor[] },
      CommentSurfaceArgs & { query: string }
    >({
      query: ({ surfaceType, surfaceId, query }) => ({
        url: `${ENDPOINT}/mentionable-users`,
        params: { surfaceType, surfaceId, q: query },
      }),
    }),
    createThread: builder.mutation<ThreadResponse, CreateThreadArgs>({
      query: ({
        surfaceType,
        surfaceId,
        componentUuid,
        offsetX,
        offsetY,
        body,
      }) => ({
        url: ENDPOINT,
        method: 'POST',
        body: { surfaceType, surfaceId, componentUuid, offsetX, offsetY, body },
      }),
      invalidatesTags: invalidatesCommentList,
      onQueryStarted(args, api) {
        const comment = buildPendingComment(args);
        const pending: CommentThread = {
          id: comment.id,
          surfaceType: args.surfaceType,
          surfaceId: args.surfaceId,
          componentUuid: args.componentUuid,
          offsetX: args.offsetX ?? null,
          offsetY: args.offsetY ?? null,
          resolved: false,
          created: comment.created,
          author: comment.author,
          comments: [comment],
        };
        return showAhead(api, pending.id, (threads, list) => {
          if (
            list.surfaceType === args.surfaceType &&
            list.surfaceId === args.surfaceId
          ) {
            threads.push(pending);
          }
        });
      },
    }),
    replyToThread: builder.mutation<
      ThreadResponse,
      CommentBodyArgs & { threadId: string }
    >({
      query: ({ threadId, body }) => ({
        url: `${ENDPOINT}/${encodeURIComponent(threadId)}/replies`,
        method: 'POST',
        body: { body },
      }),
      invalidatesTags: invalidatesCommentList,
      onQueryStarted(args, api) {
        const comment = buildPendingComment(args);
        return showAhead(api, args.threadId, (threads) => {
          threads
            .find(({ id }) => id === args.threadId)
            ?.comments.push(comment);
        });
      },
    }),
    setThreadResolved: builder.mutation<
      ThreadResponse,
      { threadId: string; resolved: boolean }
    >({
      query: ({ threadId, resolved }) => ({
        url: `${ENDPOINT}/${encodeURIComponent(threadId)}`,
        method: 'PATCH',
        body: { resolved },
      }),
      invalidatesTags: invalidatesCommentList,
      onQueryStarted: ({ threadId, resolved }, api) =>
        showAhead(api, threadId, (threads) => {
          const thread = threads.find(({ id }) => id === threadId);
          if (thread) {
            thread.resolved = resolved;
          }
        }),
    }),
  }),
});

type ThreadListRecipe = (
  threads: CommentThread[],
  list: CommentSurfaceArgs,
) => void;

/**
 * Changes every cached thread list.
 *
 * @param recipe - The change, given a list's threads and what it lists.
 * @returns A thunk that applies the change and returns how to undo it.
 */
function patchThreadLists(recipe: ThreadListRecipe) {
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

/**
 * Shows a change at once, then swaps in the thread the server answers with.
 *
 * The change is taken back out if the server refuses it.
 *
 * @param api - The mutation's lifecycle API.
 * @param threadId - The thread, pending or real, the server's thread replaces.
 * @param recipe - The change to show ahead of the server.
 */
async function showAhead(
  {
    dispatch,
    queryFulfilled,
  }: {
    dispatch: ThunkDispatch<any, any, UnknownAction>;
    queryFulfilled: PromiseLike<{ data: ThreadResponse }>;
  },
  threadId: string,
  recipe: ThreadListRecipe,
) {
  const undo = dispatch(patchThreadLists(recipe));
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
}

export const {
  useGetCommentsQuery,
  useGetMentionableUsersQuery,
  useCreateThreadMutation,
  useReplyToThreadMutation,
  useSetThreadResolvedMutation,
} = commentsApi;
