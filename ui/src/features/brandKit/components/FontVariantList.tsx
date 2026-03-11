import { ChevronRightIcon } from '@radix-ui/react-icons';
import { Box, Flex, Text } from '@radix-ui/themes';

import { buildFontVariantLabel } from '@/features/brandKit/fontCss';

import type { AssetLibraryFont } from '@/types/CodeComponent';

import styles from '../BrandKitPanel.module.css';

type FontVariantListProps = {
  fonts: AssetLibraryFont[];
  onSelectFont: (font: AssetLibraryFont) => void;
  selectedFontId: string | null;
};

const FontVariantList = ({
  fonts,
  onSelectFont,
  selectedFontId,
}: FontVariantListProps) => (
  <Flex direction="column" gap="2">
    <Text size="1" color="gray">
      Variants
    </Text>
    <Box className={styles.variantList}>
      {fonts.map((font) => (
        <button
          key={font.id}
          type="button"
          className={styles.variantRow}
          data-state={selectedFontId === font.id ? 'active' : 'inactive'}
          onClick={() => onSelectFont(font)}
        >
          <Box className={styles.variantRowMeta}>
            <Text weight="medium">{buildFontVariantLabel(font)}</Text>
            <Text size="1" color="gray" className={styles.variantFilename}>
              {font.uri.split('/').pop() ?? font.id}
            </Text>
          </Box>
          <ChevronRightIcon className={styles.variantRowChevron} />
        </button>
      ))}
    </Box>
  </Flex>
);

export default FontVariantList;
