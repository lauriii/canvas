import { describe, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import IconPickerContent from '@/components/icons/IconPickerContent';

import type { IconPack } from '@/types/Icons';

const packs: IconPack[] = [
  {
    id: 'canvas_test',
    label: 'Canvas test icons',
    description: 'Test pack',
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

describe('IconPickerContent', () => {
  it('shows the single-library header with the icon count', () => {
    render(<IconPickerContent packs={[packs[0]]} onSelect={vi.fn()} />);
    expect(screen.getByText('Canvas test icons')).toBeInTheDocument();
    expect(screen.getByText('2 icons available')).toBeInTheDocument();
    // A single pack gets no per-pack section heading.
    expect(screen.getAllByRole('option')).toHaveLength(2);
  });

  it('summarizes multiple libraries and groups the grid by pack', () => {
    render(<IconPickerContent packs={packs} onSelect={vi.fn()} />);
    expect(screen.getByText('Icon libraries')).toBeInTheDocument();
    expect(screen.getByText('3 icons available')).toBeInTheDocument();
    expect(screen.getAllByRole('listbox')).toHaveLength(2);
  });

  it('filters across all packs when searching', async () => {
    render(<IconPickerContent packs={packs} onSelect={vi.fn()} />);
    await userEvent.type(
      screen.getByRole('textbox', { name: 'Search icons' }),
      'arrow',
    );
    const options = screen.getAllByRole('option');
    expect(options).toHaveLength(2);
    expect(screen.getByTitle('arrow-up')).toBeInTheDocument();
    expect(screen.getByTitle('arrow-down')).toBeInTheDocument();
    expect(screen.queryByTitle('star')).not.toBeInTheDocument();
  });

  it('shows an empty message when nothing matches', async () => {
    render(<IconPickerContent packs={packs} onSelect={vi.fn()} />);
    await userEvent.type(
      screen.getByRole('textbox', { name: 'Search icons' }),
      'nope',
    );
    expect(screen.getByText('No icons found.')).toBeInTheDocument();
  });

  it('reports the clicked icon and marks the selected one', async () => {
    const onSelect = vi.fn();
    render(
      <IconPickerContent
        packs={packs}
        selectedId="canvas_test:star"
        onSelect={onSelect}
      />,
    );
    expect(screen.getByTitle('star')).toHaveAttribute('aria-selected', 'true');
    await userEvent.click(screen.getByTitle('arrow-up'));
    expect(onSelect).toHaveBeenCalledWith(
      expect.objectContaining({ id: 'canvas_test:arrow-up' }),
    );
  });
});
