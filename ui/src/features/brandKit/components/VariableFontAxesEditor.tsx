import { Flex, Text } from '@radix-ui/themes';

import {
  getAxisSettingValue,
  getAxisStep,
} from '@/features/brandKit/variableFontState';

import type {
  AssetLibraryFont,
  AssetLibraryFontAxis,
} from '@/types/CodeComponent';

import styles from '../BrandKitPanel.module.css';

type VariableFontAxesEditorProps = {
  font: AssetLibraryFont;
  isBusy: boolean;
  onAxisSettingChange: (
    fontId: string,
    axis: AssetLibraryFontAxis,
    value: string,
  ) => void;
  onAxisSettingCommit: (fontId: string) => void;
};

const VariableFontAxesEditor = ({
  font,
  isBusy,
  onAxisSettingChange,
  onAxisSettingCommit,
}: VariableFontAxesEditorProps) => (
  <Flex direction="column" gap="3">
    <Text size="1" color="gray">
      CSS axes
    </Text>
    {font.axes?.map((axis) => (
      <Flex
        key={axis.tag}
        direction="column"
        gap="2"
        className={styles.axisControl}
      >
        <Flex align="center" justify="between" gap="2">
          <Text size="2" weight="medium">
            {axis.name ?? axis.tag}
          </Text>
          <Text size="1" color="gray">
            {getAxisSettingValue(font, axis)}
          </Text>
        </Flex>
        <input
          type="range"
          min={axis.min}
          max={axis.max}
          step={getAxisStep(axis)}
          value={getAxisSettingValue(font, axis)}
          disabled={isBusy}
          className={styles.axisSlider}
          onChange={(event) =>
            onAxisSettingChange(font.id, axis, event.target.value)
          }
          onMouseUp={() => onAxisSettingCommit(font.id)}
          onTouchEnd={() => onAxisSettingCommit(font.id)}
          onKeyUp={() => onAxisSettingCommit(font.id)}
        />
        <Flex justify="between" gap="2">
          <Text size="1" color="gray">
            Min {axis.min}
          </Text>
          <Text size="1" color="gray">
            Default {axis.default}
          </Text>
          <Text size="1" color="gray">
            Max {axis.max}
          </Text>
        </Flex>
      </Flex>
    ))}
  </Flex>
);

export default VariableFontAxesEditor;
