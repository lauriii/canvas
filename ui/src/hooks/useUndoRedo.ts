import { useCallback } from 'react';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import {
  selectIsInlineTextBusy,
  selectRedoItem,
  selectUndoItem,
  UndoRedoActionCreators,
} from '@/features/ui/uiSlice';

interface UndoRedoState {
  isUndoable: boolean;
  isRedoable: boolean;
  dispatchUndo: () => void;
  dispatchRedo: () => void;
  dispatchClearUndoRedoHistory: () => void;
}

export function useUndoRedo(): UndoRedoState {
  const dispatch = useAppDispatch();
  // Text edited in place, or its commit in flight, is not in history yet:
  // undoing now would race the commit, whose response then lands on top.
  const isInlineTextBusy = useAppSelector(selectIsInlineTextBusy);
  const undoItem = useAppSelector(selectUndoItem);
  const redoItem = useAppSelector(selectRedoItem);

  const dispatchUndo = useCallback(() => {
    if (undoItem && !isInlineTextBusy) {
      dispatch(UndoRedoActionCreators.undo(undoItem.targetSlice));
    }
  }, [dispatch, isInlineTextBusy, undoItem]);

  const dispatchRedo = useCallback(() => {
    if (redoItem && !isInlineTextBusy) {
      dispatch(UndoRedoActionCreators.redo(redoItem.targetSlice));
    }
  }, [dispatch, isInlineTextBusy, redoItem]);

  const dispatchClearUndoRedoHistory = useCallback(() => {
    dispatch(UndoRedoActionCreators.clearHistory('layoutModel'));
    dispatch(UndoRedoActionCreators.clearHistory('pageData'));
  }, [dispatch]);

  return {
    isUndoable: undoItem !== undefined && !isInlineTextBusy,
    isRedoable: redoItem !== undefined && !isInlineTextBusy,
    dispatchUndo,
    dispatchRedo,
    dispatchClearUndoRedoHistory,
  };
}
