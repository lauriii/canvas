import { useAppDispatch, useAppSelector } from '@/app/hooks';
import {
  UndoRedoActionCreators,
  selectUndoType,
  selectRedoType,
} from '@/features/ui/uiSlice';
import { selectLayoutHistory } from '@/features/layout/layoutModelSlice';
import { selectPageDataHistory } from '@/features/pageData/pageDataSlice';

interface UndoRedoState {
  isUndoable: boolean;
  isRedoable: boolean;
  dispatchUndo: () => void;
  dispatchRedo: () => void;
}

export function useUndoRedo(): UndoRedoState {
  const dispatch = useAppDispatch();
  const layoutModel = useAppSelector(selectLayoutHistory);
  const pageData = useAppSelector(selectPageDataHistory);
  const undoType = useAppSelector(selectUndoType);
  const redoType = useAppSelector(selectRedoType);

  const isUndoable = layoutModel.past.length > 1 || pageData.past.length > 1;
  const isRedoable =
    layoutModel.future.length > 0 || pageData.future.length > 0;

  const dispatchUndo = () =>
    isUndoable && undoType
      ? dispatch(UndoRedoActionCreators.undo(undoType))
      : null;

  const dispatchRedo = () =>
    isRedoable && redoType
      ? dispatch(UndoRedoActionCreators.redo(redoType))
      : null;

  return {
    isUndoable,
    isRedoable,
    dispatchUndo,
    dispatchRedo,
  };
}
