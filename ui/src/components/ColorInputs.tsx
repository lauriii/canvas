import { useCallback, useEffect, useRef, useState } from 'react';
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

type NumericInputOptions = {
  isInteger?: boolean;
  precision?: number;
};

const roundToPrecision = (value: number, precision: number): number => {
  const factor = 10 ** precision;
  return Math.round(value * factor) / factor;
};

const useNumericInput = (
  value: number,
  onChange: (value: number) => void,
  isValid: (value: number) => boolean,
  { isInteger = false, precision }: NumericInputOptions = {},
) => {
  const format = useCallback(
    (v: number) => {
      if (isInteger) {
        return String(Math.round(v));
      }
      if (precision !== undefined) {
        return String(roundToPrecision(v, precision));
      }
      return String(v);
    },
    [isInteger, precision],
  );
  const [inputValue, setInputValue] = useState(format(value));
  const [isFocused, setIsFocused] = useState(false);

  useEffect(() => {
    if (!isFocused) {
      setInputValue(format(value));
    }
  }, [value, isFocused, format]);

  const parse = (v: string): number | null => {
    if (v === '') {
      return null;
    }
    const parsed = isInteger ? parseInt(v, 10) : parseFloat(v);
    return isNaN(parsed) ? null : parsed;
  };

  const isInvalid = (() => {
    const parsed = parse(inputValue);
    return parsed === null || !isValid(parsed);
  })();

  const className = (() => {
    const baseClass = styles.rgbaNativeInput;
    if (isInvalid) {
      if (isFocused) {
        return `${baseClass} ${styles.rgbaNativeInputInvalidSubtle}`;
      }
      return `${baseClass} ${styles.rgbaNativeInputInvalid}`;
    }
    return baseClass;
  })();

  const handleChange = (newValue: string) => {
    setInputValue(newValue);
    const parsed = parse(newValue);
    if (parsed !== null && isValid(parsed)) {
      onChange(
        precision !== undefined ? roundToPrecision(parsed, precision) : parsed,
      );
    }
  };

  const handleFocus = () => {
    setIsFocused(true);
  };

  const handleBlur = () => {
    setIsFocused(false);
    setInputValue(format(value));
  };

  return {
    value: inputValue,
    isInvalid,
    className,
    onChange: handleChange,
    onFocus: handleFocus,
    onBlur: handleBlur,
  };
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

  const rInput = useNumericInput(
    rgba.r,
    (r) => onChange(rgbaToHsva({ ...rgba, r })),
    (v) => v >= 0 && v <= 255,
    { isInteger: true },
  );
  const gInput = useNumericInput(
    rgba.g,
    (g) => onChange(rgbaToHsva({ ...rgba, g })),
    (v) => v >= 0 && v <= 255,
    { isInteger: true },
  );
  const bInput = useNumericInput(
    rgba.b,
    (b) => onChange(rgbaToHsva({ ...rgba, b })),
    (v) => v >= 0 && v <= 255,
    { isInteger: true },
  );
  const aInput = useNumericInput(
    rgba.a,
    (a) => onChange(rgbaToHsva({ ...rgba, a })),
    (v) => v >= 0 && v <= 1,
    { precision: 2 },
  );

  const hInput = useNumericInput(
    Math.round(hsla.h),
    (h) => onChange(hslaToHsva({ ...hsla, h })),
    (v) => v >= 0 && v <= 360,
    { isInteger: true },
  );
  const sInput = useNumericInput(
    Math.round(hsla.s),
    (s) => onChange(hslaToHsva({ ...hsla, s })),
    (v) => v >= 0 && v <= 100,
    { isInteger: true },
  );
  const lInput = useNumericInput(
    Math.round(hsla.l),
    (l) => onChange(hslaToHsva({ ...hsla, l })),
    (v) => v >= 0 && v <= 100,
    { isInteger: true },
  );
  const hslaAInput = useNumericInput(
    hsla.a,
    (a) => onChange(hslaToHsva({ ...hsla, a })),
    (v) => v >= 0 && v <= 1,
    { precision: 2 },
  );

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

  const isNumericInputValid =
    !rInput.isInvalid &&
    !gInput.isInvalid &&
    !bInput.isInvalid &&
    !aInput.isInvalid &&
    !hInput.isInvalid &&
    !sInput.isInvalid &&
    !lInput.isInvalid &&
    !hslaAInput.isInvalid;
  const isHexInputValid = mode !== 'hex' || !isHexInvalid;
  const isValid = isNumericInputValid && isHexInputValid;

  const prevIsValidRef = useRef(true);

  useEffect(() => {
    if (isValid !== prevIsValidRef.current) {
      prevIsValidRef.current = isValid;
      onValidityChange?.(isValid);
    }
  }, [isValid, onValidityChange]);

  const handleHexChange = (value: string) => {
    setHexInputValue(value.toUpperCase());
    if (isValidHex(value)) {
      const newHsva = hexInputToHsva(value);
      if (newHsva !== null) {
        onChange({ ...newHsva, a: roundToPrecision(newHsva.a, 2) });
        setIsHexInvalid(false);
      }
    } else {
      setIsHexInvalid(true);
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
                value={rInput.value}
                onChange={(e) => rInput.onChange(e.target.value)}
                onFocus={rInput.onFocus}
                onBlur={rInput.onBlur}
                className={rInput.className}
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
                value={gInput.value}
                onChange={(e) => gInput.onChange(e.target.value)}
                onFocus={gInput.onFocus}
                onBlur={gInput.onBlur}
                className={gInput.className}
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
                value={bInput.value}
                onChange={(e) => bInput.onChange(e.target.value)}
                onFocus={bInput.onFocus}
                onBlur={bInput.onBlur}
                className={bInput.className}
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
                value={aInput.value}
                onChange={(e) => aInput.onChange(e.target.value)}
                onFocus={aInput.onFocus}
                onBlur={aInput.onBlur}
                className={aInput.className}
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
                value={hInput.value}
                onChange={(e) => hInput.onChange(e.target.value)}
                onFocus={hInput.onFocus}
                onBlur={hInput.onBlur}
                className={hInput.className}
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
                value={sInput.value}
                onChange={(e) => sInput.onChange(e.target.value)}
                onFocus={sInput.onFocus}
                onBlur={sInput.onBlur}
                className={sInput.className}
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
                value={lInput.value}
                onChange={(e) => lInput.onChange(e.target.value)}
                onFocus={lInput.onFocus}
                onBlur={lInput.onBlur}
                className={lInput.className}
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
                value={hslaAInput.value}
                onChange={(e) => hslaAInput.onChange(e.target.value)}
                onFocus={hslaAInput.onFocus}
                onBlur={hslaAInput.onBlur}
                className={hslaAInput.className}
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
