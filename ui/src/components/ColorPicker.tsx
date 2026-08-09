import { useEffect, useRef, useState } from 'react';
import { IconButton, Tooltip } from '@radix-ui/themes';
import {
  hexToHsva,
  hslaToHsva,
  hsvaToHex,
  hsvaToHsla,
  hsvaToRgba,
} from '@uiw/color-convert';
import { Alpha, Hue, Saturation } from '@uiw/react-color';

import ColorInputs from '@/components/ColorInputs';
import { getColorHex } from '@/utils/brandKitColor';

import type { HsvaColor } from '@uiw/color-convert';
import type { BrandKitColorValue } from '@/types/CodeComponent';

import styles from './ColorPicker.module.css';

declare global {
  interface Window {
    EyeDropper?: new () => { open(): Promise<{ sRGBHex: string }> };
  }
}

// Determine initial color mode based on the stored colorSpace.
// Hex mode is only activated when the user explicitly switches to it.
const getInitialColorMode = (
  colorSpace: BrandKitColorValue['colorSpace'],
): 'rgba' | 'hsla' => (colorSpace === 'hsl' ? 'hsla' : 'rgba');

// Convert BrandKitColorValue to HsvaColor for internal picker state
const valueToHsva = (val: BrandKitColorValue): HsvaColor => {
  const alpha = val.alpha ?? 1;

  switch (val.colorSpace) {
    case 'hsl': {
      const hsla = {
        h: val.components[0],
        s: val.components[1],
        l: val.components[2],
        a: alpha,
      };
      return hslaToHsva(hsla);
    }
    case 'srgb':
    default: {
      const hex = getColorHex(val);
      const hsva = hexToHsva(hex);
      return { ...hsva, a: alpha };
    }
  }
};

// Convert HsvaColor back to BrandKitColorValue
const hsvaToValue = (
  hsva: HsvaColor,
  currentColorSpace: BrandKitColorValue['colorSpace'],
): BrandKitColorValue => {
  const alpha = hsva.a === 1 ? null : hsva.a;
  const hex = hsvaToHex(hsva);

  switch (currentColorSpace) {
    case 'hsl': {
      const hsla = hsvaToHsla(hsva);
      return {
        colorSpace: 'hsl',
        components: [
          Math.round(hsla.h),
          Math.round(hsla.s),
          Math.round(hsla.l),
        ],
        alpha,
        hex,
      };
    }
    case 'srgb':
    default: {
      const rgba = hsvaToRgba(hsva);
      return {
        colorSpace: 'srgb',
        components: [rgba.r / 255, rgba.g / 255, rgba.b / 255],
        alpha,
        hex,
      };
    }
  }
};

interface ColorPickerProps {
  value: BrandKitColorValue;
  onChange: (value: BrandKitColorValue) => void;
  onValidityChange?: (isValid: boolean) => void;
}

