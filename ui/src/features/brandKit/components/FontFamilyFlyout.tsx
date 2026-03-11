import { Cross2Icon, DownloadIcon, TrashIcon } from '@radix-ui/react-icons';
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
import { buildFontVariantLabel } from '@/features/brandKit/fontCss';

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
  selectedFontId: string | null;
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
  selectedFontId,
}: FontFamilyFlyoutProps) => (
  <Box className={styles.flyoutPanel}>
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
          {fontGroup.fonts.length}{' '}
          {fontGroup.fonts.length === 1
            ? 'variant uploaded'
            : 'variants uploaded'}
        </Text>
      </Box>
      <Flex align="center" gap="2" className={styles.flyoutHeaderActions}>
        <Button
          size="1"
          variant="soft"
          onClick={() => onAddVariantClick(fontGroup.family)}
          disabled={isBusy}
        >
          <DownloadIcon />
          Add variant
        </Button>
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

    <Box className={styles.flyoutScrollArea}>
      <Flex direction="column" gap="4" className={styles.flyoutBody}>
        <Flex direction="column" gap="2">
          <Text size="1" color="gray">
            Family name
          </Text>
          <TextField.Root
            value={familyDraft}
            onChange={(event) => onSetFamilyDraft(event.target.value)}
            onBlur={() => void onFamilyCommit(fontGroup.family)}
            disabled={isBusy}
          />
        </Flex>

        {selectedFont && (
          <>
            <FontVariantList
              fonts={fontGroup.fonts}
              onSelectFont={onSelectFont}
              selectedFontId={selectedFontId}
            />
            <FontPreviewCard font={selectedFont} />
            <Box className={styles.settingsSection}>
              <Flex direction="column" gap="3">
                <Text size="1" color="gray">
                  {selectedFont.variantType === 'variable'
                    ? 'CSS settings'
                    : 'Variant settings'}
                </Text>
                <Flex
                  align="start"
                  justify="between"
                  gap="2"
                  className={styles.variantHeader}
                >
                  <Box className={styles.variantMeta}>
                    <Text weight="medium">
                      {buildFontVariantLabel(selectedFont)}
                    </Text>
                  </Box>
                  <IconButton
                    className={styles.deleteButton}
                    variant="ghost"
                    color="gray"
                    size="1"
                    onClick={() => void onRemoveFont(selectedFont.id)}
                    disabled={isBusy}
                    aria-label={`Remove ${selectedFont.family} ${selectedFont.weight} ${selectedFont.style}`}
                  >
                    <TrashIcon />
                  </IconButton>
                </Flex>

                {selectedFont.variantType === 'variable' &&
                selectedFont.axes ? (
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

                <FontSnippetsCard
                  copiedSnippetId={copiedSnippetId}
                  font={selectedFont}
                  isBusy={isBusy}
                  onCopySnippet={onCopySnippet}
                />
              </Flex>
            </Box>
            <Box aria-hidden="true" className={styles.flyoutBottomSpacer} />
          </>
        )}
      </Flex>
    </Box>
  </Box>
);

export default FontFamilyFlyout;
