import type { MosaicNode } from 'react-mosaic-component';
import { Mosaic, MosaicWindow } from 'react-mosaic-component';
import './xb-react-mosaic-component.css';
import { useState } from 'react';
import JavaScriptEditor from '@/features/code-editor/editors/JavaScriptEditor';
import { Button, ScrollArea, Tabs } from '@radix-ui/themes';
import { LayoutIcon } from '@radix-ui/react-icons';
import GlobalCssEditor from '@/features/code-editor/editors/GlobalCssEditor';
import CssEditor from '@/features/code-editor/editors/CssEditor';
import styles from './MosaicContainer.module.css';
import './xb-code-mirror.css';
import Preview from '@/features/code-editor/Preview';
import ComponentData from '@/features/code-editor/component-data/ComponentData';

const defaultLayout: MosaicNode<string> = {
  direction: 'row',
  first: 'Editor',
  second: {
    direction: 'column',
    first: 'Preview',
    second: 'Component data',
    splitPercentage: 50,
  },
  splitPercentage: 60,
};

const fullEditorLayout: MosaicNode<string> = {
  direction: 'row',
  first: 'Editor',
  second: {
    direction: 'column',
    first: 'Preview',
    second: 'Component data',
    splitPercentage: 50,
  },
  splitPercentage: 100,
};

const MosaicContainer = () => {
  const [layout, setLayout] = useState<MosaicNode<string>>(defaultLayout);
  const [activeTab, setActiveTab] = useState('js');

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
        <Tabs.List size="1" className={styles.tabList}>
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
      setLayout(layout === defaultLayout ? fullEditorLayout : defaultLayout);
    }

    return (
      <div className="mosaic-toggle-layout">
        <Button
          onClick={toggleLayout}
          aria-label="Toggle button for code editor view"
          variant="ghost"
          color="gray"
        >
          <LayoutIcon />
        </Button>
      </div>
    );
  };

  return (
    <div id="xb-mosaic-container">
      <Mosaic
        value={layout}
        mosaicId={''}
        onChange={(newNode) => {
          window.dispatchEvent(new Event('mosaicOnChange'));
          setLayout(newNode as MosaicNode<string>);
        }}
        onRelease={() => {
          window.dispatchEvent(new Event('mosaicOnRelease'));
        }}
        renderTile={(id: string, path: any[]) => {
          switch (id) {
            case 'Editor':
              return (
                <MosaicWindow<string>
                  className="xb-mosaic-window-editor"
                  path={path}
                  draggable={false}
                  toolbarControls={
                    <>
                      <TabGroup />
                      <ToggleLayoutButton />
                    </>
                  }
                  title="Editor"
                >
                  <ScrollArea className={styles.scrollArea}>
                    {activeTab === 'js' && <JavaScriptEditor />}
                    {activeTab === 'css' && <CssEditor />}
                    {activeTab === 'global-css' && <GlobalCssEditor />}
                  </ScrollArea>
                </MosaicWindow>
              );
            case 'Preview':
              return (
                <MosaicWindow<string>
                  className="xb-mosaic-window-preview"
                  path={path}
                  title="Preview"
                  draggable={false}
                >
                  <Preview />
                </MosaicWindow>
              );
            case 'Component data':
              return (
                <MosaicWindow<string>
                  className="xb-mosaic-window-component-data"
                  path={path}
                  title="Component data"
                  draggable={false}
                >
                  <ComponentData />
                </MosaicWindow>
              );
            default:
              return <div></div>;
          }
        }}
      />
    </div>
  );
};

export default MosaicContainer;
