import { useCallback } from 'react';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import {
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
  const undoItem = useAppSelector(selectUndoItem);
  const redoItem = useAppSelector(selectRedoItem);

  const dispatchUndo = useCallback(() => {
    if (undoItem) {
      dispatch(UndoRedoActionCreators.undo(undoItem.targetSlice));
    }
  }, [dispatch, undoItem]);

  const dispatchRedo = useCallback(() => {
    if (redoItem) {
      dispatch(UndoRedoActionCreators.redo(redoItem.targetSlice));
    }
  }, [dispatch, redoItem]);

  const dispatchClearUndoRedoHistory = useCallback(() => {
    dispatch(UndoRedoActionCreators.clearHistory('layoutModel'));
    dispatch(UndoRedoActionCreators.clearHistory('pageData'));
  }, [dispatch]);

  return {
    isUndoable: undoItem !== undefined,
    isRedoable: redoItem !== undefined,
    dispatchUndo,
    dispatchRedo,
    dispatchClearUndoRedoHistory,
  };
}
