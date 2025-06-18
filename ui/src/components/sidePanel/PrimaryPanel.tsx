/**
 * ⚠️ This is highly experimental and *will* be refactored.
 */
import clsx from 'clsx';
import styles from '@/components/sidePanel/PrimaryPanel.module.css';
import { Box, Button, Flex, Heading, ScrollArea } from '@radix-ui/themes';
import { useAppDispatch, useAppSelector } from '@/app/hooks';
import Library from '@/components/sidePanel/Library';
import {
  selectActivePanel,
  unsetActivePanel,
} from '@/features/ui/primaryPanelSlice';
import useHidePanelClasses from '@/hooks/useHidePanelClasses';
import ErrorBoundary from '@/components/error/ErrorBoundary';
import Layers from '@/features/layout/layers/Layers';
import ExtensionsList from '@/components/extensions/ExtensionsList';
import { Cross2Icon } from '@radix-ui/react-icons';
import AiWizard from '../aiExtension/AiWizard';

export const PrimaryPanel = () => {
  const activePanel = useAppSelector(selectActivePanel);
  const dispatch = useAppDispatch();
  const offLeftClasses = useHidePanelClasses('left');

  const panelMap: Record<string, string> = {
    library: 'Library',
    layers: 'Layers',
    extensions: 'Extensions',
    aiWizard: 'AI',
  };

  return (
    <Flex
      className={clsx(styles.primaryPanel, ...offLeftClasses)}
      data-testid="xb-primary-panel"
      direction="column"
    >
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
            {activePanel === 'layers' && (
              <ErrorBoundary>
                <Layers />
              </ErrorBoundary>
            )}
            {activePanel === 'library' && (
              <ErrorBoundary>
                <Library />
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
          </Box>
        </ScrollArea>
      </Box>
    </Flex>
  );
};

export default PrimaryPanel;
