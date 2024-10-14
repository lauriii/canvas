import {
  Flex,
  Text,
  ScrollArea,
  SegmentedControl,
  Separator,
  Box,
} from '@radix-ui/themes';
import styles from './ContextualPanel.module.css';
import type React from 'react';
import { useState } from 'react';
import { selectSelectedComponent } from '@/features/ui/uiSlice';
import { useAppSelector } from '@/app/hooks';
import Panel from '@/components/Panel';
import ErrorBoundary from '@/components/error/ErrorBoundary';
import DummyPropsEditForm from '@/components/DummyPropsEditForm';
import clsx from 'clsx';

interface ContextualPanelProps {}

const ContextualPanel: React.FC<ContextualPanelProps> = () => {
  const selectedComponent = useAppSelector(selectSelectedComponent);
  const [activePanel, setActivePanel] = useState('settings');

  return (
    <Panel data-testid="xb-contextual-panel" className={styles.contextualPanel}>
      <Flex
        flexGrow="1"
        direction="column"
        pl="4"
        py="5"
        height="100%"
        data-testid={`xb-contextual-panel-${selectedComponent}`}
      >
        <ErrorBoundary>
          <Box pr="4">
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
          </Box>
          <ScrollArea>
            <Box pr="4">
              {activePanel === 'settings' && (
                <ErrorBoundary title="An unexpected error has occurred while rendering the component's form.">
                  <DummyPropsEditForm />
                </ErrorBoundary>
              )}
              {activePanel === 'pageSettings' && (
                <Text size="1">Styles for...{selectedComponent}</Text>
              )}
            </Box>
          </ScrollArea>
        </ErrorBoundary>
      </Flex>
    </Panel>
  );
};
export default ContextualPanel;
