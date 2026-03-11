import { Box, Flex, Text } from '@radix-ui/themes';

import { buildFontFaceSnippet } from '@/features/brandKit/fontCss';
import { getFontPreviewStyle } from '@/features/brandKit/variableFontState';

import type { AssetLibraryFont } from '@/types/CodeComponent';

import styles from '../BrandKitPanel.module.css';

type FontPreviewCardProps = {
  font: AssetLibraryFont;
};

const FontPreviewCard = ({ font }: FontPreviewCardProps) => (
  <Box className={styles.previewSection}>
    <style>{buildFontFaceSnippet(font)}</style>
    <Flex direction="column" gap="2">
      <Text size="1" color="gray">
        Preview
      </Text>
      <Text
        size="6"
        className={styles.previewSample}
        style={getFontPreviewStyle(font)}
      >
        The quick brown fox jumps over the lazy dog.
      </Text>
      <Text
        size="3"
        color="gray"
        className={styles.previewSecondary}
        style={getFontPreviewStyle(font)}
      >
        Aa Bb Cc 1234567890
      </Text>
    </Flex>
  </Box>
);

export default FontPreviewCard;
