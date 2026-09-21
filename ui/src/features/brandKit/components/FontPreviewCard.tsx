import { Flex, Text } from '@radix-ui/themes';

import { getFontPreviewStyle } from '@/features/brandKit/variableFontState';

import type { AssetLibraryFont } from '@/types/CodeComponent';

import styles from '../BrandKitPanel.module.css';

type FontPreviewCardProps = {
  font: AssetLibraryFont;
};

const FontPreviewCard = ({ font }: FontPreviewCardProps) => (
  <Flex direction="column" gap="2" className={styles.consoleSection}>
    <Text size="2" weight="medium">
      Preview
    </Text>
    <Flex direction="column" className={styles.previewCard}>
      <Text
        size="6"
        className={styles.previewSample}
        style={getFontPreviewStyle(font)}
        data-testid={`canvas-brand-kit-font-preview-${font.id}`}
      >
        The quick brown fox jumps over the lazy dog.
      </Text>
      <Text
        size="2"
        weight="medium"
        className={styles.previewSecondary}
        style={getFontPreviewStyle(font)}
      >
        abcdefghijklmnopqrstuvwxyz 0123456789
      </Text>
    </Flex>
  </Flex>
);

export default FontPreviewCard;
