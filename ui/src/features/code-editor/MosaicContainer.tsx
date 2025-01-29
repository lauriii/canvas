import type { MosaicNode } from 'react-mosaic-component';
import { Mosaic, MosaicWindow } from 'react-mosaic-component';
import './xb-react-mosaic-component.css';
import { useState } from 'react';
import JavaScriptEditor from '@/features/code-editor/JavaScriptEditor';
import { ScrollArea, Tabs } from '@radix-ui/themes';
import GlobalCssEditor from '@/features/code-editor/GlobalCssEditor';
import CssEditor from '@/features/code-editor/CssEditor';
import WindowLayoutIcon from '@assets/icons/code-editor/window-layout.svg?react';
import styles from './MosaicContainer.module.css';
import './xb-code-mirror.css';

const defaultLayout: MosaicNode<string> = {
  direction: 'row',
  first: 'Editor',
  second: {
    direction: 'column',
    first: 'Preview',
    second: 'Component Data',
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
    second: 'Component Data',
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
        <Tabs.List className={styles.tabList}>
          <Tabs.Trigger value="js">JavaScript</Tabs.Trigger>
          <Tabs.Trigger value="css">CSS</Tabs.Trigger>
          <Tabs.Trigger value="global-css">Global CSS</Tabs.Trigger>
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
        <button
          onClick={toggleLayout}
          aria-label="Toggle button for code editor view"
        >
          <WindowLayoutIcon />
        </button>
      </div>
    );
  };

  return (
    <div id="xb-mosaic-container">
      <Mosaic
        value={layout}
        mosaicId={''}
        onChange={(newNode) => setLayout(newNode as MosaicNode<string>)}
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
                  <p>(Not yet supported)</p>
                </MosaicWindow>
              );
            case 'Component Data':
              return (
                <MosaicWindow<string>
                  className="xb-mosaic-window-component-data"
                  path={path}
                  title="Component data"
                  draggable={false}
                >
                  <p>(Not yet supported)</p>
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
