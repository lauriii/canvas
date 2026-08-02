import { createAppSlice } from '@/app/createAppSlice';

import type { PayloadAction } from '@reduxjs/toolkit';

export type CommentFilter = 'open' | 'resolved';

export interface CommentsState {
  /** True while the editor is in "click the canvas to comment" mode. */
  commentModeActive: boolean;
  /**
   * True while the comments tab of the contextual panel is the active one.
   *
   * The contextual panel keeps its own tab in local state, but the on-canvas
   * pin layer has to know whether comments are on screen, so this one tab is
   * lifted into the store rather than drilled through the layout.
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

// The `comments/` action-type prefix is what keeps comments out of the undo
// timeline: `undoRedoActionIdMiddleware` in `@/app/store` only pushes an undo
// entry for actions prefixed `layoutModel/` or `pageData/`. This slice is
// therefore also registered OUTSIDE the `undoable()` wrappers in
// `combineSlices`. Renaming this slice into one of those prefixes would make
// undo able to destroy comments.
// @see ui/src/features/comments/commentsSlice.test.ts
export const commentsSlice = createAppSlice({
  name: 'comments',
  initialState,
  reducers: (create) => ({
    setCommentMode: create.reducer((state, action: PayloadAction<boolean>) => {
      state.commentModeActive = action.payload;
    }),
    toggleCommentMode: create.reducer((state) => {
      state.commentModeActive = !state.commentModeActive;
      // Turning comment mode on opens the panel, so the thread the next click
      // creates is visible in the list it lands in.
      if (state.commentModeActive) {
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
    setActiveThread: create.reducer((state, action: PayloadAction<string>) => {
      state.activeThreadId = action.payload;
    }),
    clearActiveThread: create.reducer((state) => {
      state.activeThreadId = null;
    }),
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

// Action creators are generated for each case reducer function.
export const {
  setCommentMode,
  toggleCommentMode,
  setCommentsPanelOpen,
  setActiveThread,
  clearActiveThread,
  setCommentFilter,
} = commentsSlice.actions;

// Selectors returned by `slice.selectors` take the root state as their first argument.
export const {
  selectCommentModeActive,
  selectCommentsPanelOpen,
  selectActiveThreadId,
  selectCommentFilter,
} = commentsSlice.selectors;
