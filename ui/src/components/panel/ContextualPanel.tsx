import {
  Box,
  Flex,
  Grid,
  IconButton,
  Inset,
  Text,
  ScrollArea,
  SegmentedControl,
  Separator,
} from '@radix-ui/themes';
import styles from './Panel.module.css';
import { Cross1Icon, DragHandleVerticalIcon } from '@radix-ui/react-icons';
import type React from 'react';
import { useState } from 'react';
import { useEffect } from 'react';
import {
  selectSelectedComponent,
  setContextualPanelOpen,
  setSelectedComponent,
} from '@/features/ui/uiSlice';
import { useAppDispatch, useAppSelector } from '@/app/hooks';
import ErrorBoundary from '@/components/error/ErrorBoundary';
import DummyPropsEditForm from '@/components/DummyPropsEditForm';
import clsx from 'clsx';

interface ContextualPanelProps {}

const ContextualPanel: React.FC<ContextualPanelProps> = () => {
  const dispatch = useAppDispatch();
  const selectedComponent = useAppSelector(selectSelectedComponent);
  const [activePanel, setActivePanel] = useState('settings');

  useEffect(() => {
    if (selectedComponent) {
      dispatch(setContextualPanelOpen(true));
    } else {
      dispatch(setContextualPanelOpen(false));
    }
  }, [selectedComponent, dispatch]);

  const handleContextualPanelCloseClick = () => {
    dispatch(setSelectedComponent(''));
  };

  return (
    <Box data-testid="xb-contextual-panel" className={styles.contextualPanel}>
      <Flex p="1" justify="end">
        <IconButton
          size="1"
          aria-label="Close"
          onClick={() => handleContextualPanelCloseClick()}
        >
          <Cross1Icon />
        </IconButton>
      </Flex>
      <ErrorBoundary>
        <Grid height="100%" rows="1" columns="1" gap="2" p="0">
          <Inset
            clip="padding-box"
            side="left"
            pb="current"
            className={styles.cardInset}
          >
            <Grid height="100%" p="0" columns="12px 1fr" gap="0">
              <Box height="100%">
                <Flex
                  justify="center"
                  align="center"
                  className={styles.handleContainer}
                >
                  <DragHandleVerticalIcon className={styles.handleIcon} />
                </Flex>
              </Box>
              <Box data-testid={`xb-contextual-panel-${selectedComponent}`}>
                <Flex justify="center" align="center">
                  <SegmentedControl.Root
                    defaultValue="settings"
                    onValueChange={setActivePanel}
                    className={clsx(styles.segmentedControlRoot)}
                  >
                    <SegmentedControl.Item value="settings">
                      Settings
                    </SegmentedControl.Item>
                    <SegmentedControl.Item value="pageSettings">
                      Page Data
                    </SegmentedControl.Item>
                  </SegmentedControl.Root>
                </Flex>
                <Separator orientation="horizontal" size="4" />
                <ScrollArea type="always" size="1" scrollbars="vertical">
                  <Box pt="3">
                    {activePanel === 'settings' && (
                      <>
                        <ErrorBoundary title="An unexpected error has occurred while rendering the component's form.">
                          <DummyPropsEditForm />
                        </ErrorBoundary>
                      </>
                    )}
                    {activePanel === 'pageSettings' && (
                      <Text size="1">Styles for...{selectedComponent}</Text>
                    )}
                  </Box>
                </ScrollArea>
              </Box>
            </Grid>
          </Inset>
        </Grid>
      </ErrorBoundary>
    </Box>
  );
};
export default ContextualPanel;
