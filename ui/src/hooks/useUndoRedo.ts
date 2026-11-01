import { useCallback } from 'react';

import { useAppDispatch, useAppSelector, useAppStore } from '@/app/hooks';
import {
  selectRedoItem,
  selectUndoItem,
  UndoRedoActionCreators,
} from '@/features/ui/uiSlice';
import { sendQueuedComponentPatches } from '@/services/preview';

interface UndoRedoState {
  isUndoable: boolean;
  isRedoable: boolean;
  dispatchUndo: () => Promise<void>;
  dispatchRedo: () => Promise<void>;
  dispatchClearUndoRedoHistory: () => void;
}

export function useUndoRedo(): UndoRedoState {
  const dispatch = useAppDispatch();
  const store = useAppStore();
  const undoItem = useAppSelector(selectUndoItem);
  const redoItem = useAppSelector(selectRedoItem);

  // Component changes waiting to be sent are saved and added to the history
  // first, so they are not saved over the restored state afterwards.
  const dispatchUndo = useCallback(async () => {
    await sendQueuedComponentPatches();
    const item = selectUndoItem(store.getState());
    if (item) {
      dispatch(UndoRedoActionCreators.undo(item.targetSlice));
    }
  }, [dispatch, store]);

  const dispatchRedo = useCallback(async () => {
    await sendQueuedComponentPatches();
    const item = selectRedoItem(store.getState());
    if (item) {
      dispatch(UndoRedoActionCreators.redo(item.targetSlice));
    }
  }, [dispatch, store]);

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
