import type { Meta, StoryObj } from '@storybook/react';
import ViewportToolbar from './ViewportToolbar';

const meta: Meta<typeof ViewportToolbar> = {
  title: 'Components/ViewportToolbar',
  component: ViewportToolbar,
  args: {
    size: 'lg',
    name: 'Desktop',
    width: 1024,
    height: 768,
  },
  argTypes: {
    size: {
      control: { type: 'select' },
      options: ['lg', 'sm'],
    },
    name: {
      control: { type: 'text' },
    },
    width: {
      control: { type: 'number' },
    },
    height: {
      control: { type: 'number' },
    },
  },
};

export default meta;

type Story = StoryObj<typeof ViewportToolbar>;

export const Default: Story = {
  args: {
    size: 'lg',
    name: 'Desktop',
    width: 1920,
    height: 1080,
  },
};

export const Mobile: Story = {
  args: {
    size: 'sm',
    name: 'Mobile',
    width: 375,
    height: 667,
  },
};
