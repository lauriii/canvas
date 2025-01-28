import clsx from 'clsx';
import styles from '@/components/sidebar/PrimaryPanel.module.css';
import { Box, Flex, ScrollArea, Tabs } from '@radix-ui/themes';
import { useAppDispatch, useAppSelector } from '@/app/hooks';
import Panel from '../Panel';
import Library from '@/components/sidebar/Library';
import {
  selectActivePanel,
  setActivePanel,
} from '@/features/ui/primaryPanelSlice';
import useHidePanelClasses from '@/hooks/useHidePanelClasses';
import ErrorBoundary from '@/components/error/ErrorBoundary';
import Layers from '@/features/layout/layers/Layers';

export const PrimaryPanel = () => {
  const activePanel = useAppSelector(selectActivePanel);
  const dispatch = useAppDispatch();
  const offLeftClasses = useHidePanelClasses('left');

  const onValueChange = (selectedPanel: string) => {
    dispatch(setActivePanel(selectedPanel));
  };

  return (
    <Panel
      className={clsx(styles.primaryPanel, ...offLeftClasses)}
      data-testid="xb-primary-panel"
    >
      <Flex direction="column" height="100%">
        <Tabs.Root
          defaultValue={'layers'}
          onValueChange={onValueChange}
          value={activePanel}
          className={clsx(styles.tabRoot)}
        >
          <Tabs.List justify="center" mx="4">
            <Tabs.Trigger value="layers" data-testid="xb-primary-panel--layers">
              Layers
            </Tabs.Trigger>
            <Tabs.Trigger
              value="library"
              data-testid="xb-primary-panel--library"
            >
              Library
            </Tabs.Trigger>
          </Tabs.List>
          <ScrollArea scrollbars="both" className={styles.scrollArea}>
            <Box
              px="4"
              pt="4"
              className={clsx(
                'primaryPanelContent',
                styles.primaryPanelContent,
              )}
            >
              <Tabs.Content
                value={'layers'}
                className={styles.layersTabContent}
              >
                <ErrorBoundary>
                  <Layers />
                </ErrorBoundary>
              </Tabs.Content>
              <Tabs.Content value={'library'}>
                <Library />
              </Tabs.Content>
            </Box>
          </ScrollArea>
        </Tabs.Root>
      </Flex>
    </Panel>
  );
};

export default PrimaryPanel;
