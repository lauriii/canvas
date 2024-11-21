import { Flex, ScrollArea, Box, Tabs } from '@radix-ui/themes';
import styles from './ContextualPanel.module.css';
import type React from 'react';
import { useEffect } from 'react';
import { useState } from 'react';
import { selectSelectedComponent } from '@/features/ui/uiSlice';
import { useAppSelector } from '@/app/hooks';
import Panel from '@/components/Panel';
import ErrorBoundary from '@/components/error/ErrorBoundary';
import PageDataForm from '@/components/PageDataForm';
import clsx from 'clsx';
import useHidePanelClasses from '@/hooks/useHidePanelClasses';
import { Outlet } from 'react-router-dom';

interface ContextualPanelProps {}

const ContextualPanel: React.FC<ContextualPanelProps> = () => {
  const selectedComponent = useAppSelector(selectSelectedComponent);
  const [activePanel, setActivePanel] = useState('pageData');
  const offRightClasses = useHidePanelClasses('right');

  useEffect(() => {
    if (selectedComponent) {
      setActivePanel('settings');
    } else {
      setActivePanel('pageData');
    }
  }, [selectedComponent]);

  return (
    <Panel
      data-testid="xb-contextual-panel"
      className={clsx(styles.contextualPanel, ...offRightClasses)}
    >
      <Flex
        flexGrow="1"
        direction="column"
        height="100%"
        data-testid={`xb-contextual-panel-${selectedComponent}`}
      >
        <ErrorBoundary>
          <Tabs.Root
            defaultValue={'pageData'}
            onValueChange={setActivePanel}
            value={activePanel}
            className={clsx(styles.tabRoot)}
          >
            <Tabs.List justify="center" mx="4">
              <Tabs.Trigger
                value="pageData"
                data-testid="xb-contextual-panel--page-data"
              >
                Page data
              </Tabs.Trigger>
              <Tabs.Trigger
                value="settings"
                data-testid="xb-contextual-panel--settings"
                disabled={!selectedComponent}
              >
                Settings
              </Tabs.Trigger>
            </Tabs.List>
            <ScrollArea scrollbars="vertical" className={styles.scrollArea}>
              <Box px="4" width="100%">
                <Tabs.Content value={'settings'}>
                  <ErrorBoundary title="An unexpected error has occurred while rendering the component's form.">
                    <Outlet />
                  </ErrorBoundary>
                </Tabs.Content>
                <Tabs.Content value={'pageData'}>
                  <PageDataForm />
                </Tabs.Content>
              </Box>
            </ScrollArea>
          </Tabs.Root>
        </ErrorBoundary>
      </Flex>
    </Panel>
  );
};
export default ContextualPanel;
