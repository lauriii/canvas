import { describe, expect, it, vi } from 'vitest';
import { act, renderHook } from '@testing-library/react';

import { useBrandKitFontSelection } from '@/features/brandKit/hooks/useBrandKitFontSelection';

import type { AssetLibraryFont } from '@/types/CodeComponent';

const groupedFonts: { family: string; fonts: AssetLibraryFont[] }[] = [
  {
    family: 'Plus Jakarta Sans',
    fonts: [
      {
        id: 'font-1',
        family: 'Plus Jakarta Sans',
        uri: '/fonts/plus-jakarta-sans-regular.woff2',
        format: 'woff2',
        weight: '400',
        style: 'normal',
      },
      {
        id: 'font-2',
        family: 'Plus Jakarta Sans',
        uri: '/fonts/plus-jakarta-sans-italic.woff2',
        format: 'woff2',
        weight: '400',
        style: 'italic',
      },
    ],
  },
];

describe('useBrandKitFontSelection', () => {
  it('syncs the family draft and default selected font for the open family', () => {
    const { result } = renderHook(() => useBrandKitFontSelection(groupedFonts));

    act(() => {
      result.current.setOpenFamily('Plus Jakarta Sans');
    });

    expect(result.current.familyDraft).toBe('Plus Jakarta Sans');
    expect(result.current.selectedFontId).toBe('font-1');
    expect(result.current.selectedFont?.id).toBe('font-1');
  });

  it('copies snippets and clears the copied state after the timeout', async () => {
    vi.useFakeTimers();
    const writeText = vi.fn().mockResolvedValue(undefined);
    Object.assign(navigator, {
      clipboard: {
        writeText,
      },
    });

    const { result } = renderHook(() => useBrandKitFontSelection(groupedFonts));

    await act(async () => {
      await result.current.copySnippet('font-family: test;', 'font-1:css');
    });

    expect(writeText).toHaveBeenCalledWith('font-family: test;');
    expect(result.current.copiedSnippetId).toBe('font-1:css');

    act(() => {
      vi.advanceTimersByTime(1500);
    });

    expect(result.current.copiedSnippetId).toBeNull();
    vi.useRealTimers();
  });
});
