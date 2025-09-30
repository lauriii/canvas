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
}

export function useUndoRedo(): UndoRedoState {
  const dispatch = useAppDispatch();
  const undoItem = useAppSelector(selectUndoItem);
  const redoItem = useAppSelector(selectRedoItem);

  const dispatchUndo = () =>
    undoItem
      ? dispatch(UndoRedoActionCreators.undo(undoItem.targetSlice))
      : null;

  const dispatchRedo = () =>
    redoItem
      ? dispatch(UndoRedoActionCreators.redo(redoItem.targetSlice))
      : null;

  return {
    isUndoable: undoItem !== undefined,
    isRedoable: redoItem !== undefined,
    dispatchUndo,
    dispatchRedo,
  };
}
