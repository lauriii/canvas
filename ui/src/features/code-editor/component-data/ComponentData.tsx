import { Box, Flex, ScrollArea, Tabs } from '@radix-ui/themes';
import Props from '@/features/code-editor/component-data/Props';
import Slots from '@/features/code-editor/component-data/Slots';
import styles from './ComponentData.module.css';

export default function ComponentData() {
  return (
    <Box height="100%" pt="4">
      <Tabs.Root defaultValue="props" className={styles.tabRoot}>
        <Tabs.List size="1" mx="4">
          <Tabs.Trigger value="props">Props</Tabs.Trigger>
          <Tabs.Trigger value="slots">Slots</Tabs.Trigger>
        </Tabs.List>
        <Flex direction="column" height="100%">
          <ScrollArea>
            <Tabs.Content value="props">
              <Props />
            </Tabs.Content>
            <Tabs.Content value="slots">
              <Slots />
            </Tabs.Content>
          </ScrollArea>
        </Flex>
      </Tabs.Root>
    </Box>
  );
}
