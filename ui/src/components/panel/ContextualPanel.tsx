import {
  Flex,
  ScrollArea,
  Box,
  Tabs,
  Button,
  Text,
  Callout,
} from '@radix-ui/themes';
import styles from './ContextualPanel.module.css';
import type React from 'react';
import { useEffect } from 'react';
import { useState } from 'react';
import ErrorBoundary from '@/components/error/ErrorBoundary';
import PageDataForm from '@/components/PageDataForm';
import clsx from 'clsx';
import useHidePanelClasses from '@/hooks/useHidePanelClasses';
import { Outlet } from 'react-router-dom';
import { useAppDispatch, useAppSelector } from '@/app/hooks';
import { setCurrentComponent } from '@/features/form/formStateSlice';
import {
  selectSelectedComponentUuid,
  selectIsMultiSelect,
  selectSelection,
} from '@/features/ui/uiSlice';
import { InfoCircledIcon } from '@radix-ui/react-icons';

interface ContextualPanelProps {}

const ContextualPanel: React.FC<ContextualPanelProps> = () => {
  const selectedComponent = useAppSelector(selectSelectedComponentUuid);
  const isMultiSelect = useAppSelector(selectIsMultiSelect);
  const selection = useAppSelector(selectSelection);
  const dispatch = useAppDispatch();

  const [activePanel, setActivePanel] = useState('pageData');
  const offRightClasses = useHidePanelClasses('right');

  useEffect(() => {
    if (selectedComponent) {
      // One component is selected
      dispatch(setCurrentComponent(selectedComponent));
      setActivePanel('settings');
    } else if (isMultiSelect) {
      // Multiple components are selected
      dispatch(setCurrentComponent(''));
      setActivePanel('settings');
    } else {
      // No component is selected
      dispatch(setCurrentComponent(''));
      setActivePanel('pageData');
    }
  }, [dispatch, selectedComponent, isMultiSelect]);

  return (
    <Box
      data-testid="xb-contextual-panel"
      pt="2"
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
            <Tabs.List justify="start" mx="4" size="1">
              <Tabs.Trigger
                value="pageData"
                data-testid="xb-contextual-panel--page-data"
              >
                Page data
              </Tabs.Trigger>
              {(selectedComponent || isMultiSelect) && (
                <Tabs.Trigger
                  value="settings"
                  data-testid="xb-contextual-panel--settings"
                >
                  Settings
                </Tabs.Trigger>
              )}
            </Tabs.List>
            <ScrollArea scrollbars="vertical" className={styles.scrollArea}>
              <Box px="4" width="100%">
                <Tabs.Content value={'settings'}>
                  {isMultiSelect && (
                    <Box my="2">
                      <Flex direction="column" gap="2">
                        <Text align="center" color="gray" my="3" size="1">
                          {selection.items.length} items selected
                        </Text>

                        <Flex gap="1" justify="center" align="center">
                          <Button
                            size="1"
                            disabled={!selection.consecutive}
                            onClick={() =>
                              alert(
                                'Copy functionality will be implemented later',
                              )
                            }
                            className="xb-button"
                          >
                            Copy
                          </Button>
                          <Text size="1" color="gray">
                            or
                          </Text>
                          <Button
                            size="1"
                            disabled={!selection.consecutive}
                            onClick={() =>
                              alert(
                                'Save as Pattern functionality will be implemented later',
                              )
                            }
                            className="xb-button"
                          >
                            Save as Pattern
                          </Button>
                        </Flex>
                        {!selection.consecutive && (
                          <Callout.Root
                            size="1"
                            color="blue"
                            variant="surface"
                            mt="4"
                          >
                            <Callout.Icon>
                              <InfoCircledIcon />
                            </Callout.Icon>
                            <Callout.Text>
                              Actions are only available when selecting adjacent
                              items in the layout
                            </Callout.Text>
                          </Callout.Root>
                        )}
                      </Flex>
                    </Box>
                  )}
                  <ErrorBoundary title="An unexpected error has occurred while rendering the component's form.">
                    <Outlet />
                  </ErrorBoundary>
                </Tabs.Content>
                <Tabs.Content
                  value={'pageData'}
                  forceMount={true}
                  hidden={activePanel !== 'pageData'}
                >
                  <PageDataForm />
                </Tabs.Content>
              </Box>
            </ScrollArea>
          </Tabs.Root>
        </ErrorBoundary>
      </Flex>
    </Box>
  );
};
export default ContextualPanel;
