import { useEffect } from 'react';
import {
  Box,
  Flex,
  Heading,
  ScrollArea,
  Spinner,
  Tabs,
} from '@radix-ui/themes';

import { useAppDispatch } from '@/app/hooks';
import ErrorBoundary from '@/components/error/ErrorBoundary';
import { addDataFetch } from '@/features/code-editor/codeEditorSlice';
import DataFetch from '@/features/code-editor/component-data/DataFetch';
import Props from '@/features/code-editor/component-data/Props';
import Slots from '@/features/code-editor/component-data/Slots';

import styles from './ComponentData.module.css';

export default function ComponentData({
  isLoading = false,
}: {
  isLoading?: boolean;
}) {
  const dispatch = useAppDispatch();

  // Listen for messages from the code editor preview iframe.
  useEffect(() => {
    const handleMessage = (event: any) => {
      // Ensure the message is from the expected source
      if (event.origin !== window.location.origin) {
        return;
      }
      switch (event.data?.type) {
        case '_canvas_useswr_data_fetch':
          dispatch(
            addDataFetch({
              id: event.data.id,
              data: event.data.data,
              error: false,
            }),
          );
          break;
        case '_canvas_useswr_error':
          dispatch(
            addDataFetch({
              id: event.data.id,
              data: event.data.data,
              error: true,
            }),
          );
          break;
      }
    };
    window.addEventListener('message', handleMessage);
    // Cleanup function to remove the listener.
    return () => window.removeEventListener('message', handleMessage);
  }, [dispatch]);

  return (
    <Spinner loading={isLoading}>
      <Heading as="h5" size="2" weight="medium">
        Component data
      </Heading>
      <Tabs.Root defaultValue="props" className={styles.tabRoot} asChild>
        <Flex height="calc(100% - 34px)" direction="column" pt="4">
          <Box>
            <Tabs.List size="1">
              <Tabs.Trigger value="props">Props</Tabs.Trigger>
              <Tabs.Trigger value="slots">Slots</Tabs.Trigger>
              <Tabs.Trigger value="data-fetch">Data Fetch</Tabs.Trigger>
            </Tabs.List>
          </Box>
          <Flex direction="column" flexGrow="1" height="calc(100% - 32px)">
            <ScrollArea>
              <Box px="4">
                <Tabs.Content value="props">
                  <ErrorBoundary title="An unexpected error has occurred while displaying props.">
                    <Props />
                  </ErrorBoundary>
                </Tabs.Content>
                <Tabs.Content value="slots">
                  <ErrorBoundary title="An unexpected error has occurred while displaying slots.">
                    <Slots />
                  </ErrorBoundary>
                </Tabs.Content>
                <Tabs.Content value="data-fetch">
                  <ErrorBoundary title="An unexpected error has occurred while fetching.">
                    <DataFetch />
                  </ErrorBoundary>
                </Tabs.Content>
              </Box>
            </ScrollArea>
          </Flex>
        </Flex>
      </Tabs.Root>
    </Spinner>
  );
}
