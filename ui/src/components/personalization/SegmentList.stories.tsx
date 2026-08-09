import { MemoryRouter } from 'react-router-dom';
import { fn } from '@storybook/test';

import SegmentList from './SegmentList';

import type { Meta, StoryObj } from '@storybook/react';

const meta: Meta<typeof SegmentList> = {
  title: 'Personalization/SegmentList',
  component: SegmentList,
  tags: ['autodocs'],
  decorators: [
    (Story) => (
      <MemoryRouter>
        <Story />
      </MemoryRouter>
    ),
  ],
  args: {
    onCreateSegment: fn(),
    onReorderSegments: fn(),
    onToggleSegment: fn(),
    onEditSegment: fn(),
    onEditSegmentDetails: fn(),
    onDeleteSegment: fn(),
  },
};

export default meta;

type Story = StoryObj<typeof SegmentList>;

export const Empty: Story = {};

export const WithSegments: Story = {
  args: {
    segments: [
      {
        id: 'default',
        label: 'Default',
        status: true,
        weight: 2147483647,
      },
      {
        id: '1',
        label: 'High-value customers',
        status: true,
        weight: 0,
        rules: {
          utm_parameters: {
            id: 'utm_parameters',
            negate: false,
            all: true,
            parameters: [
              { key: 'utm_campaign', value: 'vip', matching: 'exact' },
            ],
          },
        },
      },
      {
        id: '2',
        label: 'Mobile users',
        status: false,
        weight: 2,
      },
      {
        id: '3',
        label: 'Returning visitors',
        status: true,
        weight: 1,
        rules: {
          query_parameter: {
            id: 'query_parameter',
            negate: false,
            parameter: 'returning',
            value: '1',
            matching: 'exact',
          },
          day_of_week: {
            id: 'day_of_week',
            negate: false,
            days: ['saturday', 'sunday'],
          },
        },
      },
      {
        id: '4',
        label: 'European users',
        status: false,
        weight: 4,
        rules: {
          geolocation: {
            id: 'geolocation',
            negate: false,
            countries: ['DE', 'FR'],
          },
        },
      },
      {
        id: '5',
        label: 'Newsletter subscribers',
        status: true,
        weight: 3,
      },
    ],
  },
};
