import { Flex, Text } from '@radix-ui/themes';

import {
  getAxisSettingValue,
  getAxisStep,
} from '@/features/brandKit/variableFontState';

import type { CSSProperties } from 'react';
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
  <Flex direction="column" gap="2" className={styles.consoleSection}>
    <Text size="2" weight="medium">
      Variable axes
    </Text>
    <Flex direction="column" gap="4" className={styles.consoleCard}>
      {font.axes?.map((axis) => {
        const axisName = axis.name ?? axis.tag;
        const sliderId = `canvas-brand-kit-axis-${font.id}-${axis.tag}`;
        const rangeId = `${sliderId}-range`;
        const value = getAxisSettingValue(font, axis);
        // How far along its range the value sits, for the track's fill.
        const progress =
          axis.max === axis.min
            ? 0
            : ((value - axis.min) / (axis.max - axis.min)) * 100;

        return (
          <Flex
            key={axis.tag}
            direction="column"
            className={styles.axisControl}
          >
            <Flex align="center" justify="between" gap="2">
              <Text size="2" as="label" htmlFor={sliderId}>
                {axisName}
              </Text>
              <Text size="2">{value}</Text>
            </Flex>
            <input
              id={sliderId}
              type="range"
              min={axis.min}
              max={axis.max}
              step={getAxisStep(axis)}
              value={value}
              disabled={isBusy}
              className={styles.axisSlider}
              style={{ '--axis-progress': `${progress}%` } as CSSProperties}
              aria-describedby={rangeId}
              data-testid={`canvas-brand-kit-font-axis-${axis.tag}`}
              onChange={(event) =>
                onAxisSettingChange(font.id, axis, event.target.value)
              }
              onMouseUp={() => onAxisSettingCommit(font.id)}
              onTouchEnd={() => onAxisSettingCommit(font.id)}
              onKeyUp={() => onAxisSettingCommit(font.id)}
            />
            <Flex id={rangeId} className={styles.axisRange}>
              <Text size="1" color="gray" className={styles.axisRangeValue}>
                Min {axis.min}
              </Text>
              <Text
                size="1"
                color="gray"
                align="center"
                className={styles.axisRangeValue}
              >
                Default {axis.default}
              </Text>
              <Text
                size="1"
                color="gray"
                align="right"
                className={styles.axisRangeValue}
              >
                Max {axis.max}
              </Text>
            </Flex>
          </Flex>
        );
      })}
    </Flex>
  </Flex>
);

export default VariableFontAxesEditor;
