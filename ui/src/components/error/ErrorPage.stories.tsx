import type { Meta, StoryObj } from '@storybook/react';
import ErrorPage from './ErrorPage';
import { Text } from '@radix-ui/themes';
import ErrorCard from '@/components/error/ErrorCard';
import { fn } from '@storybook/test';

const meta: Meta<typeof ErrorPage> = {
  title: 'Components/Errors/ErrorPage',
  component: ErrorPage,
  parameters: {
    layout: 'fullscreen',
  },
};

export default meta;

export const Default: StoryObj<typeof ErrorPage> = {
  args: {
    children: (
      <Text size="4" style={{ color: 'var(--text-primary)' }}>
        An error occurred!
      </Text>
    ),
  },
};

export const WithCustomMessage: StoryObj<typeof ErrorCard> = {
  args: {
    resetErrorBoundary: fn(),
    title: 'Something went wrong. Please try again later.',
    error: 'An unknown error occurred.',
    resetButtonText: 'Reset Text',
  },
  render: (args) => (
    <ErrorPage>
      <ErrorCard {...args} />
    </ErrorPage>
  ),
};
