import clsx from 'clsx';
import styles from '@/components/sidePanel/PrimaryPanel.module.css';
import { Box, Flex, ScrollArea, Tabs } from '@radix-ui/themes';
import { useAppDispatch, useAppSelector } from '@/app/hooks';
import Library from '@/components/sidePanel/Library';
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
    <Box
      className={clsx(styles.primaryPanel, ...offLeftClasses)}
      pt="3"
      data-testid="xb-primary-panel"
    >
      <Flex direction="column" height="100%">
        <Tabs.Root
          defaultValue={'layers'}
          onValueChange={onValueChange}
          value={activePanel}
          className={clsx(styles.tabRoot)}
        >
          <Tabs.List justify="start" mx="4" size="1">
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
    </Box>
  );
};

export default PrimaryPanel;
