import { useEffect, useRef, useState } from 'react';
import { Flex, Select } from '@radix-ui/themes';

import { useAppDispatch } from '@/app/hooks';
import { updateProp } from '@/features/code-editor/codeEditorSlice';
import {
  Divider,
  FormElement,
  Label,
} from '@/features/code-editor/component-data/FormElement';
import { getCanvasModuleBaseUrl } from '@/utils/drupal-globals';

import type {
  CodeComponentProp,
  CodeComponentPropVideoExample,
  ValueMode,
} from '@/types/CodeComponent';

const moduleBaseUrl = getCanvasModuleBaseUrl() || '';

const POSTER_SERVICE_URL = 'https://placehold.co/';
export const CONFIG_EXAMPLE_URLS = {
  '16:9': `/ui/assets/videos/mountain_wide.mp4`,
  '9:16': `/ui/assets/videos/bird_vertical.mp4`,
};
// The live preview of the code component must be able to render
const VIDEO_SERVICE_URLS = {
  '16:9': `${moduleBaseUrl}${CONFIG_EXAMPLE_URLS['16:9']}`,
  '9:16': `${moduleBaseUrl}${CONFIG_EXAMPLE_URLS['9:16']}`,
};
const NONE_VALUE = '_none_';

// Generate the URL for the poster using the selected Aspect ratio.
export const EXAMPLE_ASPECT_RATIO_VALUES = [
  {
    value: '16:9',
    label: 'Widescreen',
    width: 1920,
    height: 1080,
    exampleSrc: VIDEO_SERVICE_URLS['16:9'],
  },
  {
    value: '9:16',
    label: 'Vertical',
    width: 1080,
    height: 1920,
    exampleSrc: VIDEO_SERVICE_URLS['9:16'],
  },
];

const DEFAULT_ASPECT_RATIO = EXAMPLE_ASPECT_RATIO_VALUES[0].value;

export const parseExampleSrc = (src: string): any => {
  if (!src || !src.startsWith(POSTER_SERVICE_URL)) {
    return DEFAULT_ASPECT_RATIO;
  }
  try {
    // Match dimensions in formats like 800x600, 1200x900, etc.
    const regex = /(\d+)x(\d+)/;
    const match = src.match(regex);
    if (!match) {
      return DEFAULT_ASPECT_RATIO;
    }
    const [, width, height] = match;
    return (
      EXAMPLE_ASPECT_RATIO_VALUES.find(
        (ratio) =>
          ratio.width === Number(width) && ratio.height === Number(height),
      )?.value || DEFAULT_ASPECT_RATIO
    );
  } catch (error) {
    console.error('Error parsing example URL:', error);
    return DEFAULT_ASPECT_RATIO;
  }
};

export default function FormPropTypeVideo({
  id,
  example,
  required,
  allowMultiple = false,
  valueMode = 'unlimited',
  limitedCount = 1,
}: Pick<CodeComponentProp, 'id'> & {
  example: CodeComponentPropVideoExample | CodeComponentPropVideoExample[];
  required: boolean;
  allowMultiple?: boolean;
  valueMode?: ValueMode;
  limitedCount?: number;
}) {
  // Handle both single video and array (when allowMultiple is enabled)
  // If it's an array, use the first element for display
  // Handle edge case where example might be an empty string (when prop type is first created/changed)
  const videoExample = Array.isArray(example)
    ? example[0] || { src: '', poster: '', width: 0, height: 0 }
    : !example
      ? { src: '', poster: '', width: 0, height: 0 }
      : example;

  const exampleAspectRatio = parseExampleSrc(videoExample.poster);
  const dispatch = useAppDispatch();
  const [aspectRatio, setAspectRatio] = useState(exampleAspectRatio);
  const [localRequired, setLocalRequired] = useState(required);

  // Use a ref to track the previous values of the UI-controlled input (aspectRatio)
  // and the multi-value settings (allowMultiple, valueMode, limitedCount).
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
    if (videoExample.poster) {
      const parsedAspectRatio = parseExampleSrc(videoExample.poster);
      setAspectRatio(parsedAspectRatio);
      // Also update the ref so the main effect doesn't trigger on mount
      prevValuesRef.current = {
        ...prevValuesRef.current,
        aspectRatio: parsedAspectRatio,
      };
    }
    // If example is empty string, it could mean:
    // 1. Initial mount - let the main useEffect initialize with defaults
    // 2. User explicitly selected None - aspectRatio would already be NONE_VALUE
    // We don't need to do anything here; the main useEffect will handle initialization
  }, [videoExample.poster, example]);

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
      prevValuesRef.current.allowMultiple !== allowMultiple ||
      prevValuesRef.current.valueMode !== valueMode ||
      prevValuesRef.current.limitedCount !== limitedCount;

    // Check if we need to initialize with a default value on first mount
    const isEmptyExample = Array.isArray(example) && example.length === 0;
    const isFirstRender = prevValuesRef.current.aspectRatio === null;

    // Only run if values actually changed OR if this is first render with empty example
    if (!hasChanged && !(isFirstRender && isEmptyExample)) {
      return;
    }

    // Detect if aspect ratio changed (not just mode switches)
    const aspectRatioChanged =
      prevValuesRef.current.aspectRatio !== aspectRatio;

    // Update ref with new values
    prevValuesRef.current = {
      aspectRatio,
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
    const aspectRatioData =
      EXAMPLE_ASPECT_RATIO_VALUES.find(
        (ratio) => ratio.value === aspectRatio,
      ) || EXAMPLE_ASPECT_RATIO_VALUES[0];

    const newVideoExample: CodeComponentPropVideoExample = {
      // ⚠️ @todo This uses the SAME URL for both the live preview and to send to the server at `canvas/api/v0/config/auto-save/js_component/…`.
      // This needs to send different values for either:
      // - one of CONFIG_EXAMPLE_URLS to the server
      // - one of VIDEO_SERVICE_URLS for the live preview
      src: aspectRatioData.exampleSrc.substring(moduleBaseUrl.length),
      poster: `${POSTER_SERVICE_URL}${aspectRatioData.width}x${aspectRatioData.height}.png?text=${aspectRatioData.label}`,
    };

    // For multi-value mode, create an array
    if (allowMultiple) {
      const currentArray = Array.isArray(example) ? example : [];

      // For limited mode, ensure we have exactly limitedCount items
      if (valueMode === 'limited') {
        // Update all items to use the new newVideoExample (aspect ratio changes should apply to all)
        const newArray = Array.from(
          { length: limitedCount },
          () => newVideoExample,
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
        // - If aspect ratio changed, update all existing items
        // - Otherwise, keep existing array or create one with single item
        let newArray;
        if (aspectRatioChanged && currentArray.length > 0) {
          // Update all existing items with new aspect ratio
          newArray = currentArray.map(() => newVideoExample);
        } else {
          // Keep existing items or create initial item
          newArray = currentArray.length > 0 ? currentArray : [newVideoExample];
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
            example: newVideoExample,
          },
        }),
      );
    }
  }, [
    aspectRatio,
    dispatch,
    id,
    example,
    allowMultiple,
    valueMode,
    limitedCount,
  ]);

  return (
    <Flex direction="column" gap="4" flexGrow="1">
      <Divider />
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
                {value.value} ({value.label})
              </Select.Item>
            ))}
          </Select.Content>
        </Select.Root>
      </FormElement>
    </Flex>
  );
}
