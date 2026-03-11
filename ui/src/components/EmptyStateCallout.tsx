import { InfoCircledIcon } from '@radix-ui/react-icons';
import { Box, Callout, Flex } from '@radix-ui/themes';

import type { ComponentProps, CSSProperties, ReactNode } from 'react';

type EmptyStateCalloutProps = {
  title: ReactNode;
  description?: ReactNode;
} & Omit<ComponentProps<typeof Callout.Root>, 'children' | 'title'>;

const calloutStyle = {
  '--empty-state-callout-icon-width': '22px',
  rowGap: 0,
} as CSSProperties;

const EmptyStateCallout = ({
  title,
  description,
  ...props
}: EmptyStateCalloutProps) => {
  return (
    <Callout.Root
      color="gray"
      size="1"
      variant="soft"
      style={calloutStyle}
      {...props}
    >
      <Flex align="center">
        <Box width="var(--empty-state-callout-icon-width)">
          <Callout.Icon>
            <InfoCircledIcon />
          </Callout.Icon>
        </Box>
        <Callout.Text
          size={description ? '2' : '1'}
          weight={description ? 'medium' : 'regular'}
        >
          {title}
        </Callout.Text>
      </Flex>
      {description && (
        <Callout.Text
          size="1"
          color="gray"
          mt="2"
          ml="var(--empty-state-callout-icon-width)"
        >
          {description}
        </Callout.Text>
      )}
    </Callout.Root>
  );
};

export default EmptyStateCallout;
