// cspell:ignore redoable
import { Button } from '@radix-ui/themes';
import { ActionCreators } from 'redux-undo';
import { ResetIcon } from '@radix-ui/react-icons';
import styles from '@/app/App.module.css';
import { useAppDispatch, useAppSelector } from '@/app/hooks';
import { selectHistory } from '@/features/layout/layoutModelSlice';
import { useHotkeys } from 'react-hotkeys-hook';
import { useEffect } from 'react';

const UndoRedo = () => {
  const dispatch = useAppDispatch();
  const layoutModel = useAppSelector(selectHistory);
  const isUndoable = layoutModel.past.length > 0;
  const isRedoable = layoutModel.future.length > 0;
  const dispatchUndo = () =>
    isUndoable ? dispatch(ActionCreators.undo()) : null;
  const dispatchRedo = () =>
    isRedoable ? dispatch(ActionCreators.redo()) : null;
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
      <Button size="1" onClick={() => dispatchUndo()} disabled={!isUndoable}>
        <ResetIcon />
        Undo
      </Button>
      <Button size="1" onClick={() => dispatchRedo()} disabled={!isRedoable}>
        <ResetIcon className={styles.topBarRedoIcon} />
        Redo
      </Button>
    </>
  );
};

export default UndoRedo;
