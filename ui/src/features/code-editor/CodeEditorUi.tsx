import { useCallback, useState } from 'react';
import { Allotment } from 'allotment';
import { useParams } from 'react-router';
import { ViewVerticalIcon } from '@radix-ui/react-icons';
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
import { getLayoutItem, setLayoutItem } from '@/utils/layoutStorage';

import styles from './CodeEditorUi.module.css';

const CODE_EDITOR_LAYOUT_STORAGE_KEY = 'canvas-code-editor-layout';
const DEFAULT_HORIZONTAL_SIZES = [60, 40];
const DEFAULT_VERTICAL_SIZES = [50, 50];
const MIN_PANE_SIZE = 100;

// Invalid stored data or parse errors fall back to defaults.
function loadLayout(): {
  horizontal: number[];
  vertical: number[];
} {
  const stored = getLayoutItem(CODE_EDITOR_LAYOUT_STORAGE_KEY);
  if (stored) {
    try {
      const parsed = JSON.parse(stored) as {
        horizontal?: number[];
        vertical?: number[];
      };
      if (
        Array.isArray(parsed.horizontal) &&
        parsed.horizontal.length === 2 &&
        Array.isArray(parsed.vertical) &&
        parsed.vertical.length === 2
      ) {
        return {
          horizontal: parsed.horizontal,
          vertical: parsed.vertical,
        };
      }
    } catch {
      // Ignore parse errors.
    }
  }
  return {
    horizontal: DEFAULT_HORIZONTAL_SIZES,
    vertical: DEFAULT_VERTICAL_SIZES,
  };
}

function saveLayout(horizontal: number[], vertical: number[]): void {
  setLayoutItem(
    CODE_EDITOR_LAYOUT_STORAGE_KEY,
    JSON.stringify({ horizontal, vertical }),
  );
}

const CodeEditorUi = () => {
  const [maximizedEditorLayout, setMaximizedEditorLayout] = useState(false);
  const [activeTab, setActiveTab] = useState('js');
  const [layout, setLayout] = useState(loadLayout);
  const dispatch = useAppDispatch();
  const selectedComponent = useAppSelector(selectCodeComponentSerialized);
  const componentStatus = useAppSelector(selectCodeComponentProperty('status'));
  const latestError = useAppSelector(selectLatestError);
  const { codeComponentId } = useParams();
  const { isLoading } = useCodeEditor();

  const handleHorizontalChange = useCallback((sizes: number[]) => {
    if (sizes.length === 2) {
      setLayout((prev) => {
        const next = { ...prev, horizontal: sizes };
        saveLayout(next.horizontal, next.vertical);
        return next;
      });
    }
  }, []);

  const handleVerticalChange = useCallback((sizes: number[]) => {
    if (sizes.length === 2) {
      setLayout((prev) => {
        const next = { ...prev, vertical: sizes };
        saveLayout(next.horizontal, next.vertical);
        return next;
      });
    }
  }, []);

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
          mr="4"
        >
          <ViewVerticalIcon />
        </Button>
      </div>
    );
  };

  const editorContent = (
    <Flex
      data-testid="canvas-code-editor-main-panel"
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
        width="calc(100% - var(--space-4))"
        height="calc(100% - 52px)"
        style={{ position: 'relative' }}
      >
        {activeTab === 'js' && <JavaScriptEditor isLoading={isLoading} />}
        {activeTab === 'css' && <CssEditor isLoading={isLoading} />}
        {activeTab === 'global-css' && (
          <GlobalCssEditor isLoading={isLoading} />
        )}
      </Flex>
    </Flex>
  );

  return (
    <Flex
      flexGrow="1"
      id="canvas-code-editor-container"
      data-testid="canvas-code-editor-container"
      style={{ overflow: 'hidden' }}
    >
      <Allotment
        defaultSizes={layout.horizontal}
        minSize={MIN_PANE_SIZE}
        onChange={handleHorizontalChange}
        className={styles.allotmentRoot}
      >
        <Allotment.Pane
          className={styles.codeEditorPanel}
          minSize={MIN_PANE_SIZE}
        >
          {editorContent}
        </Allotment.Pane>
        <Allotment.Pane
          visible={!maximizedEditorLayout}
          minSize={MIN_PANE_SIZE}
          preferredSize="40%"
        >
          <Allotment
            defaultSizes={layout.vertical}
            minSize={MIN_PANE_SIZE}
            vertical
            onChange={handleVerticalChange}
          >
            <Allotment.Pane minSize={MIN_PANE_SIZE}>
              <Flex
                data-testid="canvas-code-editor-preview-panel"
                px="4"
                pt="4"
                pb="4"
                flexGrow="1"
                direction="column"
                height="100%"
              >
                {componentStatus === false && (
                  <Box pb="4" mb="4" className={styles.addToComponentsButton}>
                    <Button
                      onClick={() => {
                        dispatch(openAddToComponentsDialog(selectedComponent));
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
            </Allotment.Pane>
            <Allotment.Pane minSize={MIN_PANE_SIZE}>
              <Flex
                data-testid="canvas-code-editor-component-data-panel"
                px="4"
                pt="4"
                flexGrow="1"
                direction="column"
                height="100%"
              >
                <ComponentData isLoading={isLoading} />
              </Flex>
            </Allotment.Pane>
          </Allotment>
        </Allotment.Pane>
      </Allotment>
    </Flex>
  );
};

export default CodeEditorUi;
