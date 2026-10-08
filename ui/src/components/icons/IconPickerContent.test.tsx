import { beforeEach, describe, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import IconPickerContent, {
  nextCellIndex,
} from '@/components/icons/IconPickerContent';

import type { GridCellPosition } from '@/components/icons/IconPickerContent';
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
  beforeEach(() => {
    window.localStorage.clear();
  });

  it('shows the single-library header with the icon count', () => {
    render(<IconPickerContent packs={[packs[0]]} onSelect={vi.fn()} />);
    expect(screen.getByText('Canvas test icons')).toBeInTheDocument();
    expect(screen.getByText('2 icons available')).toBeInTheDocument();
    // A single pack gets no per-pack section heading and no pack filter.
    expect(screen.getAllByTitle(/./)).toHaveLength(2);
    expect(
      screen.queryByRole('group', { name: 'Filter by icon pack' }),
    ).not.toBeInTheDocument();
  });

  it('summarizes multiple libraries and groups the grid by pack', () => {
    render(<IconPickerContent packs={packs} onSelect={vi.fn()} />);
    expect(screen.getByText('Icon libraries')).toBeInTheDocument();
    expect(screen.getByText('3 icons available')).toBeInTheDocument();
    expect(
      screen.getByRole('group', { name: 'Canvas test icons' }),
    ).toBeInTheDocument();
    expect(
      screen.getByRole('group', { name: 'Other pack' }),
    ).toBeInTheDocument();
  });

  it('focuses the search field on mount', () => {
    render(<IconPickerContent packs={packs} onSelect={vi.fn()} />);
    expect(screen.getByRole('textbox', { name: 'Search icons' })).toHaveFocus();
  });

  it('filters across all packs when searching, announcing the count', async () => {
    render(<IconPickerContent packs={packs} onSelect={vi.fn()} />);
    await userEvent.type(
      screen.getByRole('textbox', { name: 'Search icons' }),
      'arrow',
    );
    expect(screen.getByTitle('arrow-up')).toBeInTheDocument();
    expect(screen.getByTitle('arrow-down')).toBeInTheDocument();
    expect(screen.queryByTitle('star')).not.toBeInTheDocument();
    expect(screen.getByRole('status')).toHaveTextContent(
      '2 of 3 icons match “arrow”.',
    );
  });

  it('ranks results exact, then prefix, then substring, within a pack', async () => {
    const rankPack: IconPack = {
      id: 'rank',
      label: 'Rank pack',
      description: '',
      iconCount: 3,
      icons: [
        {
          id: 'rank:unstarred',
          name: 'unstarred',
          label: 'Unstarred',
          svg: '<svg/>',
        },
        {
          id: 'rank:star-half',
          name: 'star-half',
          label: 'Star Half',
          svg: '<svg/>',
        },
        { id: 'rank:star', name: 'star', label: 'Star', svg: '<svg/>' },
      ],
    };
    render(<IconPickerContent packs={[rankPack]} onSelect={vi.fn()} />);
    await userEvent.type(
      screen.getByRole('textbox', { name: 'Search icons' }),
      'star',
    );
    expect(screen.getAllByTitle(/star/).map((cell) => cell.title)).toEqual([
      'star',
      'star-half',
      'unstarred',
    ]);
  });

  it('never matches the pack id', async () => {
    render(<IconPickerContent packs={packs} onSelect={vi.fn()} />);
    await userEvent.type(
      screen.getByRole('textbox', { name: 'Search icons' }),
      'canvas_test',
    );
    expect(screen.getByRole('status')).toHaveTextContent(
      'No icons match “canvas_test”.',
    );
  });

  it('narrows the grid to one pack with the filter row', async () => {
    render(<IconPickerContent packs={packs} onSelect={vi.fn()} />);
    await userEvent.click(
      screen.getByRole('button', { name: 'Other pack', pressed: false }),
    );
    expect(screen.getByTitle('arrow-down')).toBeInTheDocument();
    expect(screen.queryByTitle('star')).not.toBeInTheDocument();
    expect(screen.getByRole('status')).toHaveTextContent(
      '1 icon in Other pack.',
    );
    // The tab toggles back to all packs.
    await userEvent.click(
      screen.getByRole('button', { name: 'Other pack', pressed: true }),
    );
    expect(screen.getByTitle('star')).toBeInTheDocument();
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
    expect(screen.getByTitle('star')).toHaveAttribute('aria-pressed', 'true');
    await userEvent.click(screen.getByTitle('arrow-up'));
    expect(onSelect).toHaveBeenCalledWith(
      expect.objectContaining({ id: 'canvas_test:arrow-up' }),
    );
  });

  it('moves focus through the grid with arrow keys, crossing packs', async () => {
    render(<IconPickerContent packs={packs} onSelect={vi.fn()} />);
    screen.getByTitle('arrow-up').focus();
    await userEvent.keyboard('{ArrowRight}');
    expect(screen.getByTitle('star')).toHaveFocus();
    await userEvent.keyboard('{ArrowLeft}');
    expect(screen.getByTitle('arrow-up')).toHaveFocus();
    // Down moves into the next pack's row, keeping the column.
    await userEvent.keyboard('{ArrowDown}');
    expect(screen.getByTitle('arrow-down')).toHaveFocus();
    await userEvent.keyboard('{ArrowUp}');
    expect(screen.getByTitle('arrow-up')).toHaveFocus();
  });

  it('keeps a single Tab stop in the grid', () => {
    render(
      <IconPickerContent
        packs={packs}
        selectedId="canvas_test:star"
        onSelect={vi.fn()}
      />,
    );
    // The selected icon holds the roving tabindex; every other cell is
    // skipped by Tab.
    expect(screen.getByTitle('star')).toHaveAttribute('tabindex', '0');
    expect(screen.getByTitle('arrow-up')).toHaveAttribute('tabindex', '-1');
    expect(screen.getByTitle('arrow-down')).toHaveAttribute('tabindex', '-1');
  });

  it('records a selection as recently used and offers it on reopen', async () => {
    const { unmount } = render(
      <IconPickerContent packs={packs} onSelect={vi.fn()} />,
    );
    await userEvent.click(screen.getByTitle('star'));
    unmount();

    render(<IconPickerContent packs={packs} onSelect={vi.fn()} />);
    const recents = screen.getByRole('group', { name: 'Recently used' });
    expect(recents).toContainElement(screen.getAllByTitle('star')[0]);
    // Recently used hides while searching so results stay unambiguous.
    await userEvent.type(
      screen.getByRole('textbox', { name: 'Search icons' }),
      'arrow',
    );
    expect(
      screen.queryByRole('group', { name: 'Recently used' }),
    ).not.toBeInTheDocument();
  });

  it('does not record recently used when tracking is off', async () => {
    render(
      <IconPickerContent
        packs={packs}
        onSelect={vi.fn()}
        trackRecent={false}
      />,
    );
    await userEvent.click(screen.getByTitle('star'));
    expect(window.localStorage.getItem('canvas.iconPicker.recents')).toBeNull();
  });

  it('shows an empty message when nothing matches', async () => {
    render(<IconPickerContent packs={packs} onSelect={vi.fn()} />);
    await userEvent.type(
      screen.getByRole('textbox', { name: 'Search icons' }),
      'nope',
    );
    expect(screen.getAllByText('No icons match “nope”.')).not.toHaveLength(0);
  });
});

