import { Box, Flex, ScrollArea, Spinner, Tabs } from '@radix-ui/themes';
import { selectBlockOverride } from '@/features/code-editor/codeEditorSlice';
import { useAppSelector } from '@/app/hooks';
import Props from '@/features/code-editor/component-data/Props';
import Slots from '@/features/code-editor/component-data/Slots';
import OverrideExampleData from '@/features/code-editor/component-data/OverrideExampleData';
import ErrorBoundary from '@/components/error/ErrorBoundary';
import styles from './ComponentData.module.css';

export default function ComponentData({
  isLoading = false,
}: {
  isLoading?: boolean;
}) {
  const blockOverride = useAppSelector(selectBlockOverride);

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
                  <ErrorBoundary title="An unexpected error has occurred while displaying props.">
                    {blockOverride ? (
                      <OverrideExampleData
                        block={blockOverride as string}
                        type="props"
                      />
                    ) : (
                      <Props />
                    )}
                  </ErrorBoundary>
                </Tabs.Content>
                <Tabs.Content value="slots">
                  <ErrorBoundary title="An unexpected error has occurred while displaying slots.">
                    {blockOverride ? (
                      <OverrideExampleData
                        block={blockOverride as string}
                        type="slots"
                      />
                    ) : (
                      <Slots />
                    )}
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
