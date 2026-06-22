import { fn } from '@storybook/test';

import ConflictBanner from './ConflictBanner';

import type { Meta, StoryObj } from '@storybook/react';

const meta: Meta<typeof ConflictBanner> = {
  title: 'Components/Publish Review/ConflictBanner',
  component: ConflictBanner,
  parameters: {
    layout: 'padded',
  },
  args: {
    conflictCount: 4,
    onResolveClick: fn(),
  },
};

export default meta;

type Story = StoryObj<typeof ConflictBanner>;

export const Default: Story = {};
