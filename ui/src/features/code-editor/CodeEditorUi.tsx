import { useState } from 'react';
import { Panel, PanelGroup, PanelResizeHandle } from 'react-resizable-panels';
import { useParams } from 'react-router';
import { DragHandleDots1Icon, ViewVerticalIcon } from '@radix-ui/react-icons';
import { Box, Button, Flex, Heading, Tabs } from '@radix-ui/themes';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import {
  selectCodeComponentProperty,
  selectCodeComponentSerialized,
} from '@/features/code-editor/codeEditorSlice';
import ComponentData from '@/features/code-editor/component-data/ComponentData';
import CssEditor from '@/features/code-editor/editors/CssEditor';
import GlobalCssEditor from '@/features/code-editor/editors/GlobalCssEditor';
import JavaScriptEditor from '@/features/code-editor/editors/JavaScriptEditor';
import useCodeEditor from '@/features/code-editor/hooks/useCodeEditor';
import Preview from '@/features/code-editor/Preview';
import ConflictWarning from '@/features/editor/ConflictWarning';
import { selectLatestError } from '@/features/error-handling/queryErrorSlice';
import { openAddToComponentsDialog } from '@/features/ui/codeComponentDialogSlice';

import styles from './CodeEditorUi.module.css';

const CodeEditorUi = () => {
  const [maximizedEditorLayout, setMaximizedEditorLayout] = useState(false);
  const [activeTab, setActiveTab] = useState('js');
  const dispatch = useAppDispatch();
  const selectedComponent = useAppSelector(selectCodeComponentSerialized);
  const componentStatus = useAppSelector(selectCodeComponentProperty('status'));
  const latestError = useAppSelector(selectLatestError);
  const { codeComponentId } = useParams();
  const { isLoading } = useCodeEditor();

  // Check for conflict errors (same as Editor.tsx)
  if (latestError && latestError.status === '409') {
    return <ConflictWarning />;
  }

  if (!codeComponentId) {
    return null;
  }

  const TabGroup = () => {
    function tabChangeHandler(selectedTab: string) {
      setActiveTab(selectedTab);
    }
    return (
      <Tabs.Root
        className={styles.tabRoot}
        onValueChange={tabChangeHandler}
        value={activeTab}
      >
        <Tabs.List size="1" className={styles.tabList} ml="2">
          <Tabs.Trigger value="js" className={styles.tabTrigger}>
            JavaScript
          </Tabs.Trigger>
          <Tabs.Trigger value="css" className={styles.tabTrigger}>
            CSS
          </Tabs.Trigger>
          <Tabs.Trigger value="global-css" className={styles.tabTrigger}>
            Global CSS
          </Tabs.Trigger>
        </Tabs.List>
      </Tabs.Root>
    );
  };

  const ToggleLayoutButton = () => {
    function toggleLayout() {
      setMaximizedEditorLayout((prev) => !prev);
    }

    return (
      <div className="canvas-code-editor-toggle-layout">
        <Button
          onClick={toggleLayout}
          aria-label="Toggle button for code editor view"
          variant="ghost"
          color="gray"
          mr="2"
        >
          <ViewVerticalIcon />
        </Button>
      </div>
    );
  };

  return (
    <Flex
      flexGrow="1"
      id="canvas-code-editor-container"
      data-testid="canvas-code-editor-container"
      style={{ overflow: 'hidden' }}
    >
      {/* Overflow is needed on the panel parent to stop the layout breaking when you have long lines of code in the editor.   */}
      <PanelGroup direction="horizontal">
        {/* Left Panel */}
        <Panel
          className={styles.codeEditorPanel}
          data-testid="canvas-code-editor-main-panel"
        >
          <Flex
            py="4"
            flexGrow="1"
            direction="column"
            height="100%"
            width="100%"
            pr={maximizedEditorLayout ? '2' : '0'}
          >
            <Flex pl="4">
              <Heading as="h5" size="2" weight="medium">
                Editor
              </Heading>
              <Flex flexGrow="1" direction="row-reverse">
                <ToggleLayoutButton />
              </Flex>
            </Flex>
            <TabGroup />
            <Flex
              width="100%"
              height="calc(100% - 38px)"
              style={{ position: 'relative' }}
            >
              {activeTab === 'js' && <JavaScriptEditor isLoading={isLoading} />}
              {activeTab === 'css' && <CssEditor isLoading={isLoading} />}
              {activeTab === 'global-css' && (
                <GlobalCssEditor isLoading={isLoading} />
              )}
            </Flex>
          </Flex>
        </Panel>
        {maximizedEditorLayout ? null : (
          <>
            <PanelResizeHandle className={styles.resizeHandle}>
              <DragHandleDots1Icon className={styles.resizeHandleThumb} />
            </PanelResizeHandle>
            <Panel>
              <PanelGroup direction="vertical">
                {/* Top Right Panel */}
                <Panel data-testid="canvas-code-editor-preview-panel">
                  <Flex
                    px="4"
                    pt="4"
                    pb="2"
                    flexGrow="1"
                    direction="column"
                    height="100%"
                  >
                    {componentStatus === false && (
                      <Box
                        pb="4"
                        mb="4"
                        className={styles.addToComponentsButton}
                      >
                        <Button
                          onClick={() => {
                            dispatch(
                              openAddToComponentsDialog(selectedComponent),
                            );
                          }}
                        >
                          Add to components
                        </Button>
                      </Box>
                    )}
                    <Heading as="h5" size="2" weight="medium" mb="4">
                      Preview
                    </Heading>
                    <Preview isLoading={isLoading} />
                  </Flex>
                </Panel>
                <PanelResizeHandle className={styles.resizeHandle}>
                  <DragHandleDots1Icon className={styles.resizeHandleThumb} />
                </PanelResizeHandle>
                {/* Bottom Right Panel */}
                <Panel data-testid="canvas-code-editor-component-data-panel">
                  <Flex
                    px="4"
                    pt="4"
                    flexGrow="1"
                    direction="column"
                    height="100%"
                  >
                    <ComponentData isLoading={isLoading} />
                  </Flex>
                </Panel>
              </PanelGroup>
            </Panel>
          </>
        )}
      </PanelGroup>
    </Flex>
  );
};

export default CodeEditorUi;
