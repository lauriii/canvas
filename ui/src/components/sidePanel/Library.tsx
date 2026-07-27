import { useState } from 'react';
import { Flex, Tabs } from '@radix-ui/themes';

import ErrorBoundary from '@/components/error/ErrorBoundary';
import ComponentList from '@/components/list/ComponentList';
import PatternList from '@/components/list/PatternList';
import LibraryToolbar from '@/components/sidePanel/LibraryToolbar';
import { useCanvasHeadlessSettings } from '@/hooks/useCanvasHeadlessSettings';
import useDebounce from '@/hooks/useDebounce';
import { getCanvasSettings } from '@/utils/drupal-globals';

import type { ComponentVisibility } from '@/components/list/ComponentList';

import styles from './Library.module.css';

const Library = () => {
  const [searchTerm, setSearchTerm] = useState('');
  const debouncedSearchTerm = useDebounce(searchTerm, 300);
  const headlessSettings = useCanvasHeadlessSettings();
  const canAccessHeadlessPreview =
    getCanvasSettings().canAccessHeadlessPreview === true;
  const externalComponentsOnly =
    canAccessHeadlessPreview && (headlessSettings?.frontends.length ?? 0) > 0;
  const componentVisibility: ComponentVisibility = !canAccessHeadlessPreview
    ? 'non-external-only'
    : externalComponentsOnly
      ? 'external-only'
      : 'non-external-and-fallback-external';

  return (
    <>
      <Tabs.Root defaultValue="components">
        <Tabs.List justify="start" mt="-2" size="1">
          <Tabs.Trigger
            value="components"
            data-testid="canvas-library-components-tab-select"
            data-canvas-element="library-tab"
            data-canvas-element-key="components"
          >
            Components
          </Tabs.Trigger>
          {!externalComponentsOnly && (
            <Tabs.Trigger
              value="patterns"
              data-testid="canvas-library-patterns-tab-select"
              data-canvas-element="library-tab"
              data-canvas-element-key="patterns"
            >
              Patterns
            </Tabs.Trigger>
          )}
        </Tabs.List>
        <Flex py="2" className={styles.tabWrapper}>
          <Tabs.Content
            value={'components'}
            className={styles.tabContent}
            data-testid="canvas-library-components-tab-content"
          >
            <ErrorBoundary title="An unexpected error has occurred while fetching components.">
              <LibraryToolbar
                type={'component'}
                searchTerm={searchTerm}
                onSearch={setSearchTerm}
                showNewMenu={!externalComponentsOnly}
              />
              <ComponentList
                searchTerm={debouncedSearchTerm}
                visibility={componentVisibility}
              />
            </ErrorBoundary>
          </Tabs.Content>
          {!externalComponentsOnly && (
            <Tabs.Content
              value={'patterns'}
              className={styles.tabContent}
              data-testid="canvas-library-patterns-tab-content"
            >
              <ErrorBoundary title="An unexpected error has occurred while fetching patterns.">
                <LibraryToolbar
                  type={'pattern'}
                  searchTerm={searchTerm}
                  onSearch={setSearchTerm}
                  showNewMenu={true}
                />
                <PatternList searchTerm={debouncedSearchTerm} />
              </ErrorBoundary>
            </Tabs.Content>
          )}
        </Flex>
      </Tabs.Root>
    </>
  );
};

export default Library;
