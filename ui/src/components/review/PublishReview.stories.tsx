import type { Meta, StoryObj } from '@storybook/react';
import { fn } from '@storybook/test';
import type { UnpublishedChanges } from './PublishReview';
import PublishReview, {
  IconType,
  DEFAULT_TITLE,
  DEFAULT_BUTTON_TEXT,
} from './PublishReview';

// cSpell:disable
const allChanges: UnpublishedChanges = [
  {
    label: 'Noah Lee page',
    icon: IconType.FILE,
    updated: 1733448600,
    owner: {
      name: 'Balint',
    },
  },
  {
    label: 'Navigation',
    icon: IconType.COMPONENT1,
    updated: 1725586200,
    owner: {
      name: 'Jillian Chueka',
      avatar:
        'https://images.unsplash.com/photo-1526510747491-58f928ec870f?&w=64&h=64&dpr=2&q=70&crop=focalpoint&fp-x=0.48&fp-y=0.48&fp-z=1.3&fit=crop',
    },
  },
  {
    label: 'Homepage',
    icon: IconType.FILE,
    updated: 1732066200,
    owner: {
      name: 'Renee Lund',
    },
  },
  {
    label: 'Hero banner',
    icon: IconType.CUBE,
    updated: 1733444832,
    owner: {
      name: 'Madelyn Levis',
      avatar:
        'https://images.unsplash.com/photo-1522075469751-3a6694fb2f61?&w=64&h=64&dpr=2&q=70&crop=faces&fit=crop',
    },
  },
];

const meta: Meta<typeof PublishReview> = {
  title: 'Components/Publish Review',
  component: PublishReview,
  parameters: {
    layout: 'centered',
  },
  argTypes: {
    title: { control: 'text', description: 'Heading title text' },
    changes: { control: false, description: 'Array of changes to be rendered' },
    buttonText: { control: 'text', description: 'Submit button text' },
    onPublishClick: { action: 'onPublishClick' },
  },
};

export default meta;

type Story = StoryObj<typeof PublishReview>;

export const Default: Story = {
  args: {
    title: DEFAULT_TITLE,
    changes: allChanges,
    buttonText: DEFAULT_BUTTON_TEXT,
    onPublishClick: fn(),
  },
};

export const WithOneChange: Story = {
  args: {
    title: DEFAULT_TITLE,
    changes: [allChanges[0]],
    buttonText: DEFAULT_BUTTON_TEXT,
    onPublishClick: fn(),
  },
};

export const WithNoChanges: Story = {
  args: {
    title: DEFAULT_TITLE,
    buttonText: DEFAULT_BUTTON_TEXT,
    onPublishClick: fn(),
  },
};
