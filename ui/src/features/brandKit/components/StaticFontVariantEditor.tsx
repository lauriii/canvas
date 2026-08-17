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

const StaticFontVariantEditor = ({
  font,
  isBusy,
  onWeightChange,
  onWeightCommit,
  onStyleChange,
}: StaticFontVariantEditorProps) => (
  <Flex gap="3">
    <Flex direction="column" gap="2" flexGrow="1">
      <Text size="1" color="gray">
        {Drupal.t('Weight', {}, { context: 'Canvas brand kit' })}
      </Text>
      <TextField.Root
        value={font.weight}
        onChange={(event) => onWeightChange(font.id, event.target.value)}
        onBlur={() => void onWeightCommit(font.id)}
        disabled={isBusy}
      />
    </Flex>
    <Flex direction="column" gap="2" flexGrow="1">
      <Text size="1" color="gray">
        {Drupal.t('Style', {}, { context: 'Canvas brand kit' })}
      </Text>
      <Select.Root
        value={font.style}
        onValueChange={(value) => void onStyleChange(font.id, value)}
        size="2"
        disabled={isBusy}
      >
        <Select.Trigger />
        <Select.Content position="popper" className={styles.styleSelectContent}>
          <Select.Item value="normal">
            {Drupal.t('Normal', {}, { context: 'Canvas brand kit' })}
          </Select.Item>
          <Select.Item value="italic">
            {Drupal.t('Italic', {}, { context: 'Canvas brand kit' })}
          </Select.Item>
        </Select.Content>
      </Select.Root>
    </Flex>
  </Flex>
);

export default StaticFontVariantEditor;
