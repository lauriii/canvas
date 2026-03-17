import { useEffect, useRef, useState } from 'react';
import { Box, Flex, Select } from '@radix-ui/themes';

import { useAppDispatch } from '@/app/hooks';
import { updateProp } from '@/features/code-editor/codeEditorSlice';
import {
  Divider,
  FormElement,
  Label,
} from '@/features/code-editor/component-data/FormElement';

import type {
  CodeComponentProp,
  CodeComponentPropImageExample,
  ValueMode,
} from '@/types/CodeComponent';

const IMAGE_SERVICE_URL = 'https://placehold.co/';

const NONE_VALUE = '_none_';
const EXAMPLE_ASPECT_RATIO_VALUES = [
  { value: '1:1', label: '1:1 (Square)', width: 600, height: 600 },
  { value: '4:3', label: '4:3 (Standard)', width: 800, height: 600 },
  { value: '16:9', label: '16:9 (Widescreen)', width: 1280, height: 720 },
  { value: '3:2', label: '3:2 (Classic Photo)', width: 900, height: 600 },
  { value: '2:1', label: '2:1 (Panoramic)', width: 1000, height: 500 },
  { value: '9:16', label: '9:16 (Vertical)', width: 720, height: 1280 },
  { value: '21:9', label: '21:9 (Ultrawide)', width: 1400, height: 600 },
];
const DEFAULT_ASPECT_RATIO = EXAMPLE_ASPECT_RATIO_VALUES[1].value;

const EXAMPLE_PIXEL_DENSITY_OPTIONS = [
  { value: '1x', label: '1x (Standard density)' },
  { value: '2x', label: '2x (High density)' },
  { value: '3x', label: '3x (Ultra-high density)' },
];
const DEFAULT_PIXEL_DENSITY = EXAMPLE_PIXEL_DENSITY_OPTIONS[1].value;

export const parseExampleSrc = (
  src: string,
): { aspectRatio: string; pixelDensity: string } => {
  // Default values if parsing fails
  const defaults = {
    aspectRatio: DEFAULT_ASPECT_RATIO,
    pixelDensity: DEFAULT_PIXEL_DENSITY,
  };

  if (!src || !src.startsWith(IMAGE_SERVICE_URL)) {
    return defaults;
  }

  try {
    // Extract dimensions and density from URL
    // Example: https://placehold.co/800x600@2x.png
    const match = src.match(/(\d+)x(\d+)(?:@(\d+)x)?/);
    if (!match) return defaults;

    const [, width, height, density = '1'] = match;

    // Find exact matching aspect ratio
    const aspectRatio =
      EXAMPLE_ASPECT_RATIO_VALUES.find(
        (ratio) =>
          ratio.width === Number(width) && ratio.height === Number(height),
      )?.value || DEFAULT_ASPECT_RATIO;

    // Find matching pixel density
    const pixelDensity = `${density}x`;
    if (
      !EXAMPLE_PIXEL_DENSITY_OPTIONS.some(
        (option) => option.value === pixelDensity,
      )
    ) {
      return { aspectRatio, pixelDensity: DEFAULT_PIXEL_DENSITY };
    }

    return { aspectRatio, pixelDensity };
  } catch (error) {
    console.error('Error parsing example URL:', error);
    return defaults;
  }
};

