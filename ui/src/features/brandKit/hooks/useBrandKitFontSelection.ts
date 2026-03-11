import { useEffect, useMemo, useState } from 'react';

import type { AssetLibraryFont } from '@/types/CodeComponent';

type FontGroup = { family: string; fonts: AssetLibraryFont[] };

export const useBrandKitFontSelection = (groupedFonts: FontGroup[]) => {
  const [copiedSnippetId, setCopiedSnippetId] = useState<string | null>(null);
  const [openFamily, setOpenFamily] = useState<string | null>(null);
  const [selectedFontId, setSelectedFontId] = useState<string | null>(null);
  const [familyDraft, setFamilyDraft] = useState('');

  const openFontGroup = useMemo(
    () =>
      groupedFonts.find((fontGroup) => fontGroup.family === openFamily) ?? null,
    [groupedFonts, openFamily],
  );
  const selectedFont = useMemo(
    () =>
      openFontGroup?.fonts.find((font) => font.id === selectedFontId) ??
      openFontGroup?.fonts[0] ??
      null,
    [openFontGroup, selectedFontId],
  );

  useEffect(() => {
    if (!openFamily) {
      setFamilyDraft('');
      setSelectedFontId(null);
      return;
    }

    if (!groupedFonts.some((fontGroup) => fontGroup.family === openFamily)) {
      setOpenFamily(null);
      return;
    }

    setFamilyDraft(openFamily);
  }, [groupedFonts, openFamily]);

  useEffect(() => {
    if (!openFontGroup) {
      return;
    }

    if (
      !selectedFontId ||
      !openFontGroup.fonts.some((font) => font.id === selectedFontId)
    ) {
      setSelectedFontId(openFontGroup.fonts[0]?.id ?? null);
    }
  }, [openFontGroup, selectedFontId]);

  useEffect(() => {
    if (!copiedSnippetId) {
      return undefined;
    }

    const timeoutId = window.setTimeout(() => setCopiedSnippetId(null), 1500);
    return () => window.clearTimeout(timeoutId);
  }, [copiedSnippetId]);

  const copySnippet = async (text: string, snippetId: string) => {
    await navigator.clipboard.writeText(text);
    setCopiedSnippetId(snippetId);
  };

  const selectFont = (font: AssetLibraryFont) => {
    setOpenFamily(font.family);
    setSelectedFontId(font.id);
  };

  return {
    copiedSnippetId,
    copySnippet,
    familyDraft,
    openFamily,
    openFontGroup,
    selectedFont,
    selectedFontId,
    selectFont,
    setFamilyDraft,
    setOpenFamily,
    setSelectedFontId,
  };
};
