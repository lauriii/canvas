import { TrashIcon } from '@radix-ui/react-icons';
import { Box, Flex, IconButton, Text } from '@radix-ui/themes';

import { buildFontVariantLabel } from '@/features/brandKit/fontCss';
import { getFontPreviewStyle } from '@/features/brandKit/variableFontState';

import type { AssetLibraryFont } from '@/types/CodeComponent';

import styles from '../BrandKitPanel.module.css';

type FontVariantListProps = {
  family: string;
  fonts: AssetLibraryFont[];
  isBusy: boolean;
  onRemoveFont: (fontId: string) => Promise<void>;
  onSelectFont: (font: AssetLibraryFont) => void;
  selectedFontId: string | null;
};

/**
 * The family's variants, previewed each in its own typeface.
 *
 * Selecting a variant scopes the example code beside it, so the cards are a
 * single-select group: a visually hidden radio inside each card's label gives
 * that its native semantics — arrow key navigation and an announced checked
 * state — without a roving tabindex of our own.
 */
const FontVariantList = ({
  family,
  fonts,
  isBusy,
  onRemoveFont,
  onSelectFont,
  selectedFontId,
}: FontVariantListProps) => (
  <Flex direction="column" gap="2" className={styles.consoleSection}>
    <Text size="2" weight="medium">
      Preview
    </Text>
    <Flex
      role="radiogroup"
      aria-label={`${family} variants`}
      direction="column"
      className={styles.variantList}
    >
      {fonts.map((font) => {
        const variantLabel = buildFontVariantLabel(font);
        const isSelected = selectedFontId === font.id;
        // Two files of one family can carry the same weight, style and format —
        // two variable files always do — so the filename is what tells them
        // apart in the accessibility tree.
        const filename = font.uri.split('/').pop() ?? font.id;

        return (
          <Box key={font.id} className={styles.variantRowWrapper}>
            <label
              className={styles.variantRow}
              data-state={isSelected ? 'active' : 'inactive'}
              data-testid={`canvas-brand-kit-font-variant-${font.id}`}
            >
              <input
                type="radio"
                className={styles.variantRadio}
                name={`canvas-brand-kit-font-variant-${family}`}
                value={font.id}
                aria-label={`${variantLabel} ${filename}`}
                checked={isSelected}
                onChange={() => onSelectFont(font)}
              />
              <Text className={styles.variantLabel}>{variantLabel}</Text>
              <Text
                className={styles.variantSpecimen}
                style={getFontPreviewStyle(font)}
              >
                The quick brown fox jumps over the lazy dog.
              </Text>
            </label>
            {/*
             * Only the selected card is deletable, as in the design. The button
             * is a sibling of the label rather than a child: inside it, a click
             * would also toggle the radio.
             */}
            {isSelected && (
              <Box className={styles.variantDeleteButton}>
                <IconButton
                  variant="ghost"
                  color="gray"
                  size="1"
                  disabled={isBusy}
                  aria-label={`Delete ${font.family} ${variantLabel} ${filename}`}
                  data-testid={`canvas-brand-kit-font-variant-delete-${font.id}`}
                  onClick={() => void onRemoveFont(font.id)}
                >
                  <TrashIcon />
                </IconButton>
              </Box>
            )}
          </Box>
        );
      })}
    </Flex>
  </Flex>
);

export default FontVariantList;