export default function FormPropTypeImage({
  id,
  example,
  required,
  allowMultiple = false,
  valueMode = 'unlimited',
  limitedCount = 1,
}: Pick<CodeComponentProp, 'id'> & {
  example: CodeComponentPropImageExample | CodeComponentPropImageExample[];
  required: boolean;
  allowMultiple?: boolean;
  valueMode?: ValueMode;
  limitedCount?: number;
}) {
  // Handle both single image and array (when allowMultiple is enabled)
  // If it's an array, use the first element for display
  // Handle edge case where example might be an empty string (when prop type is first created/changed)
  const imageExample = Array.isArray(example)
    ? example[0] || { src: '', width: 0, height: 0, alt: '' }
    : !example
      ? { src: '', width: 0, height: 0, alt: '' }
      : example;

  const { aspectRatio: exampleAspectRatio, pixelDensity: examplePixelDensity } =
    parseExampleSrc(imageExample.src);
  const dispatch = useAppDispatch();
  const [aspectRatio, setAspectRatio] = useState(exampleAspectRatio);
  const [pixelDensity, setPixelDensity] = useState(examplePixelDensity);
  const [localRequired, setLocalRequired] = useState(required);

  // Use a ref to track the previous values of the UI-controlled inputs (aspectRatio,
  // pixelDensity) and the multi-value settings (allowMultiple, valueMode, limitedCount).
  //
  // Why this is needed to prevent an infinite loop:
  //   1. The main useEffect below reads these values and dispatches `updateProp`, which
  //      writes a new `example` object/array back into the Redux store.
  //   2. `example` is a dependency of that same effect (needed to build the correct
  //      multi-value array in unlimited mode).
  //   3. Without a guard, each dispatch → new `example` reference → effect re-runs →
  //      another dispatch → … repeating forever.
  //
  // By comparing the current UI-input values against the ref before dispatching, the
  // effect exits early when nothing the user actually changed, breaking the cycle.
  const prevValuesRef = useRef({
    aspectRatio: exampleAspectRatio,
    pixelDensity: examplePixelDensity,
    allowMultiple,
    valueMode,
    limitedCount,
  });

  // Track if we're still initializing (on first mount)
  const isInitialMount = useRef(true);

  // Sync state with incoming example prop (for pre-population on reload)
  useEffect(() => {
    // If example is empty array, keep current aspectRatio (don't reset to default)
    if (Array.isArray(example) && example.length === 0) {
      return;
    }
    // If example has valid content, parse and update state
    if (imageExample.src) {
      const parsedValues = parseExampleSrc(imageExample.src);
      setAspectRatio(parsedValues.aspectRatio);
      setPixelDensity(parsedValues.pixelDensity);
      // Also update the ref so the main effect doesn't trigger on mount
      prevValuesRef.current = {
        ...prevValuesRef.current,
        aspectRatio: parsedValues.aspectRatio,
        pixelDensity: parsedValues.pixelDensity,
      };
    }
    // If example is empty string AND aspectRatio is not NONE_VALUE, it means we're initializing
    // Let the main useEffect handle initialization with defaults
    // Only set to NONE_VALUE if aspectRatio was already NONE_VALUE (user explicitly selected None)
  }, [imageExample.src, example]);

  useEffect(() => {
    // Mark as no longer initial mount after first render
    if (isInitialMount.current) {
      isInitialMount.current = false;
    }
  }, []);

  useEffect(() => {
    // Track changes to the required prop, update aspect ratio if needed.
    setLocalRequired(required);
    if (required !== localRequired && required && aspectRatio === NONE_VALUE) {
      setAspectRatio(DEFAULT_ASPECT_RATIO);
    }
  }, [required, localRequired, aspectRatio]);

  useEffect(() => {
    // Skip on initial mount - let the sync effect handle loading saved values
    if (isInitialMount.current) {
      return;
    }

    // Check if any relevant values have changed
    const hasChanged =
      prevValuesRef.current.aspectRatio !== aspectRatio ||
      prevValuesRef.current.pixelDensity !== pixelDensity ||
      prevValuesRef.current.allowMultiple !== allowMultiple ||
      prevValuesRef.current.valueMode !== valueMode ||
      prevValuesRef.current.limitedCount !== limitedCount;

    if (!hasChanged) {
      return;
    }

    // Detect if aspect ratio or pixel density changed (not just mode switches)
    const aspectOrDensityChanged =
      prevValuesRef.current.aspectRatio !== aspectRatio ||
      prevValuesRef.current.pixelDensity !== pixelDensity;

    // Update ref with new values
    prevValuesRef.current = {
      aspectRatio,
      pixelDensity,
      allowMultiple,
      valueMode,
      limitedCount,
    };

    if (aspectRatio === NONE_VALUE) {
      dispatch(
        updateProp({
          id,
          updates: {
            example: allowMultiple ? [] : '',
          },
        }),
      );
      return;
    }

    const pixelDensitySuffix = pixelDensity === '1x' ? '' : `@${pixelDensity}`;
    const aspectRatioData =
      EXAMPLE_ASPECT_RATIO_VALUES.find(
        (ratio) => ratio.value === aspectRatio,
      ) || EXAMPLE_ASPECT_RATIO_VALUES[0];

    const alternateWidths = `${IMAGE_SERVICE_URL}{width}x{height}${pixelDensitySuffix}.png`;
    const imageObject: CodeComponentPropImageExample = {
      src: `${IMAGE_SERVICE_URL}${aspectRatioData.width}x${aspectRatioData.height}${pixelDensitySuffix}.png?alternateWidths=${encodeURIComponent(alternateWidths)}`,
      width: aspectRatioData.width,
      height: aspectRatioData.height,
      alt: 'Example image placeholder',
    };

    // For multi-value mode, create an array
    if (allowMultiple) {
      const currentArray = Array.isArray(example) ? example : [];

      // For limited mode, ensure we have exactly limitedCount items
      if (valueMode === 'limited') {
        // Update all items to use the new imageObject (aspect ratio/pixel density changes should apply to all)
        const newArray = Array.from(
          { length: limitedCount },
          () => imageObject,
        );
        dispatch(
          updateProp({
            id,
            updates: {
              example: newArray,
            },
          }),
        );
      } else {
        // For unlimited mode:
        // - If aspect ratio or pixel density changed, update all existing items
        // - Otherwise, keep existing array or create one with single item
        let newArray;
        if (aspectOrDensityChanged && currentArray.length > 0) {
          // Update all existing items with new aspect ratio/pixel density
          newArray = currentArray.map(() => imageObject);
        } else {
          // Keep existing items or create initial item
          newArray = currentArray.length > 0 ? currentArray : [imageObject];
        }
        dispatch(
          updateProp({
            id,
            updates: {
              example: newArray,
            },
          }),
        );
      }
    } else {
      // Single value mode
      dispatch(
        updateProp({
          id,
          updates: {
            example: imageObject,
          },
        }),
      );
    }
  }, [
    aspectRatio,
    pixelDensity,
    dispatch,
    id,
    allowMultiple,
    valueMode,
    limitedCount,
    example,
  ]);

  return (
    <Flex direction="column" gap="4" flexGrow="1">
      <Divider />
      <Flex gap="4" width="100%">
        <Box flexBasis="50%" flexShrink="0">
          <FormElement>
            <Label htmlFor={`prop-example-${id}`}>Example aspect ratio</Label>
            <Select.Root
              value={aspectRatio}
              onValueChange={setAspectRatio}
              size="1"
            >
              <Select.Trigger id={`prop-example-${id}`} />
              <Select.Content>
                {!required && (
                  <Select.Item value={NONE_VALUE}>- None -</Select.Item>
                )}
                {EXAMPLE_ASPECT_RATIO_VALUES.map((value) => (
                  <Select.Item key={value.value} value={value.value}>
                    {value.label}
                  </Select.Item>
                ))}
              </Select.Content>
            </Select.Root>
          </FormElement>
        </Box>
        {aspectRatio !== NONE_VALUE && (
          <Box flexGrow="1">
            <FormElement>
              <Label htmlFor={`prop-example-pixel-density-${id}`}>
                Pixel density
              </Label>
              <Select.Root
                value={pixelDensity}
                onValueChange={setPixelDensity}
                size="1"
              >
                <Select.Trigger id={`prop-example-pixel-density-${id}`} />
                <Select.Content>
                  {EXAMPLE_PIXEL_DENSITY_OPTIONS.map((value) => (
                    <Select.Item key={value.value} value={value.value}>
                      {value.label}
                    </Select.Item>
                  ))}
                </Select.Content>
              </Select.Root>
            </FormElement>
          </Box>
        )}
      </Flex>
    </Flex>
  );
}
