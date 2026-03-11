import { useEffect, useMemo } from 'react';
import { DownloadIcon } from '@radix-ui/react-icons';
import { Button, Callout, Flex, Heading, Spinner } from '@radix-ui/themes';

import EmptyStateCallout from '@/components/EmptyStateCallout';
import FontFamiliesList from '@/features/brandKit/components/FontFamiliesList';
import { groupFontsByFamily } from '@/features/brandKit/fontCss';
import { useBrandKitFonts } from '@/features/brandKit/hooks/useBrandKitFonts';
import { useBrandKitFontSelection } from '@/features/brandKit/hooks/useBrandKitFontSelection';
import { useFontUpload } from '@/features/brandKit/hooks/useFontUpload';
import {
  getAxisSettingValue,
  readStoredAxisSelections,
  syncFontDefaultsFromAxisSettings,
  writeStoredAxisSelections,
} from '@/features/brandKit/variableFontState';

import type {
  AssetLibraryFont,
  AssetLibraryFontAxis,
} from '@/types/CodeComponent';

const BrandKitFontsSection = () => {
  const {
    errorMessage: fontsErrorMessage,
    fonts,
    isLoading,
    isSaving,
    saveFonts,
    setFonts,
  } = useBrandKitFonts();
  const groupedFonts = useMemo(() => groupFontsByFamily(fonts), [fonts]);
  const {
    copiedSnippetId,
    copySnippet,
    familyDraft,
    openFamily,
    selectedFont,
    selectedFontId,
    selectFont,
    setFamilyDraft,
    setOpenFamily,
  } = useBrandKitFontSelection(groupedFonts);

  const {
    acceptedFileTypes,
    errorMessage: uploadErrorMessage,
    fileInputRef,
    handleAddVariantClick,
    handleFilesSelected,
    handleUploadClick,
    isUploading,
  } = useFontUpload({
    fonts,
    onFontUploaded: selectFont,
    saveFonts,
  });

  const isBusy = isUploading || isSaving;
  const errorMessage = uploadErrorMessage ?? fontsErrorMessage;

  useEffect(() => {
    if (!isBusy) {
      return;
    }

    const handleBeforeUnload = (event: BeforeUnloadEvent) => {
      event.preventDefault();
      event.returnValue = '';
    };

    window.addEventListener('beforeunload', handleBeforeUnload);
    return () => {
      window.removeEventListener('beforeunload', handleBeforeUnload);
    };
  }, [isBusy]);

  const handleWeightChange = (fontId: string, value: string) => {
    setFonts((currentFonts) =>
      currentFonts.map((font) =>
        font.id === fontId ? { ...font, weight: value } : font,
      ),
    );
  };

  const handleFamilyCommit = async (currentFamily: string) => {
    const nextFamily = familyDraft.trim() || 'New font';
    setFamilyDraft(nextFamily);
    if (nextFamily === currentFamily) {
      return;
    }

    const nextFonts = fonts.map((font) =>
      font.family === currentFamily ? { ...font, family: nextFamily } : font,
    );
    await saveFonts(nextFonts);
    setOpenFamily(nextFamily);
  };

  const handleWeightCommit = async (fontId: string) => {
    const nextFonts = fonts.map((font) =>
      font.id === fontId
        ? {
            ...font,
            weight: font.weight.trim() || '400',
          }
        : font,
    );

    await saveFonts(nextFonts);
  };

  const handleStyleChange = async (fontId: string, style: string) => {
    const nextFonts = fonts.map((font) =>
      font.id === fontId
        ? { ...font, style: style as AssetLibraryFont['style'] }
        : font,
    );

    await saveFonts(nextFonts);
  };

  const handleAxisSettingChange = (
    fontId: string,
    axis: AssetLibraryFontAxis,
    value: string,
  ) => {
    const nextValue = Number.parseFloat(value);
    if (Number.isNaN(nextValue)) {
      return;
    }

    setFonts((currentFonts) =>
      currentFonts.map((font) => {
        if (font.id !== fontId) {
          return font;
        }

        const nextAxisSettings = (font.axes ?? []).map((existingAxis) => ({
          tag: existingAxis.tag,
          value:
            existingAxis.tag === axis.tag
              ? nextValue
              : getAxisSettingValue(font, existingAxis),
        }));

        const nextStoredAxisSelections = readStoredAxisSelections();
        nextStoredAxisSelections[fontId] = nextAxisSettings;
        writeStoredAxisSelections(nextStoredAxisSelections);

        return syncFontDefaultsFromAxisSettings(font, nextAxisSettings);
      }),
    );
  };

  const handleAxisSettingCommit = async (fontId: string) => {
    const nextFonts = fonts.map((font) => {
      if (font.id !== fontId || !font.axes) {
        return font;
      }

      const nextAxisSettings = font.axes.map((axis) => {
        const currentValue = getAxisSettingValue(font, axis);
        const clampedValue = Math.min(
          axis.max,
          Math.max(axis.min, currentValue),
        );

        return {
          tag: axis.tag,
          value: clampedValue,
        };
      });

      const nextStoredAxisSelections = readStoredAxisSelections();
      nextStoredAxisSelections[fontId] = nextAxisSettings;
      writeStoredAxisSelections(nextStoredAxisSelections);

      return syncFontDefaultsFromAxisSettings(font, nextAxisSettings);
    });

    await saveFonts(nextFonts);
  };

  const handleRemoveFont = async (fontId: string) => {
    const nextFonts = fonts.filter((font) => font.id !== fontId);
    if (
      openFamily &&
      !nextFonts.some(
        (font) => (font.family.trim() || 'New font') === openFamily,
      )
    ) {
      setOpenFamily(null);
    }
    await saveFonts(nextFonts);
  };

  if (isLoading) {
    return (
      <Flex width="100%" justify="center" py="6">
        <Spinner size="3" loading={true} />
      </Flex>
    );
  }

  return (
    <Flex direction="column" gap="2">
      <Flex align="center" justify="between" gap="2">
        <Heading as="h5" size="2">
          Fonts
        </Heading>
        <Button
          size="1"
          variant="soft"
          onClick={handleUploadClick}
          disabled={isBusy}
          data-testid="canvas-brand-kit-upload-font-button"
        >
          <DownloadIcon />
          Upload font
        </Button>
        <input
          ref={fileInputRef}
          type="file"
          hidden
          multiple
          accept={acceptedFileTypes}
          onChange={(event) => void handleFilesSelected(event)}
        />
      </Flex>

      {errorMessage && (
        <Callout.Root color="red" size="1">
          <Callout.Text>{errorMessage}</Callout.Text>
        </Callout.Root>
      )}

      {groupedFonts.length === 0 && !errorMessage && (
        <EmptyStateCallout
          my="3"
          title="No fonts uploaded yet."
          description="Upload one or more font files to generate reusable CSS snippets for the global asset library."
        />
      )}

      {groupedFonts.length > 0 && (
        <FontFamiliesList
          copiedSnippetId={copiedSnippetId}
          familyDraft={familyDraft}
          groupedFonts={groupedFonts}
          isBusy={isBusy}
          onAddVariantClick={handleAddVariantClick}
          onAxisSettingChange={handleAxisSettingChange}
          onAxisSettingCommit={handleAxisSettingCommit}
          onCopySnippet={copySnippet}
          onFamilyCommit={handleFamilyCommit}
          onOpenFamilyChange={setOpenFamily}
          onRemoveFont={handleRemoveFont}
          onSelectFont={selectFont}
          onSetFamilyDraft={setFamilyDraft}
          onStyleChange={handleStyleChange}
          onWeightChange={handleWeightChange}
          onWeightCommit={handleWeightCommit}
          openFamily={openFamily}
          selectedFont={selectedFont}
          selectedFontId={selectedFontId}
        />
      )}
    </Flex>
  );
};

export default BrandKitFontsSection;
