import clsx from 'clsx';
import styles from '@/components/sidePanel/PrimaryPanel.module.css';
import { Box, Flex, Heading, ScrollArea } from '@radix-ui/themes';
import { useAppSelector } from '@/app/hooks';
import Library from '@/components/sidePanel/Library';
import { selectActivePanel } from '@/features/ui/primaryPanelSlice';
import useHidePanelClasses from '@/hooks/useHidePanelClasses';
import ErrorBoundary from '@/components/error/ErrorBoundary';
import Layers from '@/features/layout/layers/Layers';
import ExtensionsList from '@/components/extensions/ExtensionsList';

export const PrimaryPanel = () => {
  const activePanel = useAppSelector(selectActivePanel);
  const offLeftClasses = useHidePanelClasses('left');

  const panelMap: Record<string, string> = {
    library: 'Library',
    layers: 'Layers',
    extensions: 'Extensions',
  };

  return (
    <Flex
      className={clsx(styles.primaryPanel, ...offLeftClasses)}
      data-testid="xb-primary-panel"
      direction="column"
    >
      <Flex className={styles.header} px="4" align="center" flexShrink="0">
        <Heading as="h4" size="2" trim="both">
          {panelMap[activePanel]}
        </Heading>
      </Flex>
      <Box flexGrow="1" className={styles.scrollArea}>
        <ScrollArea scrollbars="both">
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
          </Box>
        </ScrollArea>
      </Box>
    </Flex>
  );
};

export default PrimaryPanel;
