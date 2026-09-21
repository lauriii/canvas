import { useState } from 'react';
import { describe, expect, it, vi } from 'vitest';
import { Popover, Theme } from '@radix-ui/themes';
import { fireEvent, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import FontFamilyFlyout from '@/features/brandKit/components/FontFamilyFlyout';
import { syncFontDefaultsFromAxisSettings } from '@/features/brandKit/variableFontState';

import type { BrandKitFont, BrandKitFontAxis } from '@/types/CodeComponent';

const FONT_ASSET_BASE_URI = 'public://canvas/assets/';
const FONT_ASSET_BASE_URL = '/sites/default/files/canvas/assets/';

const variableFont: BrandKitFont = {
  id: 'noto-sans-variable',
  family: 'Noto Sans',
  uri: `${FONT_ASSET_BASE_URI}noto-sans.woff2`,
  url: `${FONT_ASSET_BASE_URL}noto-sans.woff2`,
  format: 'woff2',
  variantType: 'variable',
  weight: '400',
  style: 'normal',
  axes: [
    { tag: 'wght', name: 'Weight', min: 100, max: 900, default: 400 },
    { tag: 'opsz', name: 'Optical size', min: 14, max: 32, default: 16 },
  ],
  axisSettings: [
    { tag: 'wght', value: 400 },
    { tag: 'opsz', value: 16 },
  ],
};

const staticFonts: BrandKitFont[] = [
  {
    id: 'inter-100',
    family: 'Inter',
    uri: `${FONT_ASSET_BASE_URI}inter-100.ttf`,
    url: `${FONT_ASSET_BASE_URL}inter-100.ttf`,
    format: 'ttf',
    variantType: 'static',
    weight: '100',
    style: 'normal',
    axes: null,
    axisSettings: null,
  },
  {
    id: 'inter-700-italic',
    family: 'Inter',
    uri: `${FONT_ASSET_BASE_URI}inter-700-italic.woff2`,
    url: `${FONT_ASSET_BASE_URL}inter-700-italic.woff2`,
    format: 'woff2',
    variantType: 'static',
    weight: '700',
    style: 'italic',
    axes: null,
    axisSettings: null,
  },
];

const noopProps = {
  copiedSnippetId: null,
  familyDraft: '',
  isBusy: false,
  onAddVariantClick: vi.fn(),
  onAxisSettingChange: vi.fn(),
  onAxisSettingCommit: vi.fn(),
  onCopySnippet: vi.fn().mockResolvedValue(undefined),
  onFamilyCommit: vi.fn().mockResolvedValue(undefined),
  onRemoveFont: vi.fn().mockResolvedValue(undefined),
  onSelectFont: vi.fn(),
  onSetFamilyDraft: vi.fn(),
  onStyleChange: vi.fn().mockResolvedValue(undefined),
  onWeightChange: vi.fn(),
  onWeightCommit: vi.fn(),
};

/**
 * The flyout renders inside a Radix popover, whose context its close button
 * needs.
 */
const FlyoutContext = ({ children }: { children: React.ReactNode }) => (
  <Theme>
    <Popover.Root open={true}>{children}</Popover.Root>
  </Theme>
);

/**
 * Drives the flyout the way `BrandKitFontsSection` does, so a slider move or a
 * variant click is observable in the preview and the emitted code.
 */
const FlyoutHarness = ({
  fonts,
  onCopySnippet = noopProps.onCopySnippet,
}: {
  fonts: BrandKitFont[];
  onCopySnippet?: (text: string, snippetId: string) => Promise<void>;
}) => {
  const [familyFonts, setFamilyFonts] = useState(fonts);
  const [selectedFontId, setSelectedFontId] = useState(fonts[0].id);

  const handleAxisSettingChange = (
    fontId: string,
    axis: BrandKitFontAxis,
    value: string,
  ) => {
    setFamilyFonts((currentFonts) =>
      currentFonts.map((font) =>
        font.id === fontId
          ? syncFontDefaultsFromAxisSettings(
              font,
              (font.axes ?? []).map((existingAxis) => ({
                tag: existingAxis.tag,
                value:
                  existingAxis.tag === axis.tag
                    ? Number.parseFloat(value)
                    : (font.axisSettings?.find(
                        (setting) => setting.tag === existingAxis.tag,
                      )?.value ?? existingAxis.default),
              })),
            )
          : font,
      ),
    );
  };

  return (
    <FlyoutContext>
      <FontFamilyFlyout
        {...noopProps}
        onCopySnippet={onCopySnippet}
        onAxisSettingChange={handleAxisSettingChange}
        onSelectFont={(font) => setSelectedFontId(font.id)}
        fontGroup={{ family: familyFonts[0].family, fonts: familyFonts }}
        selectedFont={
          familyFonts.find((font) => font.id === selectedFontId) ?? null
        }
      />
    </FlyoutContext>
  );
};

describe('FontFamilyFlyout', () => {
  it('redraws the preview and the example code as an axis moves', () => {
    render(<FlyoutHarness fonts={[variableFont]} />);

    const preview = screen.getByTestId(
      `canvas-brand-kit-font-preview-${variableFont.id}`,
    );
    expect(preview).toHaveStyle({ fontWeight: '400' });
    // Every axis is on its own default, so the class is the whole example.
    expect(
      screen.getByTestId(`canvas-brand-kit-font-snippet-${variableFont.id}`),
    ).toHaveTextContent('<p class="font-noto-sans">');

    // A pointer drag on a range input surfaces as a change event.
    fireEvent.change(screen.getByLabelText('Weight'), {
      target: { value: '450' },
    });

    expect(preview).toHaveStyle({ fontWeight: '450' });
    expect(preview.style.fontVariationSettings).toBe('"wght" 450, "opsz" 16');
    expect(
      screen.getByTestId(`canvas-brand-kit-font-snippet-${variableFont.id}`),
    ).toHaveTextContent('<p class="font-noto-sans font-[450]">');
  });

  it('tells the reader the example code follows the sliders', () => {
    render(<FlyoutHarness fonts={[variableFont]} />);

    expect(
      screen.getByTestId('canvas-brand-kit-font-code-context'),
    ).toHaveTextContent('Based on current slider settings');
    expect(
      screen.getByTestId(
        `canvas-brand-kit-font-face-snippet-${variableFont.id}`,
      ),
    ).toHaveTextContent('font-weight: 100 900;');
  });

  it('re-scopes the example code to the selected variant', () => {
    render(<FlyoutHarness fonts={staticFonts} />);

    expect(
      screen.getByTestId('canvas-brand-kit-font-code-context'),
    ).toHaveTextContent('Variant: 100 Normal');
    expect(
      screen.getByTestId('canvas-brand-kit-font-face-snippet-inter-100'),
    ).toHaveTextContent(
      "src: url('/sites/default/files/canvas/assets/inter-100.ttf') format('truetype');",
    );

    const italicCard = screen.getByTestId(
      'canvas-brand-kit-font-variant-inter-700-italic',
    );
    const italicRadio = within(italicCard).getByRole<HTMLInputElement>('radio');
    // The whole card is the radio's label, so clicking anywhere on it selects
    // the variant.
    expect(italicRadio.labels?.[0]).toBe(italicCard);
    fireEvent.click(italicRadio);
    expect(italicRadio).toBeChecked();

    expect(
      screen.getByTestId('canvas-brand-kit-font-code-context'),
    ).toHaveTextContent('Variant: 700 Italic');
    const fontFace = screen.getByTestId(
      'canvas-brand-kit-font-face-snippet-inter-700-italic',
    );
    expect(fontFace).toHaveTextContent('font-weight: 700;');
    expect(fontFace).toHaveTextContent('font-style: italic;');
    expect(
      screen.getByTestId('canvas-brand-kit-font-snippet-inter-700-italic'),
    ).toHaveTextContent('<p class="font-inter font-bold italic">');
  });

  it('lists every file of a variable family, not just the first', () => {
    const italicVariableFont = {
      ...variableFont,
      id: 'noto-sans-variable-italic',
      uri: `${FONT_ASSET_BASE_URI}noto-sans-italic.woff2`,
      url: `${FONT_ASSET_BASE_URL}noto-sans-italic.woff2`,
      style: 'italic' as const,
    };
    render(<FlyoutHarness fonts={[variableFont, italicVariableFont]} />);

    // Without a card the second file would be unreachable: a variable family
    // is not offered "Add variant", so there is no other way back to it.
    expect(
      screen.getByTestId(
        `canvas-brand-kit-font-variant-${italicVariableFont.id}`,
      ),
    ).toBeInTheDocument();
    expect(
      screen.queryByTestId('canvas-brand-kit-font-add-variant-button'),
    ).not.toBeInTheDocument();
    // The axes of the selected file are still editable.
    expect(screen.getByLabelText('Weight')).toBeInTheDocument();
  });

  it('names the variant group and each card for assistive technology', () => {
    render(<FlyoutHarness fonts={staticFonts} />);

    expect(
      screen.getByRole('radiogroup', { name: 'Inter variants' }),
    ).toBeInTheDocument();
    // Two files of a family can carry the same weight, style and format, so the
    // filename is what keeps the radios' names distinct.
    expect(
      screen.getByRole('radio', { name: '100 Normal [TTF] inter-100.ttf' }),
    ).toBeInTheDocument();
  });

  it('previews an italic axis without asking for a face that does not exist', () => {
    // The family's @font-face declares the upright face this file defaults to,
    // so the preview must not request `italic` on top of `'ital' 1` — the
    // browser would fake a second slant.
    const dualItalicFont = {
      ...variableFont,
      axes: [{ tag: 'ital', name: 'Italic', min: 0, max: 1, default: 0 }],
      axisSettings: [{ tag: 'ital', value: 1 }],
      style: 'italic' as const,
    };
    render(<FlyoutHarness fonts={[dualItalicFont]} />);

    const preview = screen.getByTestId(
      `canvas-brand-kit-font-preview-${dualItalicFont.id}`,
    );
    expect(preview).toHaveStyle({ fontStyle: 'normal' });
    expect(preview.style.fontVariationSettings).toBe('"ital" 1');
  });

  it('copies each code block verbatim', async () => {
    const user = userEvent.setup();
    const onCopySnippet = vi.fn().mockResolvedValue(undefined);
    render(<FlyoutHarness fonts={staticFonts} onCopySnippet={onCopySnippet} />);

    await user.click(
      screen.getByRole('button', { name: 'Copy CSS @font-face rule' }),
    );

    expect(onCopySnippet).toHaveBeenCalledWith(
      screen.getByTestId('canvas-brand-kit-font-face-snippet-inter-100')
        .textContent,
      'inter-100:font-face',
    );

    await user.click(
      screen.getByRole('button', { name: 'Copy Tailwind theme declaration' }),
    );

    expect(onCopySnippet).toHaveBeenLastCalledWith(
      '@theme {\n  --font-inter: "Inter", sans-serif;\n}',
      'inter-100:css',
    );
  });

  it('announces a copy rather than only restyling the button', () => {
    render(
      <FlyoutContext>
        <FontFamilyFlyout
          {...noopProps}
          copiedSnippetId="inter-100:css"
          fontGroup={{ family: 'Inter', fonts: staticFonts }}
          selectedFont={staticFonts[0]}
        />
      </FlyoutContext>,
    );

    expect(
      screen.getAllByRole('status').map((liveRegion) => liveRegion.textContent),
    ).toContain('Tailwind theme declaration copied');
  });
});
