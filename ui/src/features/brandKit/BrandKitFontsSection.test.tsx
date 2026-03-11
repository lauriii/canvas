import { beforeEach, describe, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';

import BrandKitFontsSection from '@/features/brandKit/BrandKitFontsSection';

const useBrandKitFontsMock = vi.fn();
const useBrandKitFontSelectionMock = vi.fn();
const useFontUploadMock = vi.fn();
const writeStoredAxisSelectionsMock = vi.fn();
const FONT_ASSET_BASE_URI = 'public://canvas/assets/';
const FONT_ASSET_BASE_URL = '/sites/default/files/canvas/assets/';

let fontFamiliesListProps: Record<string, any> | null = null;

vi.mock('@/features/brandKit/components/FontFamiliesList', () => ({
  default: (props: Record<string, any>) => {
    fontFamiliesListProps = props;
    return <div data-testid="font-families-list" />;
  },
}));

vi.mock('@/features/brandKit/hooks/useBrandKitFonts', () => ({
  useBrandKitFonts: () => useBrandKitFontsMock(),
}));

vi.mock('@/features/brandKit/hooks/useBrandKitFontSelection', () => ({
  useBrandKitFontSelection: () => useBrandKitFontSelectionMock(),
}));

vi.mock('@/features/brandKit/hooks/useFontUpload', () => ({
  useFontUpload: () => useFontUploadMock(),
}));

vi.mock('@/features/brandKit/variableFontState', () => ({
  getAxisSettingValue: (
    font: {
      axes?: Array<{ tag: string; default: number }> | null;
      axisSettings?: Array<{ tag: string; value: number }> | null;
    },
    axis: { tag: string; default: number },
  ) =>
    font.axisSettings?.find((setting) => setting.tag === axis.tag)?.value ??
    font.axes?.find((existingAxis) => existingAxis.tag === axis.tag)?.default ??
    null,
  readStoredAxisSelections: () => ({}),
  syncFontDefaultsFromAxisSettings: (
    font: Record<string, any>,
    axisSettings: Array<{ tag: string; value: number }>,
  ) => ({
    ...font,
    axisSettings,
  }),
  writeStoredAxisSelections: (...args: unknown[]) =>
    writeStoredAxisSelectionsMock(...args),
}));

describe('BrandKitFontsSection', () => {
  beforeEach(() => {
    fontFamiliesListProps = null;
    useBrandKitFontSelectionMock.mockReturnValue({
      copiedSnippetId: null,
      copySnippet: vi.fn(),
      familyDraft: '',
      openFamily: 'Mona Sans',
      selectedFont: null,
      selectedFontId: null,
      selectFont: vi.fn(),
      setFamilyDraft: vi.fn(),
      setOpenFamily: vi.fn(),
    });
    useFontUploadMock.mockReturnValue({
      acceptedFileTypes: '.woff2',
      errorMessage: null,
      fileInputRef: { current: null },
      handleAddVariantClick: vi.fn(),
      handleFilesSelected: vi.fn(),
      handleUploadClick: vi.fn(),
      isUploading: false,
    });
    writeStoredAxisSelectionsMock.mockReset();
  });

  it('disables uploads and warns before unload while brand kit auto-save is running', () => {
    useBrandKitFontsMock.mockReturnValue({
      errorMessage: null,
      fonts: [],
      isLoading: false,
      isSaving: true,
      saveFonts: vi.fn(),
      setFonts: vi.fn(),
    });

    render(<BrandKitFontsSection />);

    expect(
      screen.getByTestId('canvas-brand-kit-upload-font-button'),
    ).toBeDisabled();

    const event = new Event('beforeunload', {
      cancelable: true,
    }) as BeforeUnloadEvent;
    Object.defineProperty(event, 'returnValue', {
      configurable: true,
      writable: true,
      value: undefined,
    });

    window.dispatchEvent(event);

    expect(event.defaultPrevented).toBe(true);
    expect(event.returnValue).toBe('');
  });

  it('persists clamped variable font axis values on commit', async () => {
    const saveFonts = vi.fn().mockResolvedValue(undefined);
    useBrandKitFontsMock.mockReturnValue({
      errorMessage: null,
      fonts: [
        {
          id: 'font-1',
          family: 'Mona Sans',
          uri: `${FONT_ASSET_BASE_URI}mona-sans.woff2`,
          url: `${FONT_ASSET_BASE_URL}mona-sans.woff2`,
          format: 'woff2',
          variantType: 'variable',
          weight: '100 900',
          style: 'normal',
          axes: [
            {
              tag: 'wght',
              name: 'Weight',
              min: 100,
              max: 900,
              default: 400,
            },
          ],
          axisSettings: [
            {
              tag: 'wght',
              value: 950,
            },
          ],
        },
      ],
      isLoading: false,
      isSaving: false,
      saveFonts,
      setFonts: vi.fn(),
    });

    render(<BrandKitFontsSection />);

    expect(fontFamiliesListProps).not.toBeNull();

    await fontFamiliesListProps?.onAxisSettingCommit('font-1');

    expect(saveFonts).toHaveBeenCalledWith([
      expect.objectContaining({
        id: 'font-1',
        axisSettings: [
          {
            tag: 'wght',
            value: 900,
          },
        ],
      }),
    ]);
    expect(writeStoredAxisSelectionsMock).toHaveBeenCalledWith({
      'font-1': [
        {
          tag: 'wght',
          value: 900,
        },
      ],
    });
  });

  it('does not show the empty state when the fonts section has an error', () => {
    useBrandKitFontsMock.mockReturnValue({
      errorMessage: 'Failed to load fonts.',
      fonts: [],
      isLoading: false,
      isSaving: false,
      saveFonts: vi.fn(),
      setFonts: vi.fn(),
    });

    render(<BrandKitFontsSection />);

    expect(screen.getByText('Failed to load fonts.')).toBeInTheDocument();
    expect(
      screen.queryByText('No fonts uploaded yet.'),
    ).not.toBeInTheDocument();
  });
});
