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
import clsx from 'clsx';
import styles from './Panel.module.css';
import List from '@/components/list/List';
import { Cross1Icon, DragHandleVerticalIcon } from '@radix-ui/react-icons';
import type React from 'react';
import { useAppDispatch, useAppSelector } from '@/app/hooks';
import { setPrimaryPanelOpen, selectPrimaryPanelOpen } from '@/features/ui/uiSlice';
import TreeView from '@/features/layout/tree/TreeView';

interface PrimaryPanelProps {}

const PrimaryPanel: React.FC<PrimaryPanelProps> = () => {
  const dispatch = useAppDispatch();
  const primaryPanelOpen = useAppSelector(selectPrimaryPanelOpen);

  const handlePrimaryPanelOpenChange = (open: boolean) => {
    dispatch(setPrimaryPanelOpen(open));
  };

  return (
    <Drawer.Root
      direction="left"
      handleOnly={true}
      open={primaryPanelOpen}
      modal={false}
      onOpenChange={handlePrimaryPanelOpenChange}
    >
      <div className={styles.triggerContainer}>
        <Drawer.Trigger asChild={true}>
          <Button className={styles.sideBarTrigger} size="1">
            Open left
          </Button>
        </Drawer.Trigger>
      </div>
      <Drawer.Portal>
        <Theme>
          <Drawer.Content
            className={clsx(styles.sideBar, styles.sideBarLeft)}
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
                  side="right"
                  pb="current"
                  className={styles.cardInset}
                >
                  <Grid height="100%" p="1" columns="1fr 12px" gap="0">
                    <Box>
                      <Tabs.Root defaultValue="components">
                        <Tabs.List size="1">
                          <Tabs.Trigger value="components">
                            Components
                          </Tabs.Trigger>
                          <Tabs.Trigger value="layout">Layout</Tabs.Trigger>
                        </Tabs.List>

                        <Box pt="3">
                          <Tabs.Content value="components">
                            <Text size="2">Drag on components</Text>
                            <List />
                          </Tabs.Content>

                          <Tabs.Content value="layout">
                            <Text size="2">Rearrange your layout</Text>
                            <TreeView />
                          </Tabs.Content>
                        </Box>
                      </Tabs.Root>
                    </Box>
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

export default PrimaryPanel;
