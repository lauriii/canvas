import { describe, expect, it, vi } from 'vitest';
import { Theme } from '@radix-ui/themes';
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import FontFamiliesList from '@/features/brandKit/components/FontFamiliesList';

import type { BrandKitFont } from '@/types/CodeComponent';

const FONT_ASSET_BASE_URI = 'public://canvas/assets/';
const FONT_ASSET_BASE_URL = '/sites/default/files/canvas/assets/';

vi.mock('@/features/brandKit/components/FontFamilyFlyout', () => ({
  default: () => <div>Font family flyout</div>,
}));

const groupedFonts: Array<{ family: string; fonts: BrandKitFont[] }> = [
  {
    family: 'Mona Sans',
    fonts: [
      {
        id: 'font-1',
        family: 'Mona Sans',
        uri: `${FONT_ASSET_BASE_URI}mona-sans.woff2`,
        url: `${FONT_ASSET_BASE_URL}mona-sans.woff2`,
        format: 'woff2',
        variantType: 'static',
        weight: '400',
        style: 'normal',
      },
      {
        id: 'font-3',
        family: 'Mona Sans',
        uri: `${FONT_ASSET_BASE_URI}mona-sans-bold.ttf`,
        url: `${FONT_ASSET_BASE_URL}mona-sans-bold.ttf`,
        format: 'ttf',
        variantType: 'static',
        weight: '700',
        style: 'normal',
      },
    ],
  },
  {
    family: 'Recursive',
    fonts: [
      {
        id: 'font-2',
        family: 'Recursive',
        uri: `${FONT_ASSET_BASE_URI}recursive.woff2`,
        url: `${FONT_ASSET_BASE_URL}recursive.woff2`,
        format: 'woff2',
        variantType: 'variable',
        weight: '300 1000',
        style: 'normal',
        axes: [
          { tag: 'wght', name: 'Weight', min: 300, max: 1000, default: 400 },
        ],
        axisSettings: [{ tag: 'wght', value: 400 }],
      },
    ],
  },
];

const buildProps = (overrides: Record<string, unknown> = {}) => ({
  copiedSnippetId: null,
  familyDraft: 'Mona Sans',
  groupedFonts,
  isBusy: false,
  onAddVariantClick: vi.fn(),
  onAxisSettingChange: vi.fn(),
  onAxisSettingCommit: vi.fn(),
  onCopySnippet: vi.fn(),
  onFamilyCommit: vi.fn(),
  onOpenFamilyChange: vi.fn(),
  onRemoveFamily: vi.fn().mockResolvedValue(undefined),
  onRemoveFont: vi.fn(),
  onSelectFont: vi.fn(),
  onSetFamilyDraft: vi.fn(),
  onStyleChange: vi.fn(),
  onWeightChange: vi.fn(),
  onWeightCommit: vi.fn(),
  openFamily: 'Mona Sans',
  selectedFont: groupedFonts[0].fonts[0],
  ...overrides,
});

describe('FontFamiliesList', () => {
  it('marks the open font family row as active', () => {
    render(
      <Theme>
        <FontFamiliesList {...buildProps()} />
      </Theme>,
    );

    // The explicit label replaces the button's descendant text for assistive
    // technology, so it must carry the file formats the row shows.
    const openFamilyButton = screen.getByRole('button', {
      name: 'Open Mona Sans font details, 2 variants, WOFF2 / TTF',
    });
    const closedFamilyButton = screen.getByRole('button', {
      name: 'Open Recursive font details, 1 variable font, WOFF2',
    });

    expect(openFamilyButton).toHaveAttribute('data-state', 'active');
    expect(openFamilyButton).toHaveAttribute('aria-expanded', 'true');
    expect(closedFamilyButton).toHaveAttribute('data-state', 'inactive');
    expect(closedFamilyButton).toHaveAttribute('aria-expanded', 'false');
  });

  it('shows each family its file formats and whether it is variable', () => {
    render(
      <Theme>
        <FontFamiliesList {...buildProps()} />
      </Theme>,
    );

    const monaSans = screen.getByTestId(
      'canvas-brand-kit-font-family-row-Mona Sans',
    );
    expect(within(monaSans).getByText('WOFF2 / TTF')).toBeInTheDocument();
    expect(within(monaSans).getByText('Static')).toBeInTheDocument();

    const recursive = screen.getByTestId(
      'canvas-brand-kit-font-family-row-Recursive',
    );
    expect(within(recursive).getByText('WOFF2')).toBeInTheDocument();
    expect(within(recursive).getByText('Variable')).toBeInTheDocument();
  });

  it('deletes a whole family from the row overflow menu', async () => {
    const user = userEvent.setup();
    const props = buildProps();
    render(
      <Theme>
        <FontFamiliesList {...props} />
      </Theme>,
    );

    await user.click(
      screen.getByRole('button', { name: 'Open Mona Sans contextual menu' }),
    );
    await user.click(await screen.findByText('Delete font'));

    expect(props.onRemoveFamily).toHaveBeenCalledWith('Mona Sans');
  });

  it('offers "Add variant" only for families that are not variable', async () => {
    const user = userEvent.setup();
    const props = buildProps();
    render(
      <Theme>
        <FontFamiliesList {...props} />
      </Theme>,
    );

    await user.click(
      screen.getByRole('button', { name: 'Open Recursive contextual menu' }),
    );
    await screen.findByText('Delete font');
    expect(screen.queryByText('Add variant')).not.toBeInTheDocument();
    await user.keyboard('{Escape}');

    await user.click(
      screen.getByRole('button', { name: 'Open Mona Sans contextual menu' }),
    );
    await user.click(await screen.findByText('Add variant'));

    expect(props.onAddVariantClick).toHaveBeenCalledWith('Mona Sans');
  });
});
