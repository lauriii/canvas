import type { Meta, StoryObj } from '@storybook/react';
import { fn } from '@storybook/test';
import DefaultSitePanel from './DefaultSitePanel';

const meta: Meta<typeof DefaultSitePanel> = {
  title: 'Personalization/DefaultSitePanel',
  component: DefaultSitePanel,
  tags: ['autodocs'],
  args: {
    onClickEdit: fn(),
    onClickPreview: fn(),
  },
};

export default meta;

type Story = StoryObj<typeof DefaultSitePanel>;

export const Default: Story = {};
