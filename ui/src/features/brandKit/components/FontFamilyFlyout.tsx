import { Cross2Icon, PlusIcon } from '@radix-ui/react-icons';
import {
  Box,
  Button,
  Flex,
  Heading,
  IconButton,
  Popover,
  Text,
  TextField,
} from '@radix-ui/themes';

import FontPreviewCard from '@/features/brandKit/components/FontPreviewCard';
import FontSnippetsCard from '@/features/brandKit/components/FontSnippetsCard';
import FontVariantList from '@/features/brandKit/components/FontVariantList';
import StaticFontVariantEditor from '@/features/brandKit/components/StaticFontVariantEditor';
import VariableFontAxesEditor from '@/features/brandKit/components/VariableFontAxesEditor';
import {
  buildFontFaceStyles,
  buildFontFamilySummary,
  isVariableFont,
  isVariableFontFamily,
} from '@/features/brandKit/fontCss';

import type {
  AssetLibraryFont,
  AssetLibraryFontAxis,
} from '@/types/CodeComponent';

import styles from '../BrandKitPanel.module.css';

type FontGroup = { family: string; fonts: AssetLibraryFont[] };

type FontFamilyFlyoutProps = {
  copiedSnippetId: string | null;
  familyDraft: string;
  fontGroup: FontGroup;
  isBusy: boolean;
  onAddVariantClick: (family: string) => void;
  onAxisSettingChange: (
    fontId: string,
    axis: AssetLibraryFontAxis,
    value: string,
  ) => void;
  onAxisSettingCommit: (fontId: string) => void;
  onCopySnippet: (text: string, snippetId: string) => Promise<void>;
  onFamilyCommit: (currentFamily: string) => Promise<void>;
  onRemoveFont: (fontId: string) => Promise<void>;
  onSelectFont: (font: AssetLibraryFont) => void;
  onSetFamilyDraft: (value: string) => void;
  onStyleChange: (fontId: string, style: string) => Promise<void>;
  onWeightChange: (fontId: string, value: string) => void;
  onWeightCommit: (fontId: string) => Promise<void>;
  selectedFont: AssetLibraryFont | null;
};

const FontFamilyFlyout = ({
  copiedSnippetId,
  familyDraft,
  fontGroup,
  isBusy,
  onAddVariantClick,
  onAxisSettingChange,
  onAxisSettingCommit,
  onCopySnippet,
  onFamilyCommit,
  onRemoveFont,
  onSelectFont,
  onSetFamilyDraft,
  onStyleChange,
  onWeightChange,
  onWeightCommit,
  selectedFont,
}: FontFamilyFlyoutProps) => {
  // A family is only offered "Add variant" when it is not variable, but the
  // list of files is shown whenever there is more than one — including the
  // several files a variable family can still hold, such as an upright and an
  // italic. Otherwise every file but the first would be unreachable.
  const isVariableFamily = isVariableFontFamily(fontGroup.fonts);
  const hasVariantList = fontGroup.fonts.length > 1;
  const isVariableSelection = selectedFont
    ? isVariableFont(selectedFont)
    : false;

  return (
    <Box className={styles.flyoutPanel}>
      {/*
       * Every file in the family gets its @font-face rule, so each variant card
       * can be typeset in its own weight and style.
       */}
      <style>{buildFontFaceStyles(fontGroup.fonts)}</style>
      <Flex
        align="center"
        justify="between"
        gap="3"
        className={styles.flyoutHeader}
      >
        <Box className={styles.flyoutHeaderMeta}>
          <Heading as="h5" size="3">
            {fontGroup.family}
          </Heading>
          <Text size="1" color="gray">
            {buildFontFamilySummary(fontGroup.fonts)}
          </Text>
        </Box>
        <Flex align="center" gap="2" className={styles.flyoutHeaderActions}>
          {!isVariableFamily && (
            <Button
              size="1"
              variant="soft"
              onClick={() => onAddVariantClick(fontGroup.family)}
              disabled={isBusy}
              data-testid="canvas-brand-kit-font-add-variant-button"
            >
              <PlusIcon />
              Add variant
            </Button>
          )}
          <Popover.Close aria-label="Close font details">
            <IconButton
              variant="ghost"
              color="gray"
              size="1"
              className={styles.flyoutCloseButton}
            >
              <Cross2Icon />
            </IconButton>
          </Popover.Close>
        </Flex>
      </Flex>

      <Flex className={styles.flyoutBody}>
        <Flex direction="column" className={styles.centerConsole}>
          <Flex direction="column" gap="2" flexShrink="0">
            <Text size="1" color="gray">
              Family name
            </Text>
            <TextField.Root
              value={familyDraft}
              aria-label="Font family name"
              onChange={(event) => onSetFamilyDraft(event.target.value)}
              onBlur={() => void onFamilyCommit(fontGroup.family)}
              disabled={isBusy}
            />
          </Flex>

          {selectedFont && (
            <>
              {/* With several files the cards are the preview, each typeset in
                  its own variant; with one there is nothing to choose between. */}
              {hasVariantList ? (
                <FontVariantList
                  family={fontGroup.family}
                  fonts={fontGroup.fonts}
                  isBusy={isBusy}
                  onRemoveFont={onRemoveFont}
                  onSelectFont={onSelectFont}
                  selectedFontId={selectedFont.id}
                />
              ) : (
                <FontPreviewCard font={selectedFont} />
              )}

              {isVariableSelection ? (
                <VariableFontAxesEditor
                  font={selectedFont}
                  isBusy={isBusy}
                  onAxisSettingChange={onAxisSettingChange}
                  onAxisSettingCommit={onAxisSettingCommit}
                />
              ) : (
                <StaticFontVariantEditor
                  font={selectedFont}
                  isBusy={isBusy}
                  onWeightChange={onWeightChange}
                  onWeightCommit={onWeightCommit}
                  onStyleChange={onStyleChange}
                />
              )}
            </>
          )}
        </Flex>

        <Flex direction="column" className={styles.rightConsole}>
          {selectedFont && (
            <FontSnippetsCard
              copiedSnippetId={copiedSnippetId}
              font={selectedFont}
              isBusy={isBusy}
              onCopySnippet={onCopySnippet}
            />
          )}
        </Flex>
      </Flex>
    </Box>
  );
};

export default FontFamilyFlyout;
