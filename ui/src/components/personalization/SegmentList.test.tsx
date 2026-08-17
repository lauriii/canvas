import { MemoryRouter } from 'react-router-dom';
import { describe, expect, it, vi } from 'vitest';
import { Theme } from '@radix-ui/themes';
import { render, screen } from '@testing-library/react';

import SegmentList from './SegmentList';

import type { Segment } from '@/types/Personalization';

const handlers = {
  onCreateSegment: vi.fn(),
  onReorderSegments: vi.fn(),
  onToggleSegment: vi.fn(),
  onEditSegment: vi.fn(),
  onEditSegmentDetails: vi.fn(),
  onDeleteSegment: vi.fn(),
};

const segments: Segment[] = [
  {
    id: 'default',
    label: 'Default',
    status: true,
    weight: 2147483647,
  },
  {
    id: 'returning',
    label: 'Returning visitors',
    status: true,
    weight: 0,
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
    id: 'mobile',
    label: 'Mobile users',
    status: false,
    weight: 1,
  },
];

const renderList = () =>
  render(
    <Theme>
      <MemoryRouter>
        <SegmentList segments={segments} {...handlers} />
      </MemoryRouter>
    </Theme>,
  );

describe('SegmentList', () => {
  it('summarizes the rules of a segment under its label', () => {
    renderList();

    expect(
      screen.getByText(
        'The "returning" query parameter equals "1"; and The visit happens on Saturday, Sunday',
      ),
    ).toBeInTheDocument();
  });

  it('warns when a segment has no rules', () => {
    renderList();

    const warning = screen.getByText('No rules yet — matches no one');
    expect(warning).toBeInTheDocument();
    expect(warning).toHaveAttribute('data-accent-color', 'amber');
  });

  it('describes the default segment as matching everyone', () => {
    renderList();

    expect(screen.getByText('Matches all visitors')).toBeInTheDocument();
    expect(
      screen.queryAllByText('No rules yet — matches no one'),
      // Only the ruleless non-default segment warns; the default one never
      // does.
    ).toHaveLength(1);
  });

  it('dims the label of disabled segments', () => {
    renderList();

    expect(screen.getByRole('link', { name: 'Mobile users' })).toHaveAttribute(
      'data-accent-color',
      'gray',
    );
    expect(
      screen.getByRole('link', { name: 'Returning visitors' }),
    ).not.toHaveAttribute('data-accent-color', 'gray');
  });

  it('notes that the list order does not decide which variant wins', () => {
    renderList();

    expect(screen.getByText(/The order is display only/)).toBeInTheDocument();
  });
});
