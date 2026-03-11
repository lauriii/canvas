import { describe, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';

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
        variantType: 'static',
        weight: '500',
        style: 'normal',
      },
    ],
  },
];

const defaultProps = {
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
  onRemoveFont: vi.fn(),
  onSelectFont: vi.fn(),
  onSetFamilyDraft: vi.fn(),
  onStyleChange: vi.fn(),
  onWeightChange: vi.fn(),
  onWeightCommit: vi.fn(),
  openFamily: 'Mona Sans',
  selectedFont: groupedFonts[0].fonts[0],
  selectedFontId: 'font-1',
};

describe('FontFamiliesList', () => {
  it('marks the open font family row as active', () => {
    render(<FontFamiliesList {...defaultProps} />);

    const openFamilyButton = screen.getByRole('button', {
      name: 'Open Mona Sans font details, 1 variant uploaded',
    });
    const closedFamilyButton = screen.getByRole('button', {
      name: 'Open Recursive font details, 1 variant uploaded',
    });

    expect(openFamilyButton).toHaveAttribute('data-state', 'active');
    expect(openFamilyButton).toHaveAttribute('aria-expanded', 'true');
    expect(closedFamilyButton).toHaveAttribute('data-state', 'inactive');
    expect(closedFamilyButton).toHaveAttribute('aria-expanded', 'false');
  });
});
