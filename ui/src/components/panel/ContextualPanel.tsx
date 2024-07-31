import {
  Box,
  Button,
  Flex,
  Grid,
  IconButton,
  Inset,
  Tabs,
  Text,
  ScrollArea,
} from '@radix-ui/themes';
import styles from './Panel.module.css';
import { Cross1Icon, DragHandleVerticalIcon } from '@radix-ui/react-icons';
import type React from 'react';
import { useEffect, Suspense } from 'react';
import {
  selectSelectedComponent,
  setContextualPanelOpen,
  setSelectedComponent,
} from '@/features/ui/uiSlice';
import { useAppDispatch, useAppSelector } from '@/app/hooks';
import {
  selectModel,
  updateNodeModel,
} from '@/features/layout/layoutModelSlice';
import { useLoaderData, Await } from 'react-router-dom';
import DummyPropsEditForm from '@/components/DummyPropsEditForm';

interface ContextualPanelProps {}

const ContextualPanel: React.FC<ContextualPanelProps> = () => {
  const dispatch = useAppDispatch();
  const selectedComponent = useAppSelector(selectSelectedComponent);
  const model = useAppSelector(selectModel);
  let data = useLoaderData();

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

  const handleEditClick = () => {
    alert(
      ' TODO: This really ought to be removed, and instead the form above should update model values when the user types into the inputs!',
    );
    dispatch(
      updateNodeModel({
        uuid: selectedComponent,
        model: {
          // @ts-ignore
          ...model[selectedComponent],
          text: 'This here prop (text) was updated and re-rendered by the SDC!',
        },
      }),
    );
  };

  return (
    <Box className={styles.contextualPanel}>
      <Flex p="1" justify="end">
        <IconButton
          size="1"
          aria-label="Close"
          onClick={() => handleContextualPanelCloseClick()}
        >
          <Cross1Icon />
        </IconButton>
      </Flex>
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
            <Suspense fallback={<p>Loading component...</p>}>
              <Await
                // POC: In AppRoutes.tsx we are loading async data into data.html - this will only show the rest
                // of the markup once that promise is resolved. The purple <h4> renders the data as an example.
                // @ts-ignore - PoC only, if real would need TS definitions.
                resolve={data.html}
                errorElement={<p>Error loading Component ID!</p>}
              >
                {(html) => (
                  <>
                    <Box>
                      <Tabs.Root defaultValue="settings">
                        <Tabs.List size="1">
                          <Tabs.Trigger value="settings">Settings</Tabs.Trigger>
                          <Tabs.Trigger value="styles">Styles</Tabs.Trigger>
                        </Tabs.List>
                        <ScrollArea
                          type="always"
                          size="1"
                          scrollbars="vertical"
                          style={{ height: 700 }}
                        >
                          <Box pt="3">
                            <Tabs.Content value="settings">
                              <Text size="1">
                                <details>
                                  <summary>
                                    Settings for... {selectedComponent}
                                  </summary>
                                  {selectedComponent && (
                                    <pre>
                                      {JSON.stringify(
                                        model[selectedComponent],
                                        null,
                                        2,
                                      )}
                                    </pre>
                                  )}
                                </details>
                                <h4 style={{ color: '#8A00E6' }}>
                                  POC: {html}
                                </h4>
                                <DummyPropsEditForm />
                              </Text>
                              <Button
                                onClick={handleEditClick}
                                className={styles.editButton}
                              >
                                Edit
                              </Button>
                            </Tabs.Content>

                            <Tabs.Content value="styles">
                              <Text size="1">
                                Styles for...{selectedComponent}
                              </Text>
                            </Tabs.Content>
                          </Box>
                        </ScrollArea>
                      </Tabs.Root>
                    </Box>
                  </>
                )}
              </Await>
            </Suspense>
          </Grid>
        </Inset>
      </Grid>
    </Box>
  );
};
export default ContextualPanel;
