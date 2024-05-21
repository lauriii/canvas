import styles from "./App.module.css";
import { useRef, useState} from "react";
import Preview from "./features/layout/preview/Preview";
import TreeView from "./features/layout/tree/TreeView";
import List from "./features/list/List";
import Layout from "./features/layout/Layout";
import {Button, Theme, Flex, Card, Grid} from "@radix-ui/themes";
import {Drawer} from 'vaul';
import {DragHandleVerticalIcon} from '@radix-ui/react-icons'
import classNames from "classnames";

const App = () => {
  const iframeRef = useRef(null);
  const [leftOpen, setLeftOpen] = useState(false)
  const [rightOpen, setRightOpen] = useState(false)

  const handleLeftDrawerOpenChange = (open: boolean) => {
    console.log('left', open)
    setLeftOpen(open);

  }

  const handleRightDrawerOpenChange = (open: boolean) => {
    console.log('right', open)
    setRightOpen(open);
  }

  return (
    <div className={styles.app}>
      <Layout/>
      <div className={classNames(styles.appContainer)}>
        <div className={styles.topBar}>
          <Flex gap="3">
            <Drawer.Root direction="left" handleOnly={true} modal={false} onOpenChange={handleLeftDrawerOpenChange}>
              <Drawer.Trigger asChild={true}><Button size="1">Open left</Button></Drawer.Trigger>
              <Drawer.Portal>
                <Theme>
                  <Drawer.Content className={classNames([styles.sideBar, styles.sideBarLeft])}>
                    <Grid height="100%" p="1">
                      <Card variant="classic">
                        <Flex height="100%">
                          <List/>
                          <Flex justify="center" align="center" className={styles.handleContainer}>
                            <Drawer.Handle></Drawer.Handle>
                            <DragHandleVerticalIcon className={styles.handleIcon}/>
                          </Flex>
                        </Flex>
                      </Card>
                    </Grid>
                  </Drawer.Content>
                  <Drawer.Overlay/>
                </Theme>
              </Drawer.Portal>

            </Drawer.Root>
            <Drawer.Root direction="right" handleOnly={true} modal={false} onOpenChange={handleRightDrawerOpenChange}>
              <Drawer.Trigger asChild={true}><Button size="1">Open right</Button></Drawer.Trigger>
              <Drawer.Portal>
                <Theme>
                  <Drawer.Content className={classNames(styles.sideBar, styles.sideBarRight)}>
                    <Grid height="100%" p="1">
                      <Card>
                        <Flex height="100%">
                          <Flex justify="center" align="center" className={styles.handleContainer}>
                            <Drawer.Handle></Drawer.Handle>
                            <DragHandleVerticalIcon className={styles.handleIcon}/>
                          </Flex>
                          <div>
                            <h2>Layout</h2>
                            <TreeView/>
                          </div>
                        </Flex>
                      </Card>
                    </Grid>
                  </Drawer.Content>
                  <Drawer.Overlay/>
                </Theme>
              </Drawer.Portal>
            </Drawer.Root>
          </Flex>
        </div>
        <div className={classNames(styles.previewContainer, {
          [styles.leftSideBarOpen]: leftOpen,
          [styles.rightSideBarOpen]: rightOpen,
        })}>
          <Preview iframeRef={iframeRef}/>
        </div>
      </div>

    </div>
  );
};

export default App;
