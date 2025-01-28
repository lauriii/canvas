import type { Meta, StoryObj } from '@storybook/react';
import Navigation from './Navigation';
import type { ContentStub } from '@/types/Content';
import Panel from '@/components/Panel';

const meta: Meta<typeof Navigation> = {
  title: 'Components/Navigation',
  component: Navigation,
  decorators: [(story) => <Panel p="4">{story()}</Panel>],
};
export default meta;

type Story = StoryObj<typeof Navigation>;

const items: ContentStub[] = [
  {
    title: 'Alpha',
    path: '/alpha',
    id: 1,
    status: true,
  },
  {
    title: 'Bravo',
    path: '/bravo',
    id: 2,
    status: true,
  },
  {
    title: 'Charlie',
    path: '/charlie',
    id: 3,
    status: false,
  },
  {
    title: 'Delta',
    path: '/delta',
    id: 4,
    status: false,
  },
  {
    title: 'Echo',
    path: '/echo',
    id: 5,
    status: true,
  },
  {
    title: 'Foxtrot',
    path: '/foxtrot',
    id: 6,
    status: true,
  },
  {
    title: 'Golf',
    path: '/golf',
    id: 7,
    status: false,
  },
  {
    title: 'Hotel',
    path: '/hotel',
    id: 8,
    status: true,
  },
  {
    title: 'India',
    path: '/india',
    id: 9,
    status: false,
  },
  {
    title: 'Juliet',
    path: '/juliet',
    id: 10,
    status: true,
  },
];

export const Default: Story = {
  args: {
    loading: false,
    items,
    onNewPage: () => console.log('Creating new page'),
    onSearch: (query: string) => console.log('Searching for', query),
    onSelect: (value: ContentStub) => console.log('Selected', value),
    onRename: (page: ContentStub) => console.log('Renamed', page),
    onDuplicate: (page: ContentStub) => console.log('Duplicated', page),
    onSetHomepage: (page: ContentStub) => console.log('Set as homepage', page),
    onDelete: (page: ContentStub) => console.log('Deleted', page),
  },
};

export const Loading: Story = {
  args: {
    loading: true,
    items: [],
  },
};

export const NoItems: Story = {
  args: {
    loading: false,
    items: [],
  },
};
