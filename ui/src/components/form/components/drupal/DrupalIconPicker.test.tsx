import { beforeEach, describe, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

// The raw component is tested; withRHF only adds form plumbing around it.
import { DrupalIconPicker } from '@/components/form/components/drupal/DrupalIconPicker';

import type { IconPack } from '@/types/Icons';

const triggerChange = vi.fn();

vi.mock('@/components/form/contexts/FieldContext', () => ({
  useFieldContext: () => ({ triggerChange }),
}));

// The real withRHF pulls in the whole form component map (a circular import
// in a test context); the raw component under test does not need it.
vi.mock('@/components/form/react-hook-form/withRHF', () => ({
  withRHF: (component: unknown) => component,
}));

const useGetIconPacksQueryMock = vi.fn();

vi.mock('@/services/icons', () => ({
  useGetIconPacksQuery: (...args: unknown[]) =>
    useGetIconPacksQueryMock(...args),
}));

const packs: IconPack[] = [
  {
    id: 'canvas_test',
    label: 'Canvas test icons',
    description: '',
    iconCount: 2,
    icons: [
      {
        id: 'canvas_test:arrow-up',
        name: 'arrow-up',
        label: 'Arrow Up',
        svg: '<svg xmlns="http://www.w3.org/2000/svg"><path d="M0 0"/></svg>',
      },
      {
        id: 'canvas_test:star',
        name: 'star',
        label: 'Star',
        svg: '<svg xmlns="http://www.w3.org/2000/svg"><path d="M1 1"/></svg>',
      },
    ],
  },
  {
    id: 'other_pack',
    label: 'Other pack',
    description: '',
    iconCount: 1,
    icons: [
      {
        id: 'other_pack:arrow-down',
        name: 'arrow-down',
        label: 'Arrow Down',
        url: '/files/arrow-down.svg',
      },
    ],
  },
];

describe('DrupalIconPicker', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    window.localStorage.clear();
    useGetIconPacksQueryMock.mockReturnValue({ data: packs });
  });

  it('shows the placeholder when no icon is set', () => {
    render(<DrupalIconPicker attributes={{}} />);
    expect(
      screen.getByRole('button', { name: 'Choose icon' }),
    ).toBeInTheDocument();
    expect(
      screen.queryByRole('button', { name: 'Clear icon' }),
    ).not.toBeInTheDocument();
  });

  it('shows the selected icon label', () => {
    render(<DrupalIconPicker attributes={{ value: 'canvas_test:star' }} />);
    expect(
      screen.getByRole('button', { name: 'Icon: Star' }),
    ).toBeInTheDocument();
  });

  it('resolves the stored value across all packs, not just the scoped ones', () => {
    // The value's pack is outside the prop's scope: the component still
    // renders it (matching server-side resolution), so the control shows it
    // rather than a broken state.
    render(
      <DrupalIconPicker
        attributes={{
          value: 'canvas_test:star',
          'data-canvas-icon-packs': 'other_pack',
        }}
      />,
    );
    expect(
      screen.getByRole('button', { name: 'Icon: Star' }),
    ).toBeInTheDocument();
  });

  it('marks a value no pack resolves as not available', () => {
    render(<DrupalIconPicker attributes={{ value: 'gone:icon' }} />);
    expect(screen.getByText('gone:icon')).toHaveAttribute(
      'title',
      'Icon not available: gone:icon',
    );
    // The set value still announces as an icon, by its raw id.
    expect(
      screen.getByRole('button', { name: 'Icon: gone:icon' }),
    ).toBeInTheDocument();
  });

  it('clears the value through the field context', async () => {
    render(<DrupalIconPicker attributes={{ value: 'canvas_test:star' }} />);
    await userEvent.click(screen.getByRole('button', { name: 'Clear icon' }));
    expect(triggerChange).toHaveBeenCalledWith('');
    expect(
      screen.getByRole('button', { name: 'Choose icon' }),
    ).toBeInTheDocument();
  });

  it('follows an externally changed value', () => {
    const { rerender } = render(
      <DrupalIconPicker attributes={{ value: 'canvas_test:star' }} />,
    );
    expect(
      screen.getByRole('button', { name: 'Icon: Star' }),
    ).toBeInTheDocument();
    rerender(
      <DrupalIconPicker attributes={{ value: 'canvas_test:arrow-up' }} />,
    );
    expect(
      screen.getByRole('button', { name: 'Icon: Arrow Up' }),
    ).toBeInTheDocument();
  });

  it('offers only the scoped packs in the picker', async () => {
    render(
      <DrupalIconPicker
        attributes={{ 'data-canvas-icon-packs': 'other_pack' }}
      />,
    );
    await userEvent.click(screen.getByRole('button', { name: 'Choose icon' }));
    expect(screen.getByTitle('arrow-down')).toBeInTheDocument();
    expect(screen.queryByTitle('star')).not.toBeInTheDocument();
  });

  it('selects an icon from the picker', async () => {
    render(<DrupalIconPicker attributes={{}} />);
    await userEvent.click(screen.getByRole('button', { name: 'Choose icon' }));
    await userEvent.click(screen.getByTitle('star'));
    expect(triggerChange).toHaveBeenCalledWith('canvas_test:star');
    expect(
      screen.getByRole('button', { name: 'Icon: Star' }),
    ).toBeInTheDocument();
  });
});
