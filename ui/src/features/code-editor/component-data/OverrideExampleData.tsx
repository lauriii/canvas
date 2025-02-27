import ReactJsonView from '@microlink/react-json-view';
import { Box, Callout, Flex } from '@radix-ui/themes';
import getOverrideExampleData from './getOverrideExampleData';
import { InfoCircledIcon } from '@radix-ui/react-icons';

export default function OverrideExampleData({
  block,
  type,
}: {
  block: string;
  type: 'props' | 'slots';
}) {
  const data = getOverrideExampleData(block, type);

  if (!data) {
    return (
      <Box my="4">
        <Callout.Root size="1" variant="surface" color="gray">
          <Callout.Icon>
            <InfoCircledIcon />
          </Callout.Icon>
          <Callout.Text>
            {type === 'props'
              ? 'This override component does not have any props.'
              : 'This override component does not have any slots.'}
          </Callout.Text>
        </Callout.Root>
      </Box>
    );
  }

  return (
    <Flex direction="column" mt="4">
      <Box flexGrow="1" mb="4">
        <ReactJsonView
          src={data}
          style={{
            fontSize: 'var(--font-size-1)',
            marginBottom: 'var(--space-4)',
          }}
          name={false}
          indentWidth={2}
          enableClipboard={false}
          displayObjectSize={false}
          quotesOnKeys={false}
        />
      </Box>
    </Flex>
  );
}
