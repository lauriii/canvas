import { describe, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import BrandKitIconsSection from '@/features/brandKit/BrandKitIconsSection';

import type { IconPack } from '@/types/Icons';

const useGetIconPacksQueryMock = vi.fn();

vi.mock('@/services/icons', () => ({
  useGetIconPacksQuery: () => useGetIconPacksQueryMock(),
}));

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

describe('BrandKitIconsSection', () => {
  it('lists installed packs as rows with a search bar', () => {
    useGetIconPacksQueryMock.mockReturnValue({
      data: packs,
      isLoading: false,
    });
    render(<BrandKitIconsSection />);
    expect(screen.getByText('Icon libraries')).toBeInTheDocument();
    expect(
      screen.getByRole('button', { name: 'Browse Canvas test icons' }),
    ).toBeInTheDocument();
    expect(screen.getByText('2 icons')).toBeInTheDocument();
    expect(
      screen.getByRole('textbox', { name: 'Search icons' }),
    ).toBeInTheDocument();
    // Read-only: only the library browse rows, no mutation controls.
    expect(screen.getAllByRole('button')).toHaveLength(packs.length);
  });

  it('opens the icon browser popover for a library row', async () => {
    useGetIconPacksQueryMock.mockReturnValue({
      data: packs,
      isLoading: false,
    });
    render(<BrandKitIconsSection />);
    await userEvent.click(
      screen.getByRole('button', { name: 'Browse Canvas test icons' }),
    );
    // The popover shows the single-library browser: header count and icons.
    expect(screen.getByText('2 icons available')).toBeInTheDocument();
    expect(screen.getByTitle('star')).toBeInTheDocument();
  });

  it('shows matching icons across all packs inline when searching', async () => {
    useGetIconPacksQueryMock.mockReturnValue({
      data: packs,
      isLoading: false,
    });
    render(<BrandKitIconsSection />);
    await userEvent.type(
      screen.getByRole('textbox', { name: 'Search icons' }),
      'arrow',
    );
    expect(screen.getByTitle('arrow-up')).toBeInTheDocument();
    expect(screen.getByTitle('arrow-down')).toBeInTheDocument();
    expect(screen.queryByTitle('star')).not.toBeInTheDocument();
    // Library rows are replaced by inline results while searching.
    expect(
      screen.queryByRole('button', { name: 'Browse Canvas test icons' }),
    ).not.toBeInTheDocument();
  });

  it('shows an empty state when no packs are installed', () => {
    useGetIconPacksQueryMock.mockReturnValue({ data: [], isLoading: false });
    render(<BrandKitIconsSection />);
    expect(
      screen.getByText('No icon libraries installed.'),
    ).toBeInTheDocument();
  });
});
