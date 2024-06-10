import { Drawer } from 'vaul';
import {
  Box,
  Button,
  Card,
  Flex,
  Grid,
  IconButton,
  Inset,
  Tabs,
  Text,
  Theme,
} from '@radix-ui/themes';
import classNames from 'classnames';
import styles from './Panel.module.css';
import { Cross1Icon, DragHandleVerticalIcon } from '@radix-ui/react-icons';
import type React from 'react';
import { useAppDispatch, useAppSelector } from '@/app/hooks';
import { selectSelectedComponent } from '@/features/ui/uiSlice';
import { selectModel, updateNodeModel } from '@/features/layout/layoutModelSlice';

interface ContextualPanelProps {
  open: boolean;
  setOpen: React.SetStateAction<any>;
}

const ContextualPanel: React.FC<ContextualPanelProps> = (props) => {
  const { open, setOpen } = props;
  const dispatch = useAppDispatch();
  const selectedComponent = useAppSelector(selectSelectedComponent);
  const model = useAppSelector(selectModel);

  const handleContextualPanelOpenChange = (open: boolean) => {
    setOpen(open);
  };

  const handleEditClick = () => {
    dispatch(
      updateNodeModel({
        uuid: selectedComponent,
        // @ts-ignore
        model: { ...model[selectedComponent], name: 'FOO' },
      }),
    );
  };

  return (
    <Drawer.Root
      open={open}
      direction="right"
      handleOnly={true}
      modal={false}
      onOpenChange={handleContextualPanelOpenChange}
    >
      <Drawer.Portal>
        <Theme>
          <Drawer.Content
            className={classNames(styles.sideBar, styles.sideBarRight)}
          >
            <Grid height="100%" rows="1" columns="1" gap="2">
              <Card variant="classic">
                <Flex p="1" justify="end">
                  <Drawer.Close asChild={true}>
                    <IconButton size="1">
                      <Cross1Icon />
                    </IconButton>
                  </Drawer.Close>
                </Flex>
                <Inset
                  clip="padding-box"
                  side="left"
                  pb="current"
                  className={styles.cardInset}
                >
                  <Grid height="100%" p="1" columns="12px 1fr" gap="0">
                    <Box height="100%">
                      <Flex
                        justify="center"
                        align="center"
                        className={styles.handleContainer}
                      >
                        <Drawer.Handle></Drawer.Handle>
                        <DragHandleVerticalIcon className={styles.handleIcon} />
                      </Flex>
                    </Box>
                    {selectedComponent && (
                      <Box>
                        <Tabs.Root defaultValue="settings">
                          <Tabs.List size="1">
                            <Tabs.Trigger value="settings">
                              Settings
                            </Tabs.Trigger>
                            <Tabs.Trigger value="styles">Styles</Tabs.Trigger>
                          </Tabs.List>

                          <Box pt="3">
                            <Tabs.Content value="settings">
                              <Text size="1">
                                Settings for... {selectedComponent}
                                <pre>
                                  {JSON.stringify(
                                    model[selectedComponent],
                                    null,
                                    2,
                                  )}
                                </pre>
                              </Text>
                              <Button onClick={handleEditClick}>Edit</Button>
                            </Tabs.Content>

                            <Tabs.Content value="styles">
                              <Text size="1">
                                Styles for...{selectedComponent}
                              </Text>
                            </Tabs.Content>
                          </Box>
                        </Tabs.Root>
                      </Box>
                    )}
                  </Grid>
                </Inset>
              </Card>
            </Grid>
          </Drawer.Content>
          <Drawer.Overlay />
        </Theme>
      </Drawer.Portal>
    </Drawer.Root>
  );
};
export default ContextualPanel;
