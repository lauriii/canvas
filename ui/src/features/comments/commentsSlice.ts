import { createAppSlice } from '@/app/createAppSlice';

import type { PayloadAction } from '@reduxjs/toolkit';

export type CommentFilter = 'open' | 'resolved';

export interface CommentsState {
  /** True while the editor is in "click the canvas to comment" mode. */
  commentModeActive: boolean;
  /**
   * True while the comments tab of the contextual panel is the active one.
   *
   * The contextual panel keeps its other tabs in local state. This one is in
   * the store because the on-canvas pin layer reads it too.
   */
  panelOpen: boolean;
  /** The thread whose replies are expanded, or `null` when none is. */
  activeThreadId: string | null;
  filter: CommentFilter;
}

export const initialState: CommentsState = {
  commentModeActive: false,
  panelOpen: false,
  activeThreadId: null,
  filter: 'open',
};

// The `comments/` action-type prefix keeps comments out of the undo timeline:
// `undoRedoActionIdMiddleware` in `@/app/store` only pushes an undo entry for
// actions prefixed `layoutModel/` or `pageData/`.
// @see ui/src/features/comments/commentsSlice.test.ts
export const commentsSlice = createAppSlice({
  name: 'comments',
  initialState,
  reducers: (create) => ({
    setCommentMode: create.reducer((state, action: PayloadAction<boolean>) => {
      state.commentModeActive = action.payload;
      // The thread the next click creates is listed in the panel.
      if (action.payload) {
        state.panelOpen = true;
      }
    }),
    setCommentsPanelOpen: create.reducer(
      (state, action: PayloadAction<boolean>) => {
        state.panelOpen = action.payload;
        // Comment mode only makes sense alongside the panel it feeds.
        if (!action.payload) {
          state.commentModeActive = false;
        }
      },
    ),
    setActiveThread: create.reducer(
      (state, action: PayloadAction<string | null>) => {
        state.activeThreadId = action.payload;
      },
    ),
    setCommentFilter: create.reducer(
      (state, action: PayloadAction<CommentFilter>) => {
        state.filter = action.payload;
      },
    ),
  }),
  selectors: {
    selectCommentModeActive: (comments): boolean => comments.commentModeActive,
    selectCommentsPanelOpen: (comments): boolean => comments.panelOpen,
    selectActiveThreadId: (comments): string | null => comments.activeThreadId,
    selectCommentFilter: (comments): CommentFilter => comments.filter,
  },
});

export const {
  setCommentMode,
  setCommentsPanelOpen,
  setActiveThread,
  setCommentFilter,
} = commentsSlice.actions;

export const {
  selectCommentModeActive,
  selectCommentsPanelOpen,
  selectActiveThreadId,
  selectCommentFilter,
} = commentsSlice.selectors;
