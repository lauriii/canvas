import { describe, expect, it } from 'vitest';

import { makeStore } from '@/app/store';
import {
  clearActiveThread,
  commentsSlice,
  initialState,
  selectActiveThreadId,
  selectCommentFilter,
  selectCommentModeActive,
  setActiveThread,
  setCommentFilter,
  setCommentMode,
  toggleCommentMode,
} from '@/features/comments/commentsSlice';
import { setPageData } from '@/features/pageData/pageDataSlice';

describe('commentsSlice', () => {
  it('starts with comment mode off, no active thread and the open filter', () => {
    expect(commentsSlice.getInitialState()).toEqual({
      commentModeActive: false,
      panelOpen: false,
      activeThreadId: null,
      filter: 'open',
    });
  });

  it('setCommentMode sets comment mode explicitly', () => {
    const on = commentsSlice.reducer(initialState, setCommentMode(true));
    expect(on.commentModeActive).toBe(true);
    const off = commentsSlice.reducer(on, setCommentMode(false));
    expect(off.commentModeActive).toBe(false);
  });

  it('toggleCommentMode flips comment mode', () => {
    const on = commentsSlice.reducer(initialState, toggleCommentMode());
    expect(on.commentModeActive).toBe(true);
    expect(
      commentsSlice.reducer(on, toggleCommentMode()).commentModeActive,
    ).toBe(false);
  });

  it('setActiveThread and clearActiveThread manage the active thread', () => {
    const active = commentsSlice.reducer(initialState, setActiveThread('7'));
    expect(active.activeThreadId).toBe('7');
    expect(
      commentsSlice.reducer(active, clearActiveThread()).activeThreadId,
    ).toBeNull();
  });

  it('setCommentFilter switches between open and resolved', () => {
    const resolved = commentsSlice.reducer(
      initialState,
      setCommentFilter('resolved'),
    );
    expect(resolved.filter).toBe('resolved');
    expect(
      commentsSlice.reducer(resolved, setCommentFilter('open')).filter,
    ).toBe('open');
  });

  it('exposes selectors that read from the root state', () => {
    const store = makeStore();
    expect(selectCommentModeActive(store.getState())).toBe(false);
    expect(selectActiveThreadId(store.getState())).toBeNull();
    expect(selectCommentFilter(store.getState())).toBe('open');

    store.dispatch(setCommentMode(true));
    store.dispatch(setActiveThread('42'));
    store.dispatch(setCommentFilter('resolved'));

    expect(selectCommentModeActive(store.getState())).toBe(true);
    expect(selectActiveThreadId(store.getState())).toBe('42');
    expect(selectCommentFilter(store.getState())).toBe('resolved');
  });
});

describe('comments are outside the undo timeline', () => {
  // Structural guarantee that undo can never delete a comment:
  // `undoRedoActionIdMiddleware` only pushes undo entries for actions prefixed
  // `layoutModel/` or `pageData/`. This test fails if the comments slice is
  // ever renamed into one of those prefixes.
  // @see ui/src/app/store.ts
  it('every comments action type uses the "comments/" prefix', () => {
    const actionTypes = [
      setCommentMode(true),
      toggleCommentMode(),
      setActiveThread('1'),
      clearActiveThread(),
      setCommentFilter('resolved'),
    ].map((action) => action.type);
    actionTypes.forEach((type) => {
      expect(type.startsWith('comments/')).toBe(true);
    });
  });

  it('dispatching comments actions adds nothing to the undo stack', () => {
    const store = makeStore();
    const before = store.getState().ui.undoStack.length;

    store.dispatch(setCommentMode(true));
    store.dispatch(toggleCommentMode());
    store.dispatch(setActiveThread('9'));
    store.dispatch(setCommentFilter('resolved'));
    store.dispatch(clearActiveThread());

    expect(store.getState().ui.undoStack.length).toBe(before);
    // The state did change, so the assertion above is not vacuous.
    expect(store.getState().comments.filter).toBe('resolved');
  });

  it('a pageData action still adds to the undo stack', () => {
    // Guards the test above: proves the undo stack is reachable from makeStore,
    // so an unchanged length after a comments action is meaningful.
    const store = makeStore();
    const before = store.getState().ui.undoStack.length;
    store.dispatch(setPageData({ title: [{ value: 'New title' }] }));
    expect(store.getState().ui.undoStack.length).toBe(before + 1);
  });
});