const ColorPicker = ({
  value,
  onChange,
  onValidityChange,
}: ColorPickerProps) => {
  const [hsva, setHsva] = useState<HsvaColor>(() => valueToHsva(value));
  const [colorMode, setColorMode] = useState<'rgba' | 'hsla' | 'hex'>(() =>
    getInitialColorMode(value.colorSpace),
  );

  // Track previous colorSpace to detect external changes
  const prevColorSpaceRef = useRef<BrandKitColorValue['colorSpace']>(
    value.colorSpace,
  );
  // Track if the mode was explicitly set by user (to prevent resetting hex mode)
  const userModeRef = useRef<'rgba' | 'hsla' | 'hex' | null>(null);

  // Sync external value changes back into internal HSVA state
  useEffect(() => {
    setHsva(valueToHsva(value));
    // Only update colorMode when colorSpace changes externally
    // (not when user switches modes internally)
    if (value.colorSpace !== prevColorSpaceRef.current) {
      prevColorSpaceRef.current = value.colorSpace;
      // Don't override user-selected hex mode when colorSpace changes to srgb
      if (userModeRef.current !== 'hex' || value.colorSpace !== 'srgb') {
        const expectedMode = getInitialColorMode(value.colorSpace);
        setColorMode(expectedMode);
      }
    }
  }, [value]);

  // Handle colorSpace change when switching input modes
  const handleModeChange = (newMode: 'rgba' | 'hsla' | 'hex') => {
    setColorMode(newMode);
    // Track user-selected mode to prevent auto-reset
    userModeRef.current = newMode;

    // Determine the target colorSpace based on the new mode
    // hex mode uses srgb colorSpace
    const targetColorSpace: BrandKitColorValue['colorSpace'] =
      newMode === 'hsla' ? 'hsl' : 'srgb';

    // Only convert if the colorSpace is actually changing
    if (targetColorSpace !== value.colorSpace) {
      onChange(hsvaToValue(hsva, targetColorSpace));
    }
  };

  const handleHsvaChange = (newHsva: HsvaColor) => {
    setHsva(newHsva);
    onChange(hsvaToValue(newHsva, value.colorSpace));
  };

  const handleColorInputsChange = (newHsva: HsvaColor) => {
    setHsva(newHsva);
    onChange(hsvaToValue(newHsva, value.colorSpace));
  };

  const hasEyeDropper = typeof window !== 'undefined' && 'EyeDropper' in window;

  const handleEyeDropper = async () => {
    if (!hasEyeDropper || !window.EyeDropper) {
      return;
    }
    try {
      const eyeDropper = new window.EyeDropper();
      const result = await eyeDropper.open();
      if (result && result.sRGBHex) {
        const newHsva = hexToHsva(result.sRGBHex);
        // Keep existing alpha when using eyedropper
        const mergedHsva: HsvaColor = { ...newHsva, a: hsva.a };
        setHsva(mergedHsva);
        // Eyedropper always produces sRGB
        const rgba = hsvaToRgba(mergedHsva);
        onChange({
          colorSpace: 'srgb',
          components: [rgba.r / 255, rgba.g / 255, rgba.b / 255],
          alpha: mergedHsva.a === 1 ? null : mergedHsva.a,
          hex: result.sRGBHex,
        });
      }
    } catch {
      // User cancelled or eyedropper failed — no action needed
    }
  };

  const rgba = hsvaToRgba(hsva);
  const currentColorStyle = `rgba(${rgba.r}, ${rgba.g}, ${rgba.b}, ${rgba.a})`;

  return (
    <div className={styles.colorPicker}>
      {/* Row 1: Saturation box - full width, no side padding */}
      <div className={styles.saturationBox}>
        <Saturation
          hsva={hsva}
          onChange={(newColor) =>
            handleHsvaChange({ ...hsva, ...newColor, a: hsva.a })
          }
        />
      </div>

      {/* Row 2: Eyedropper, color swatch, and sliders */}
      <div className={styles.row2}>
        <div className={styles.toolsColumn}>
          <Tooltip
            content={
              hasEyeDropper
                ? Drupal.t('Pick a color from the screen')
                : Drupal.t('Not available in this browser')
            }
          >
            <IconButton
              type="button"
              size="1"
              variant="soft"
              onClick={handleEyeDropper}
              disabled={!hasEyeDropper}
              className={styles.eyeDropperButton}
              aria-label={Drupal.t('Pick color from screen')}
            >
              {/* Eyedropper/pipette SVG icon.  */}
              <svg
                xmlns="http://www.w3.org/2000/svg"
                width="16"
                height="16"
                viewBox="0 0 24 24"
                fill="none"
              >
                <path
                  d="M17.8058 7.75488L16.2459 6.19499C15.986 5.935 15.566 5.935 15.306 6.19499L13.2262 8.27484L11.9396 7.0016L10.9996 7.94153L11.9462 8.88813L6 14.8344V18.0008H9.16644L15.1127 12.0546L16.0593 13.0012L16.9992 12.0612L15.7193 10.7813L17.7992 8.70148C18.0658 8.43483 18.0658 8.01486 17.8058 7.75488ZM8.61315 16.6676L7.33324 15.3877L12.7062 10.0147L13.9861 11.2946L8.61315 16.6676Z"
                  fill="currentColor"
                />
              </svg>
            </IconButton>
          </Tooltip>
          <div
            className={styles.colorSwatch}
            style={{ backgroundColor: currentColorStyle }}
            aria-label={Drupal.t('Current color')}
          />
        </div>

        <div className={styles.slidersColumn}>
          <div className={styles.sliderWrapper}>
            <Hue
              hue={hsva.h}
              onChange={(newHue) => handleHsvaChange({ ...hsva, h: newHue.h })}
            />
          </div>
          <div className={styles.sliderWrapper}>
            <Alpha
              hsva={hsva}
              onChange={(newAlpha) =>
                handleHsvaChange({ ...hsva, a: newAlpha.a })
              }
            />
          </div>
        </div>
      </div>

      {/* Row 3: RGBA/HSLA/HEX inputs */}
      <div className={styles.rgbaRow}>
        <ColorInputs
          hsva={hsva}
          onChange={handleColorInputsChange}
          mode={colorMode}
          onModeChange={handleModeChange}
          onValidityChange={onValidityChange}
        />
      </div>
    </div>
  );
};

export default ColorPicker;
