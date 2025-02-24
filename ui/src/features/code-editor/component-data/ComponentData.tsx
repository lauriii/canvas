import { Box, Flex, ScrollArea, Spinner, Tabs } from '@radix-ui/themes';
import Props from '@/features/code-editor/component-data/Props';
import Slots from '@/features/code-editor/component-data/Slots';
import ErrorBoundary from '@/components/error/ErrorBoundary';
import styles from './ComponentData.module.css';

export default function ComponentData({
  isLoading = false,
}: {
  isLoading?: boolean;
}) {
  return (
    <Spinner loading={isLoading}>
      <Box height="100%" pt="4">
        <Tabs.Root defaultValue="props" className={styles.tabRoot}>
          <Tabs.List size="1" mx="4">
            <Tabs.Trigger value="props">Props</Tabs.Trigger>
            <Tabs.Trigger value="slots">Slots</Tabs.Trigger>
          </Tabs.List>
          <Flex direction="column" height="100%">
            <ScrollArea>
              <Box px="4">
                <Tabs.Content value="props">
                  <ErrorBoundary title="An unexpected error has occurred while rendering the component's props.">
                    <Props />
                  </ErrorBoundary>
                </Tabs.Content>
                <Tabs.Content value="slots">
                  <ErrorBoundary title="An unexpected error has occurred while rendering the component's slots.">
                    <Slots />
                  </ErrorBoundary>
                </Tabs.Content>
              </Box>
            </ScrollArea>
          </Flex>
        </Tabs.Root>
      </Box>
    </Spinner>
  );
}
