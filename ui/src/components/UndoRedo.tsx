// cspell:ignore redoable
import { Button } from '@radix-ui/themes';
import { ResetIcon } from '@radix-ui/react-icons';
import { useAppDispatch, useAppSelector } from '@/app/hooks';
import { selectLayoutHistory } from '@/features/layout/layoutModelSlice';
import { selectPageDataHistory } from '@/features/pageData/pageDataSlice';
import { useHotkeys } from 'react-hotkeys-hook';
import { useEffect } from 'react';
import { UndoRedoActionCreators } from '@/features/ui/uiSlice';
import { selectUndoType, selectRedoType } from '@/features/ui/uiSlice';
import clsx from 'clsx';
import styles from '@/components/topbar/Topbar.module.css';

const UndoRedo = () => {
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
  // The useHotKeys hook listens to the parent document.
  useHotkeys('mod+z', () => dispatchUndo()); // 'mod' listens for cmd on Mac and ctrl on Windows.
  useHotkeys(['meta+shift+z', 'ctrl+y'], () => dispatchRedo()); // Mac redo is cmd+shift+z, Windows redo is ctrl+y.

  // Add an event listener for a message from the iFrame that a user used hot keys for undo/redo
  // while inside the iFrame.
  useEffect(() => {
    function dispatchUndoRedo(event: MessageEvent) {
      if (event.data === 'dispatchUndo') {
        dispatchUndo();
      }
      if (event.data === 'dispatchRedo') {
        dispatchRedo();
      }
    }
    window.addEventListener('message', dispatchUndoRedo);
    return () => {
      window.removeEventListener('message', dispatchUndoRedo);
    };
  });

  return (
    <>
      <Button
        variant="ghost"
        color="gray"
        size="2"
        className={clsx(styles.topBarButton)}
        onClick={() => dispatchUndo()}
        disabled={!isUndoable}
        aria-label="Undo"
      >
        <ResetIcon height="24" width="auto" />
      </Button>
      <Button
        variant="ghost"
        color="gray"
        size="2"
        className={clsx(styles.topBarButton)}
        onClick={() => dispatchRedo()}
        disabled={!isRedoable}
        aria-label="Redo"
      >
        <ResetIcon
          height="24"
          width="auto"
          style={{ transform: 'scaleX(-1)' }}
        />
      </Button>
    </>
  );
};

export default UndoRedo;
