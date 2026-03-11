import { Box, Flex, Popover, Text } from '@radix-ui/themes';

import FontFamilyFlyout from '@/features/brandKit/components/FontFamilyFlyout';

import type {
  AssetLibraryFont,
  AssetLibraryFontAxis,
} from '@/types/CodeComponent';

import styles from '../BrandKitPanel.module.css';

type FontGroup = { family: string; fonts: AssetLibraryFont[] };

type FontFamiliesListProps = {
  copiedSnippetId: string | null;
  familyDraft: string;
  groupedFonts: FontGroup[];
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
  onOpenFamilyChange: (family: string | null) => void;
  onRemoveFont: (fontId: string) => Promise<void>;
  onSelectFont: (font: AssetLibraryFont) => void;
  onSetFamilyDraft: (value: string) => void;
  onStyleChange: (fontId: string, style: string) => Promise<void>;
  onWeightChange: (fontId: string, value: string) => void;
  onWeightCommit: (fontId: string) => Promise<void>;
  openFamily: string | null;
  selectedFont: AssetLibraryFont | null;
  selectedFontId: string | null;
};

const FontFamiliesList = ({
  copiedSnippetId,
  familyDraft,
  groupedFonts,
  isBusy,
  onAddVariantClick,
  onAxisSettingChange,
  onAxisSettingCommit,
  onCopySnippet,
  onFamilyCommit,
  onOpenFamilyChange,
  onRemoveFont,
  onSelectFont,
  onSetFamilyDraft,
  onStyleChange,
  onWeightChange,
  onWeightCommit,
  openFamily,
  selectedFont,
  selectedFontId,
}: FontFamiliesListProps) => (
  <Box className={styles.familyList}>
    {groupedFonts.map((fontGroup) => (
      <Popover.Root
        key={fontGroup.family}
        modal={false}
        open={openFamily === fontGroup.family}
        onOpenChange={(isOpen) => {
          onOpenFamilyChange(isOpen ? fontGroup.family : null);
        }}
      >
        <Popover.Trigger>
          <button
            type="button"
            className={styles.familyRow}
            data-state={openFamily === fontGroup.family ? 'active' : 'inactive'}
            aria-expanded={openFamily === fontGroup.family}
            aria-label={`Open ${fontGroup.family} font details, ${fontGroup.fonts.length} ${
              fontGroup.fonts.length === 1 ? 'variant' : 'variants'
            } uploaded`}
          >
            <Box className={styles.familyMeta}>
              <Text size="1" weight="medium" className={styles.familyName}>
                {fontGroup.family}
              </Text>
            </Box>
            <Flex
              align="end"
              justify="center"
              flexShrink="0"
              px="1"
              className={styles.familyCount}
            >
              <Text size="1" weight="medium">
                {fontGroup.fonts.length}
              </Text>
            </Flex>
          </button>
        </Popover.Trigger>
        <Popover.Content
          side="right"
          sideOffset={20}
          align="start"
          className={styles.flyoutContent}
          onOpenAutoFocus={(event) => event.preventDefault()}
        >
          <FontFamilyFlyout
            copiedSnippetId={copiedSnippetId}
            familyDraft={familyDraft}
            fontGroup={fontGroup}
            isBusy={isBusy}
            onAddVariantClick={onAddVariantClick}
            onAxisSettingChange={onAxisSettingChange}
            onAxisSettingCommit={onAxisSettingCommit}
            onCopySnippet={onCopySnippet}
            onFamilyCommit={onFamilyCommit}
            onRemoveFont={onRemoveFont}
            onSelectFont={onSelectFont}
            onSetFamilyDraft={onSetFamilyDraft}
            onStyleChange={onStyleChange}
            onWeightChange={onWeightChange}
            onWeightCommit={onWeightCommit}
            selectedFont={openFamily === fontGroup.family ? selectedFont : null}
            selectedFontId={selectedFontId}
          />
        </Popover.Content>
      </Popover.Root>
    ))}
  </Box>
);

export default FontFamiliesList;
