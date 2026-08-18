import { Flex, Select, Text, TextField } from '@radix-ui/themes';

import type { AssetLibraryFont } from '@/types/CodeComponent';

import styles from '../BrandKitPanel.module.css';

type StaticFontVariantEditorProps = {
  font: AssetLibraryFont;
  isBusy: boolean;
  onWeightChange: (fontId: string, value: string) => void;
  onWeightCommit: (fontId: string) => Promise<void>;
  onStyleChange: (fontId: string, style: string) => Promise<void>;
};

/**
 * Not in the design, which reads weight and style out of the font file. Kept
 * because that detection is best effort: a file whose metadata cannot be parsed
 * lands on 400 normal, and this is the only way to correct it.
 */
const StaticFontVariantEditor = ({
  font,
  isBusy,
  onWeightChange,
  onWeightCommit,
  onStyleChange,
}: StaticFontVariantEditorProps) => (
  <Flex direction="column" gap="2" className={styles.consoleSection}>
    <Text size="2" weight="medium">
      Variant settings
    </Text>
    <Flex gap="3" className={styles.consoleCard}>
      <Flex direction="column" gap="2" flexGrow="1">
        <Text size="1" color="gray" as="label" htmlFor={`weight-${font.id}`}>
          Weight
        </Text>
        <TextField.Root
          id={`weight-${font.id}`}
          value={font.weight}
          onChange={(event) => onWeightChange(font.id, event.target.value)}
          onBlur={() => void onWeightCommit(font.id)}
          disabled={isBusy}
        />
      </Flex>
      <Flex direction="column" gap="2" flexGrow="1">
        <Text size="1" color="gray" id={`style-label-${font.id}`}>
          Style
        </Text>
        <Select.Root
          value={font.style}
          onValueChange={(value) => void onStyleChange(font.id, value)}
          size="2"
          disabled={isBusy}
        >
          <Select.Trigger aria-labelledby={`style-label-${font.id}`} />
          <Select.Content
            position="popper"
            className={styles.styleSelectContent}
          >
            <Select.Item value="normal">Normal</Select.Item>
            <Select.Item value="italic">Italic</Select.Item>
          </Select.Content>
        </Select.Root>
      </Flex>
    </Flex>
  </Flex>
);

export default StaticFontVariantEditor;
