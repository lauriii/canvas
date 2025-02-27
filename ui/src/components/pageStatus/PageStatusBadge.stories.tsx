// PageStatusBadge.stories.tsx
import type { Meta, StoryFn } from '@storybook/react';
import type { PageStatusBadgeProps } from './PageStatus';
import { PageStatusBadge } from './PageStatus';

export default {
  title: 'Components/PageStatusBadge',
  component: PageStatusBadge,
  argTypes: {
    isNew: { control: 'boolean' },
    hasAutosave: { control: 'boolean' },
    isPublished: { control: 'boolean' },
  },
} as Meta;

const Template: StoryFn<typeof PageStatusBadge> = (
  args: PageStatusBadgeProps,
) => <PageStatusBadge {...args} />;

export const Draft = Template.bind({});
Draft.args = {
  isNew: true,
  hasAutosave: false,
  isPublished: false,
};

export const Changed = Template.bind({});
Changed.args = {
  isNew: false,
  hasAutosave: true,
  isPublished: false,
};

export const Published = Template.bind({});
Published.args = {
  isNew: false,
  hasAutosave: false,
  isPublished: true,
};

export const Archived = Template.bind({});
Archived.args = {
  isNew: false,
  hasAutosave: false,
  isPublished: false,
};
