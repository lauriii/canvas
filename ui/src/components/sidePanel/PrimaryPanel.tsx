import { useEffect } from 'react';
import clsx from 'clsx';
import { Cross2Icon } from '@radix-ui/react-icons';
import { Box, Button, Flex, Heading, ScrollArea } from '@radix-ui/themes';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import ErrorBoundary from '@/components/error/ErrorBoundary';
import ExtensionsList from '@/components/extensions/ExtensionsList';
import Code from '@/components/sidePanel/Code';
import Library from '@/components/sidePanel/Library';
import Pages from '@/components/sidePanel/Pages';
import Templates from '@/components/sidePanel/Templates';
import Layers from '@/features/layout/layers/Layers';
import {
  selectActivePanel,
  unsetActivePanel,
} from '@/features/ui/primaryPanelSlice';
import useHidePanelClasses from '@/hooks/useHidePanelClasses';
import { hasPermission } from '@/utils/permissions';

import AiWizard from '../aiExtension/AiWizard';

import styles from '@/components/sidePanel/PrimaryPanel.module.css';

export const PrimaryPanel = () => {
  const activePanel = useAppSelector(selectActivePanel);
  const dispatch = useAppDispatch();
  const offLeftClasses = useHidePanelClasses('left');

  useEffect(() => {
    if (activePanel === 'templates' && !hasPermission('contentTemplates')) {
      dispatch(unsetActivePanel());
    }
  }, [activePanel, dispatch]);

  const panelMap: Record<string, string> = {
    library: 'Library',
    layers: 'Layers',
    code: 'Code',
    extensions: 'Extensions',
    aiWizard: 'AI',
    templates: 'Templates',
    pages: 'Pages',
  };

  return (
    <Flex
      className={clsx(styles.primaryPanel, ...offLeftClasses)}
      data-testid="canvas-primary-panel"
      direction="column"
    >
      {!!activePanel && (
        <>
          <Flex align="center" className={styles.header} px="4" flexShrink="0">
            <Heading as="h4" size="2" trim="both">
              {panelMap[activePanel]}
            </Heading>
            <Box ml="auto">
              <Button
                ml="auto"
                mr="0"
                variant="ghost"
                size="1"
                highContrast
                onClick={() => dispatch(unsetActivePanel())}
              >
                <Cross2Icon />
              </Button>
            </Box>
          </Flex>
          <Box flexGrow="1" className={styles.scrollArea}>
            <ScrollArea scrollbars="vertical">
              <Box p="4" className="primaryPanelContent">
                {activePanel === 'library' && (
                  <ErrorBoundary>
                    <Library />
                  </ErrorBoundary>
                )}
                {activePanel === 'layers' && (
                  <ErrorBoundary>
                    <Layers />
                  </ErrorBoundary>
                )}
                {activePanel === 'code' && (
                  <ErrorBoundary>
                    <Code />
                  </ErrorBoundary>
                )}
                {activePanel === 'pages' && (
                  <ErrorBoundary>
                    <Pages />
                  </ErrorBoundary>
                )}
                {activePanel === 'extensions' && (
                  <ErrorBoundary>
                    <ExtensionsList />
                  </ErrorBoundary>
                )}
                {activePanel === 'aiWizard' && (
                  <ErrorBoundary>
                    <AiWizard />
                  </ErrorBoundary>
                )}
                {activePanel === 'templates' &&
                  hasPermission('contentTemplates') && (
                    <ErrorBoundary>
                      <Templates />
                    </ErrorBoundary>
                  )}
              </Box>
            </ScrollArea>
          </Box>
        </>
      )}
    </Flex>
  );
};

export default PrimaryPanel;