describe('nextCellIndex', () => {
  // Two sections: a 4-column row of 4 plus a row of 2, then a row of 1.
  const positions: GridCellPosition[] = [
    { row: 0, col: 0 },
    { row: 0, col: 1 },
    { row: 0, col: 2 },
    { row: 0, col: 3 },
    { row: 1, col: 0 },
    { row: 1, col: 1 },
    { row: 2, col: 0 },
  ];

  it('moves left and right without wrapping past the ends', () => {
    expect(nextCellIndex(positions, 0, 'ArrowLeft')).toBeNull();
    expect(nextCellIndex(positions, 0, 'ArrowRight')).toBe(1);
    expect(nextCellIndex(positions, 6, 'ArrowRight')).toBeNull();
  });

  it('moves by row, clamping the column to shorter rows', () => {
    expect(nextCellIndex(positions, 3, 'ArrowDown')).toBe(5);
    expect(nextCellIndex(positions, 5, 'ArrowDown')).toBe(6);
    expect(nextCellIndex(positions, 6, 'ArrowUp')).toBe(4);
    expect(nextCellIndex(positions, 0, 'ArrowUp')).toBeNull();
  });

  it('jumps within the current row for Home and End', () => {
    expect(nextCellIndex(positions, 2, 'Home')).toBe(0);
    expect(nextCellIndex(positions, 1, 'End')).toBe(3);
  });
});
