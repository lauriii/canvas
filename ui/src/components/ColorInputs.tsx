import { useEffect, useState } from 'react';
import { CaretSortIcon } from '@radix-ui/react-icons';
import {
  hexToHsva,
  hslaToHsva,
  hsvaToHex,
  hsvaToHexa,
  hsvaToHsla,
  hsvaToRgba,
  rgbaToHsva,
} from '@uiw/color-convert';

import type { HsvaColor } from '@uiw/color-convert';

import styles from './ColorPicker.module.css';

type ColorMode = 'rgba' | 'hsla' | 'hex';

interface ColorInputsProps {
  hsva: HsvaColor;
  onChange: (hsva: HsvaColor) => void;
  mode: ColorMode;
  onModeChange: (mode: ColorMode) => void;
  onValidityChange?: (isValid: boolean) => void;
}

const MODES: ColorMode[] = ['rgba', 'hsla', 'hex'];

const getNextMode = (current: ColorMode): ColorMode => {
  const currentIndex = MODES.indexOf(current);
  return MODES[(currentIndex + 1) % MODES.length] ?? 'rgba';
};

const isValidHex = (value: string): boolean => {
  return /^[0-9a-fA-F]{6}([0-9a-fA-F]{2})?$/.test(value);
};

const extractAlphaFromHex = (hex: string): number | null => {
  if (hex.length === 8) {
    const alphaHex = hex.slice(6, 8);
    const alphaInt = parseInt(alphaHex, 16);
    return Math.round(alphaInt) / 255;
  }
  return null;
};

const hsvaToHexInput = (hsva: HsvaColor): string => {
  if (hsva.a === 1) {
    return hsvaToHex(hsva).slice(1).toUpperCase();
  }
  return hsvaToHexa(hsva).slice(1).toUpperCase();
};

const hexInputToHsva = (value: string): HsvaColor | null => {
  if (!isValidHex(value)) {
    return null;
  }
  const fullHex = `#${value.toLowerCase()}`;
  const hsva = hexToHsva(fullHex);
  if (value.length === 8) {
    const alpha = extractAlphaFromHex(value);
    if (alpha !== null) {
      return { ...hsva, a: alpha };
    }
  }
  return hsva;
};

