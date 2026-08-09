import DefaultSitePanel from './DefaultSitePanel';

import type { Meta, StoryObj } from '@storybook/react';

const meta: Meta<typeof DefaultSitePanel> = {
  title: 'Personalization/DefaultSitePanel',
  component: DefaultSitePanel,
  tags: ['autodocs'],
};

export default meta;

type Story = StoryObj<typeof DefaultSitePanel>;

export const Default: Story = {};
