import { useEffect } from 'react';
import { useHotkeys } from 'react-hotkeys-hook';
import { ResetIcon } from '@radix-ui/react-icons';
import { Button } from '@radix-ui/themes';

import { useUndoRedo } from '@/hooks/useUndoRedo';

import styles from '@/components/topbar/Topbar.module.css';

const UndoRedo = () => {
  const { isUndoable, isRedoable, dispatchUndo, dispatchRedo } = useUndoRedo();

  // The useHotKeys hook listens to the parent document.
  // 'mod' listens for cmd on Mac and ctrl on Windows.
  useHotkeys(
    'mod+z',
    () => {
      dispatchUndo();
    },
    {
      // Enable hotkeys on form tags. Ignore the default behavior of the browser and
      // have redux handle the undo so it's correctly added to redux's history.
      enableOnFormTags: true,
      preventDefault: true,
    },
  );

  // Mac redo is cmd+shift+z, Windows redo is ctrl+y.
  useHotkeys(
    ['meta+shift+z', 'ctrl+y'],
    () => {
      dispatchRedo();
    },
    {
      enableOnFormTags: true,
      preventDefault: true,
    },
  );

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
        size="1"
        className={styles.topBarButton}
        onClick={() => dispatchUndo()}
        disabled={!isUndoable}
        aria-label="Undo"
      >
        <ResetIcon height="16" width="auto" />
      </Button>
      <Button
        variant="ghost"
        color="gray"
        size="1"
        className={styles.topBarButton}
        onClick={() => dispatchRedo()}
        disabled={!isRedoable}
        aria-label="Redo"
      >
        <ResetIcon
          height="16"
          width="auto"
          style={{ transform: 'scaleX(-1)' }}
        />
      </Button>
    </>
  );
};

export default UndoRedo;
