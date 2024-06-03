import styles from './App.module.css';
import { useEffect, useRef, useState } from 'react';
import Preview from './features/layout/preview/Preview';
import Layout from './features/layout/Layout';
import { Button, Theme, Flex, Card, Grid } from '@radix-ui/themes';
import classNames from 'classnames';
import PrimaryPanel from './features/panel/PrimaryPanel';
import ContextualPanel from './features/panel/ContextualPanel';
import { useAppSelector } from './app/hooks';
import { selectSelectedComponent } from './features/ui/uiSlice';

const App = () => {
  const iframeRef = useRef(null);
  const [primaryPanelOpen, setPrimaryPanelOpen] = useState(true);
  const [contextualPanelOpen, setContextualPanelOpen] = useState(false);

  const selectedComponent = useAppSelector(selectSelectedComponent);

  useEffect(() => {
    if (selectedComponent) {
      setContextualPanelOpen(true);
    } else {
      setContextualPanelOpen(false);
    }
  }, [selectedComponent]);

  return (
    <div
      className={classNames(styles.app, {
        [styles.leftSideBarOpen]: primaryPanelOpen,
        [styles.rightSideBarOpen]: contextualPanelOpen,
      })}
    >
      <Layout />
      <div className={styles.topBar}>
        <Flex gap="3">
          <Button size="1" onClick={() => setContextualPanelOpen(true)}>
            Open Right
          </Button>
        </Flex>
      </div>
      <div className={classNames(styles.previewContainer)}>
        <Preview iframeRef={iframeRef} />
      </div>
      <PrimaryPanel open={primaryPanelOpen} setOpen={setPrimaryPanelOpen} />
      <ContextualPanel
        open={contextualPanelOpen}
        setOpen={setContextualPanelOpen}
      />
    </div>
  );
};

export default App;
