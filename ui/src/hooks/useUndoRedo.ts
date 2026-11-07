import { useCallback } from 'react';

import { useAppDispatch, useAppSelector, useAppStore } from '@/app/hooks';
import {
  selectRedoItem,
  selectUndoItem,
  UndoRedoActionCreators,
} from '@/features/ui/uiSlice';
import { isOtherEntity } from '@/services/baseQuery';
import { clearSavedModels, runComponentWrite } from '@/services/preview';

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

  // As component writes, so changes waiting to be sent are saved and added to
  // the history first, rather than saved over the restored state afterwards.
  const dispatchUndo = useCallback(() => {
    // Undo is for the entity edited now, even if it waits.
    const editorUrl = window.location.href;
    return runComponentWrite(async () => {
      const item = selectUndoItem(store.getState());
      if (item && !isOtherEntity(editorUrl)) {
        dispatch(UndoRedoActionCreators.undo(item.targetSlice));
        // Recorded saved models predate the restored ones, which the
        // following layout POST saves. Changes queued next build on their own
        // models instead.
        clearSavedModels();
      }
    });
  }, [dispatch, store]);

  const dispatchRedo = useCallback(() => {
    const editorUrl = window.location.href;
    return runComponentWrite(async () => {
      const item = selectRedoItem(store.getState());
      if (item && !isOtherEntity(editorUrl)) {
        dispatch(UndoRedoActionCreators.redo(item.targetSlice));
        clearSavedModels();
      }
    });
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
