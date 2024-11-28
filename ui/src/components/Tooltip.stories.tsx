import type { Meta, StoryObj } from '@storybook/react';
import Tooltip from './Tooltip';
import { IconButton } from '@radix-ui/themes';
import { InfoCircledIcon } from '@radix-ui/react-icons';

const meta: Meta<typeof Tooltip> = {
  title: 'Components/Tooltip',
  component: Tooltip,
  argTypes: {
    content: { control: 'text', description: 'Tooltip content text' },
    children: {
      control: false,
      description: 'The element that triggers the tooltip',
    },
  },
};

export default meta;

type Story = StoryObj<typeof Tooltip>;

export const Default: Story = {
  args: {
    content: 'This is a tooltip',
    children: (
      <IconButton radius="full">
        <InfoCircledIcon />
      </IconButton>
    ),
  },
};

export const WithLongText: Story = {
  args: {
    content:
      'Lorem Ipsum is simply dummy text of the printing and typesetting industry.',
    children: (
      <IconButton radius="full">
        <InfoCircledIcon />
      </IconButton>
    ),
  },
};