const ColorInputs = ({
  hsva,
  onChange,
  mode,
  onModeChange,
  onValidityChange,
}: ColorInputsProps) => {
  const rgba = hsvaToRgba(hsva);
  const hsla = hsvaToHsla(hsva);

  // HEX input state
  const [hexInputValue, setHexInputValue] = useState(() =>
    hsvaToHexInput(hsva),
  );
  const [isHexFocused, setIsHexFocused] = useState(false);
  const [isHexInvalid, setIsHexInvalid] = useState(false);

  // Sync hex input when hsva changes externally (and not focused)
  useEffect(() => {
    if (!isHexFocused) {
      setHexInputValue(hsvaToHexInput(hsva));
      setIsHexInvalid(false);
    }
  }, [hsva, isHexFocused]);

  // Sync hex input when switching to hex mode
  useEffect(() => {
    if (mode === 'hex') {
      setHexInputValue(hsvaToHexInput(hsva));
      setIsHexInvalid(false);
    }
  }, [mode, hsva]);

  const handleRChange = (value: string) => {
    const r = parseInt(value, 10);
    if (!isNaN(r) && r >= 0 && r <= 255) {
      onChange(rgbaToHsva({ ...rgba, r }));
    }
  };

  const handleGChange = (value: string) => {
    const g = parseInt(value, 10);
    if (!isNaN(g) && g >= 0 && g <= 255) {
      onChange(rgbaToHsva({ ...rgba, g }));
    }
  };

  const handleBChange = (value: string) => {
    const b = parseInt(value, 10);
    if (!isNaN(b) && b >= 0 && b <= 255) {
      onChange(rgbaToHsva({ ...rgba, b }));
    }
  };

  const handleAChange = (value: string) => {
    const a = parseFloat(value);
    if (!isNaN(a) && a >= 0 && a <= 1) {
      onChange(rgbaToHsva({ ...rgba, a }));
    }
  };

  const handleHChange = (value: string) => {
    const h = parseInt(value, 10);
    if (!isNaN(h) && h >= 0 && h <= 360) {
      onChange(hslaToHsva({ ...hsla, h }));
    }
  };

  const handleSChange = (value: string) => {
    const s = parseInt(value, 10);
    if (!isNaN(s) && s >= 0 && s <= 100) {
      onChange(hslaToHsva({ ...hsla, s }));
    }
  };

  const handleLChange = (value: string) => {
    const l = parseInt(value, 10);
    if (!isNaN(l) && l >= 0 && l <= 100) {
      onChange(hslaToHsva({ ...hsla, l }));
    }
  };

  const handleHslaAChange = (value: string) => {
    const a = parseFloat(value);
    if (!isNaN(a) && a >= 0 && a <= 1) {
      onChange(hslaToHsva({ ...hsla, a }));
    }
  };

  const handleHexChange = (value: string) => {
    setHexInputValue(value.toUpperCase());
    if (isValidHex(value)) {
      const newHsva = hexInputToHsva(value);
      if (newHsva !== null) {
        onChange(newHsva);
        setIsHexInvalid(false);
        onValidityChange?.(true);
      }
    } else {
      setIsHexInvalid(true);
      onValidityChange?.(false);
    }
  };

  const handleHexFocus = () => {
    setIsHexFocused(true);
  };

  const handleHexBlur = () => {
    setIsHexFocused(false);
    // Reset to valid value if currently invalid
    if (isHexInvalid) {
      setHexInputValue(hsvaToHexInput(hsva));
      setIsHexInvalid(false);
      onValidityChange?.(true);
    }
  };

  const handleModeSwitch = () => {
    onModeChange(getNextMode(mode));
  };

  const getHexInputClassName = (): string => {
    const baseClass = styles.rgbaNativeInput;
    if (isHexInvalid) {
      if (isHexFocused) {
        return `${baseClass} ${styles.rgbaNativeInputInvalidSubtle}`;
      }
      return `${baseClass} ${styles.rgbaNativeInputInvalid}`;
    }
    return baseClass;
  };

  const renderInputs = () => {
    switch (mode) {
      case 'rgba':
        return (
          <>
            <div className={styles.rgbaInputGroup}>
              <input
                id="color-r"
                type="number"
                min={0}
                max={255}
                step={1}
                value={rgba.r}
                onChange={(e) => handleRChange(e.target.value)}
                className={styles.rgbaNativeInput}
                aria-label="Red value"
              />
              <label htmlFor="color-r" className={styles.rgbaLabel}>
                R
              </label>
            </div>

            <div className={styles.rgbaInputGroup}>
              <input
                id="color-g"
                type="number"
                min={0}
                max={255}
                step={1}
                value={rgba.g}
                onChange={(e) => handleGChange(e.target.value)}
                className={styles.rgbaNativeInput}
                aria-label="Green value"
              />
              <label htmlFor="color-g" className={styles.rgbaLabel}>
                G
              </label>
            </div>

            <div className={styles.rgbaInputGroup}>
              <input
                id="color-b"
                type="number"
                min={0}
                max={255}
                step={1}
                value={rgba.b}
                onChange={(e) => handleBChange(e.target.value)}
                className={styles.rgbaNativeInput}
                aria-label="Blue value"
              />
              <label htmlFor="color-b" className={styles.rgbaLabel}>
                B
              </label>
            </div>

            <div className={styles.rgbaInputGroup}>
              <input
                id="color-a-rgba"
                type="number"
                min={0}
                max={1}
                step={0.01}
                value={rgba.a}
                onChange={(e) => handleAChange(e.target.value)}
                className={styles.rgbaNativeInput}
                aria-label="Alpha value"
              />
              <label htmlFor="color-a-rgba" className={styles.rgbaLabel}>
                A
              </label>
            </div>
          </>
        );

      case 'hsla':
        return (
          <>
            <div className={styles.rgbaInputGroup}>
              <input
                id="color-h"
                type="number"
                min={0}
                max={360}
                step={1}
                value={Math.round(hsla.h)}
                onChange={(e) => handleHChange(e.target.value)}
                className={styles.rgbaNativeInput}
                aria-label="Hue value"
              />
              <label htmlFor="color-h" className={styles.rgbaLabel}>
                H
              </label>
            </div>

            <div className={styles.rgbaInputGroup}>
              <input
                id="color-s"
                type="number"
                min={0}
                max={100}
                step={1}
                value={Math.round(hsla.s)}
                onChange={(e) => handleSChange(e.target.value)}
                className={styles.rgbaNativeInput}
                aria-label="Saturation value"
              />
              <label htmlFor="color-s" className={styles.rgbaLabel}>
                S
              </label>
            </div>

            <div className={styles.rgbaInputGroup}>
              <input
                id="color-l"
                type="number"
                min={0}
                max={100}
                step={1}
                value={Math.round(hsla.l)}
                onChange={(e) => handleLChange(e.target.value)}
                className={styles.rgbaNativeInput}
                aria-label="Lightness value"
              />
              <label htmlFor="color-l" className={styles.rgbaLabel}>
                L
              </label>
            </div>

            <div className={styles.rgbaInputGroup}>
              <input
                id="color-a-hsla"
                type="number"
                min={0}
                max={1}
                step={0.01}
                value={hsla.a}
                onChange={(e) => handleHslaAChange(e.target.value)}
                className={styles.rgbaNativeInput}
                aria-label="Alpha value"
              />
              <label htmlFor="color-a-hsla" className={styles.rgbaLabel}>
                A
              </label>
            </div>
          </>
        );

      case 'hex':
        return (
          <div className={`${styles.rgbaInputGroup} ${styles.hexInputGroup}`}>
            <div className={styles.hexInputWrapper}>
              <span className={styles.hexPrefix}>#</span>
              <input
                id="color-hex"
                type="text"
                value={hexInputValue}
                onChange={(e) => handleHexChange(e.target.value)}
                onFocus={handleHexFocus}
                onBlur={handleHexBlur}
                className={getHexInputClassName()}
                aria-label="Hex color value"
                aria-invalid={isHexInvalid}
              />
            </div>
            <label htmlFor="color-hex" className={styles.rgbaLabel}>
              HEX
            </label>
          </div>
        );

      default:
        return null;
    }
  };

  return (
    <div data-input-mode={mode} className={styles.rgbaInputs}>
      {renderInputs()}
      <button
        type="button"
        onClick={handleModeSwitch}
        className={styles.modeSwitch}
        aria-label="Switch color format"
        title="Switch color format"
      >
        <CaretSortIcon />
      </button>
    </div>
  );
};

export default ColorInputs;
