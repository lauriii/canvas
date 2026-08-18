import { camelCase, isEqual } from 'lodash';
import { v4 as uuidv4 } from 'uuid';

import derivedPropTypes from '@/features/code-editor/component-data/derivedPropTypes';
import { CONFIG_EXAMPLE_URLS } from '@/features/code-editor/component-data/forms/FormPropTypeVideo';
import { getCanvasModuleBaseUrl } from '@/utils/drupal-globals';

import type {
  BrandKitColor,
  BrandKitColorValue,
  CodeComponent,
  CodeComponentProp,
  CodeComponentPropImageExample,
  CodeComponentPropPreviewValue,
  CodeComponentPropSerialized,
  CodeComponentPropVideoExample,
  CodeComponentSerialized,
  CodeComponentSlot,
  CodeComponentSlotSerialized,
  DataDependencies,
  ResolvedColorProp,
} from '@/types/CodeComponent';

export function getPropMachineName(name: string) {
  return camelCase(name);
}

/**
 * Builds the `dataDependencies` for a serialized payload: the AST-derived
 * dependencies (drupalSettings, urls) plus the prop-derived `entityFields`.
 */
export function serializeDataDependencies(
  props: CodeComponentProp[],
  dataDependencies: DataDependencies,
): DataDependencies {
  const entityFields: Record<string, string[]> = {};
  for (const prop of props) {
    if (
      prop.name &&
      prop.derivedType === 'contentEntityReference' &&
      prop.entityFieldExpressions?.length
    ) {
      entityFields[getPropMachineName(prop.name)] = prop.entityFieldExpressions;
    }
  }
  const serialized: DataDependencies = { ...dataDependencies };
  delete serialized.entityFields;
  if (Object.keys(entityFields).length > 0) {
    serialized.entityFields = entityFields;
  }
  return serialized;
}

/**
 * Computes a CSS color value string from a W3C Design Token value.
 *
 * Output is always rgb()/rgba() for sRGB or hsl()/hsla() for HSL — never hex.
 *
 * @see src/Utility/ColorResolver.php computeCssColorValue()
 *   PHP counterpart — keep both in sync.
 * @see tests/src/Kernel/Utility/ColorResolverTest.php
 *   Canonical test vectors for both implementations.
 *
 * @param value - The color value struct.
 * @returns The CSS color value string.
 */
export function computeCssColorValue(value: BrandKitColorValue): string {
  const { colorSpace, components, alpha } = value;

  switch (colorSpace) {
    case 'srgb': {
      // Components are [R, G, B] each [0-1].
      const r = Math.round(components[0] * 255);
      const g = Math.round(components[1] * 255);
      const b = Math.round(components[2] * 255);

      // Loose equality intentionally matches both null and undefined.
      if (alpha == null || alpha === 1.0) {
        return `rgb(${r}, ${g}, ${b})`;
      }

      return `rgba(${r}, ${g}, ${b}, ${alpha.toFixed(2)})`;
    }

    case 'hsl': {
      // Components are [H, S, L] where H is [0-360], S and L are [0-100].
      const h = Math.round(components[0]);
      const s = Math.round(components[1]);
      const l = Math.round(components[2]);

      // Loose equality intentionally matches both null and undefined.
      if (alpha == null || alpha === 1.0) {
        return `hsl(${h}, ${s}%, ${l}%)`;
      }
      return `hsla(${h}, ${s}%, ${l}%, ${alpha.toFixed(2)})`;
    }

    default:
      return 'rgb(0, 0, 0)';
  }
}

/**
 * Parses a free-pick hex string into a W3C Design Token value struct.
 *
 * @param hex - The stored hex color string (#rrggbb or #rrggbbaa).
 * @returns The W3C Design Token value struct.
 */
function parseFreePickHexToValue(hex: string): BrandKitColorValue {
  const clean = hex.slice(1);

  // Parse RGB bytes.
  const r = parseInt(clean.substring(0, 2), 16);
  const g = parseInt(clean.substring(2, 4), 16);
  const b = parseInt(clean.substring(4, 6), 16);

  // Parse alpha if present.
  const alpha =
    clean.length === 8 ? parseInt(clean.substring(6, 8), 16) / 255 : null;

  return {
    colorSpace: 'srgb',
    components: [r / 255, g / 255, b / 255],
    alpha,
    hex: `#${clean.substring(0, 6)}`,
  };
}

/**
 * Parses a free-pick HSL string into a W3C Design Token value struct.
 *
 * @param hsl - The stored HSL color string (hsl(h, s%, l%) or hsla(h, s%, l%, a)).
 * @returns The W3C Design Token value struct.
 */
function parseFreePickHslToValue(hsl: string): BrandKitColorValue | null {
  // Match hsl(h, s%, l%) or hsla(h, s%, l%, a)
  const hslMatch = hsl.match(/^hsl\(\s*(\d+)\s*,\s*(\d+)%\s*,\s*(\d+)%\s*\)$/i);
  const hslaMatch = hsl.match(
    /^hsla\(\s*(\d+)\s*,\s*(\d+)%\s*,\s*(\d+)%\s*,\s*([\d.]+)\s*\)$/i,
  );

  if (hslaMatch) {
    const h = parseInt(hslaMatch[1], 10);
    const s = parseInt(hslaMatch[2], 10);
    const l = parseInt(hslaMatch[3], 10);
    const alpha = parseFloat(hslaMatch[4]);

    return {
      colorSpace: 'hsl',
      components: [h, s, l],
      alpha,
      hex: null,
    };
  }

  if (hslMatch) {
    const h = parseInt(hslMatch[1], 10);
    const s = parseInt(hslMatch[2], 10);
    const l = parseInt(hslMatch[3], 10);

    return {
      colorSpace: 'hsl',
      components: [h, s, l],
      alpha: null,
      hex: null,
    };
  }

  return null;
}

/**
 * Parses a free-pick color string (hex or HSL) into a W3C Design Token value struct.
 *
 * @param value - The stored color string (#rrggbbaa, #rrggbb, hsl(...), or hsla(...)).
 * @returns The W3C Design Token value struct, or null if parsing fails.
 */
function parseFreePickToValue(value: string): BrandKitColorValue | null {
  if (value.startsWith('#')) {
    return parseFreePickHexToValue(value);
  }

  if (value.startsWith('hsl(') || value.startsWith('hsla(')) {
    return parseFreePickHslToValue(value);
  }

  return null;
}

/**
 * Resolves a stored color value to a ResolvedColorProp for preview.
 *
 * Mirrors PHP JsonSchemaPropsComponentSourceBase::resolveColorPropValue().
 *
 * @param storedValue - The stored color value (e.g. '#ff0000ff' or 'canvas-color:<uuid>').
 * @param brandKitColors - The Brand Kit colors for resolving canvas-color references.
 * @returns The resolved color object, or null if value is empty/invalid.
 */
function resolveColorPropValueForPreview(
  storedValue: string,
  brandKitColors: BrandKitColor[] | null,
): ResolvedColorProp | null {
  if (!storedValue) {
    return null;
  }

  // Brand Kit reference: canvas-color:<uuid>
  if (storedValue.startsWith('canvas-color:')) {
    const uuid = storedValue.slice('canvas-color:'.length);
    const color = brandKitColors?.find((c) => c.id === uuid);
    if (!color) {
      return null;
    }
    const cssColorValue = computeCssColorValue(color.value);

    return {
      value: color.value,
      cssColorValue,
      cssVariable: color.cssVariable,
      colorName: color.name,
    };
  }

  // Free pick (8-digit hex #rrggbbaa or 6-digit #rrggbb, or HSL/HSLA)
  const freePickValue = parseFreePickToValue(storedValue);
  if (freePickValue) {
    const cssColorValue = computeCssColorValue(freePickValue);

    return {
      value: freePickValue,
      cssColorValue,
      cssVariable: null,
      colorName: null,
    };
  }

  return null;
}

/**
 * Parses a prop value for the code editor preview.
 *
 * @see ui/src/features/code-editor/Preview.tsx
 *
 * @param prop - The prop to parse.
 * @param brandKitColors - The Brand Kit colors for resolving color props.
 * @returns The parsed prop value.
 */
export function parsePropValueForPreview(
  prop: CodeComponentProp,
  brandKitColors: BrandKitColor[] | null,
): CodeComponentPropPreviewValue {
  // Color props need special resolution to match production render output
  if (prop.derivedType === 'color') {
    return resolveColorPropValueForPreview(
      (prop.example as string) ?? '',
      brandKitColors,
    );
  }

  switch (prop.type) {
    case 'integer':
      return Number(prop.example);
    case 'number':
      return Number(prop.example);
    case 'boolean':
      return String(prop.example) === 'true';
    case 'array':
      // For multi-value props, return the array as-is
      // It should already be an array of the correct type (string[] | number[])
      return Array.isArray(prop.example) ? prop.example : [];
    default:
      return prop.example as string;
  }
}

/**
 * Returns prop values for the code editor preview.
 *
 * @see ui/src/features/code-editor/Preview.tsx
 *
 * @param props - The props to get the values for.
 * @param brandKitColors - The Brand Kit colors for resolving color props.
 * @returns The prop values.
 */
export function getPropValuesForPreview(
  props: CodeComponentProp[],
  brandKitColors: BrandKitColor[] | null,
): Record<string, CodeComponentPropPreviewValue> {
  const propValues = {} as Record<string, CodeComponentPropPreviewValue>;
  props
    .filter((prop) => prop.name)
    .forEach((prop) => {
      propValues[getPropMachineName(prop.name)] = parsePropValueForPreview(
        prop,
        brandKitColors,
      );
    });
  return propValues;
}

/**
 * Returns slot names for the code editor preview.
 *
 * @see ui/src/features/code-editor/Preview.tsx
 *
 * @param slots - The slots to get the names for.
 * @returns The slot names.
 */
export function getSlotNamesForPreview(slots: CodeComponentSlot[]): string[] {
  return slots
    .filter((slot) => slot.name && slot.example)
    .map((slot) => getPropMachineName(slot.name));
}

/**
 * Returns JS for the code editor preview for slots.
 *
 * @see ui/src/features/code-editor/Preview.tsx
 *
 * @param slots - The slots to get the JS for.
 * @returns The JS for the slots.
 */
export function getJsForSlotsPreview(slots: CodeComponentSlot[]) {
  return slots
    .filter((slot) => slot.name && slot.example)
    .map((slot) => {
      // Wrap the slot's example value in a function so that it can be
      // rendered by Preact.
      return `export function ${getPropMachineName(slot.name)}() { return (${slot.example as string});}`;
    })
    .join('\n');
}

function serializeExample(
  example: CodeComponentProp['example'],
  flags: {
    isNumberType: boolean;
    isStringArrayProp: boolean | undefined;
    isVideo: boolean;
    isImage: boolean;
  },
) {
  const { isNumberType, isStringArrayProp, isVideo, isImage } = flags;

  // Multi-value props (allowMultiple)
  if (Array.isArray(example)) {
    if (isNumberType) {
      return example.filter((v) => v !== '').map((v) => Number(v));
    }
    if (isStringArrayProp) {
      return (example as string[]).filter((v) => v !== '');
    }
    if (isVideo) {
      return (example as CodeComponentPropVideoExample[])
        .filter((v) => v && typeof v === 'object' && v.src && v.src !== '')
        .map(serializeVideoSrc);
    }
    if (isImage) {
      return (example as CodeComponentPropImageExample[]).filter(
        (v) => v && typeof v === 'object' && v.src && v.src !== '',
      );
    }
    return example;
  }

  // Single-value props
  if (isNumberType) {
    return Number(example);
  }
  if (isVideo && typeof example === 'object') {
    return serializeVideoSrc(example as CodeComponentPropVideoExample);
  }
  return example!;
}

/**
 * Serializes props for saving in the JS Component config entity.
 *
 * @see ui/tests/fixtures/code-component-props.json
 * @see ui/tests/unit/code-editor-utils.cy.jsx
 *
 * @param props - The props to serialize.
 * @returns The serialized props.
 */
export function serializeProps(props: CodeComponentProp[]) {
  // Filter out props without a name since they are not valid yet.
  return props
    .filter((prop) => prop.name)
    .reduce(
      (acc, prop) => {
        const {
          name,
          type,
          example,
          enum: enumValues,
          $ref,
          format,
          contentMediaType,
          'x-formatting-context': xFormattingContext,
          'x-canvas-color-picker': xCanvasColorPicker,
          'x-canvas-color-folders': xCanvasColorFolders,
          derivedType,
          allowMultiple,
          items,
          valueMode,
          limitedCount,
        } = prop;
        // Check if the base type (or items type for arrays) is numeric
        const baseType = allowMultiple && items ? items.type : type;
        const isNumberType = ['integer', 'number'].includes(baseType);
        const isVideo = derivedType === 'video';
        const isImage = derivedType === 'image';

        // Determine the actual type for serialization
        const serializedType = allowMultiple && items ? 'array' : type;

        // For string-based array props (e.g. date, link), empty strings should
        // be treated as "no value" and excluded from the serialized output.
        // Number arrays already filter empty strings inline below.
        const isStringArrayProp =
          allowMultiple && items?.type === 'string' && !isNumberType;

        // Whether this prop has a non-empty example worth serializing.
        // For arrays: check length > 0 (string arrays also require non-empty strings)
        // For non-arrays: check truthy value or explicit false (for booleans)
        const hasExample = Array.isArray(example)
          ? isStringArrayProp
            ? (example as string[]).some((v) => v !== '')
            : example.length > 0
          : example || example === false;

        const processed: CodeComponentPropSerialized = {
          title: name,
          type: serializedType,
          ...(hasExample && {
            examples: [
              serializeExample(example, {
                isNumberType,
                isStringArrayProp,
                isVideo,
                isImage,
              }),
            ],
          }),
          // Only add enum/meta:enum at root level if NOT an array
          ...(!allowMultiple &&
            enumValues && {
              enum: enumValues
                .filter(({ value }) => value !== '')
                .map(({ value }) => (isNumberType ? Number(value) : value)),
              'meta:enum': Object.fromEntries(
                enumValues
                  .filter(({ value }) => value !== '')
                  .map(({ value, label }) => [value, label]),
              ),
            }),
        };
        // When allowMultiple is true, metadata goes INSIDE items
        if (allowMultiple && items) {
          processed.items = {
            type: items.type,
            ...($ref && { $ref }),
            ...(format && { format }),
            ...(contentMediaType && { contentMediaType }),
            ...(xFormattingContext && {
              'x-formatting-context': xFormattingContext,
            }),
            // Add enum/meta:enum inside items for array types
            ...(enumValues && {
              enum: enumValues
                .filter(({ value }) => value !== '')
                .map(({ value }) => (isNumberType ? Number(value) : value)),
              'meta:enum': Object.fromEntries(
                enumValues
                  .filter(({ value }) => value !== '')
                  .map(({ value, label }) => [value, label]),
              ),
            }),
          };
          // Add maxItems when valueMode is 'limited'
          if (valueMode === 'limited' && limitedCount) {
            processed.maxItems = limitedCount;
          }
        } else {
          // When not an array, metadata goes at top level
          if ($ref) processed.$ref = $ref;
          if (format) processed.format = format;
          if (contentMediaType) processed.contentMediaType = contentMediaType;
          if (xFormattingContext)
            processed['x-formatting-context'] = xFormattingContext;
          if (xCanvasColorPicker)
            processed['x-canvas-color-picker'] = xCanvasColorPicker;
          if (xCanvasColorFolders && xCanvasColorFolders.length > 0)
            processed['x-canvas-color-folders'] = xCanvasColorFolders;
        }
        return { ...acc, [getPropMachineName(name)]: processed };
      },
      {} as Record<string, CodeComponentPropSerialized>,
    );
}

/**
 * Deserializes props from the JS Component config entity.
 *
 * @see ui/tests/fixtures/code-component-props.json
 * @see ui/tests/unit/code-editor-utils.cy.jsx
 *
 * @param props - The props to deserialize.
 * @returns The deserialized props.
 */
export function deserializeProps(
  props: Record<string, CodeComponentPropSerialized>,
): CodeComponentProp[] {
  if (!props) {
    return [];
  }
  return Object.entries(props).map(([, prop]) => {
    const {
      title,
      type,
      examples,
      enum: enumValues,
      'meta:enum': metaEnum,
      $ref,
      format,
      contentMediaType,
      'x-canvas-color-picker': xCanvasColorPicker,
      'x-canvas-color-folders': xCanvasColorFolders,
      'x-formatting-context': xFormattingContext,
      'x-allowed-entity-type-id': xAllowedEntityTypeId,
      'x-allowed-bundle': xAllowedBundle,
      items,
      maxItems,
    } = prop;

    // Detect if this is an array type (allowMultiple)
    const allowMultiple = type === 'array' && items;
    const actualType = allowMultiple ? items.type : type;

    // When it's an array, enum is inside items; otherwise at top level
    const actualEnumValues = allowMultiple ? items?.enum : enumValues;
    const actualMetaEnum = allowMultiple ? items?.['meta:enum'] : metaEnum;

    // When it's an array, metadata is inside items; otherwise at top level
    const actualRef = allowMultiple ? items?.$ref : $ref;
    const actualFormat = allowMultiple ? items?.format : format;
    const actualContentMediaType = allowMultiple
      ? items?.contentMediaType
      : contentMediaType;
    const actualXFormattingContext = allowMultiple
      ? items?.['x-formatting-context']
      : xFormattingContext;
    const actualXAllowedEntityTypeId = allowMultiple
      ? items?.['x-allowed-entity-type-id']
      : xAllowedEntityTypeId;
    const actualXAllowedBundle = allowMultiple
      ? items?.['x-allowed-bundle']
      : xAllowedBundle;

    const isNumberType = ['integer', 'number'].includes(actualType);
    let example: CodeComponentProp['example'] = allowMultiple ? [] : '';

    // Create a normalized prop for type derivation
    // For array types, we need to check items.type instead of top-level type
    const propForDerivation =
      allowMultiple && items
        ? {
            ...prop,
            type: items.type,
            $ref: items.$ref,
            format: items.format,
            contentMediaType: items.contentMediaType,
            'x-formatting-context': items['x-formatting-context'],
            'x-allowed-entity-type-id': items['x-allowed-entity-type-id'],
            'x-allowed-bundle': items['x-allowed-bundle'],
            enum: items.enum,
            'meta:enum': items['meta:enum'],
          }
        : prop;

    const derivedType =
      derivedPropTypes.find((type) => type.derive(propForDerivation))?.type ??
      null;
    const isVideo = derivedType == 'video';

    if (examples?.length) {
      if (actualType === 'object') {
        example = examples[0] as unknown as
          | CodeComponentPropImageExample
          | CodeComponentPropVideoExample;
      } else if (actualType === 'boolean') {
        example = examples[0] as unknown as boolean;
      } else if (allowMultiple && Array.isArray(examples[0])) {
        example = examples[0] as string[] | number[];
      } else if (!allowMultiple) {
        example = String(examples[0]);
      }
    }

    // This should use meta:enum to build the list of values/labels if available but fallback to use the enum array if meta:enum is not there.
    const deserializedProp = {
      id: uuidv4(),
      name: title,
      type: actualType,
      example:
        isVideo && Array.isArray(example)
          ? (example as CodeComponentPropVideoExample[]).map(
              deserializeVideoSrc,
            )
          : isVideo && typeof example === 'object'
            ? deserializeVideoSrc(example as CodeComponentPropVideoExample)
            : example,
      ...(actualEnumValues && {
        enum: actualEnumValues.map((value) => ({
          value: isNumberType ? Number(value) : value,
          label: String(value),
        })),
      }),
      ...(actualMetaEnum && {
        enum: Object.entries(actualMetaEnum).map(([value, label]) => ({
          value: isNumberType ? Number(value) : value,
          label,
        })),
      }),
      ...(actualRef && { $ref: actualRef }),
      ...(actualFormat && { format: actualFormat }),
      ...(actualContentMediaType && {
        contentMediaType: actualContentMediaType,
      }),
      ...(actualXFormattingContext && {
        'x-formatting-context': actualXFormattingContext,
      }),
      ...(actualXAllowedEntityTypeId && {
        'x-allowed-entity-type-id': actualXAllowedEntityTypeId,
      }),
      ...(actualXAllowedBundle && {
        'x-allowed-bundle': actualXAllowedBundle,
      }),
      ...(xCanvasColorPicker && {
        'x-canvas-color-picker': xCanvasColorPicker,
      }),
      ...(xCanvasColorFolders &&
        xCanvasColorFolders.length > 0 && {
          'x-canvas-color-folders': xCanvasColorFolders,
        }),
      derivedType,
      ...(allowMultiple && { allowMultiple: true, items }),
      ...(allowMultiple &&
        maxItems && {
          valueMode: 'limited' as const,
          limitedCount: maxItems,
        }),
      ...(allowMultiple &&
        !maxItems && {
          valueMode: 'unlimited' as const,
          limitedCount: 1,
        }),
    };

    // Backwards compatibility
    // @see https://www.drupal.org/i/3520843
    if (derivedType === 'formattedText' && prop.$ref?.includes('textarea')) {
      deserializedProp.contentMediaType = 'text/html';
      deserializedProp['x-formatting-context'] = 'block';
      delete deserializedProp.$ref;
    }

    // Backwards compatibility: remove stale contentMediaType /
    // x-formatting-context fields that were incorrectly carried over when
    // switching a prop away from 'formattedText' to a type with other
    // distinguishing fields (a format or enum values), before the type-switch
    // logic was fixed to clear those fields. Without this fix, those props
    // would incorrectly re-derive as 'formattedText' on page load.
    // @see https://www.drupal.org/i/3583386
    if (
      derivedType === 'formattedText' &&
      (actualFormat || (actualEnumValues && actualEnumValues.length > 0))
    ) {
      delete deserializedProp.contentMediaType;
      delete deserializedProp['x-formatting-context'];
      // Remove stale fields from items for multi-value (allowMultiple) props.
      if (allowMultiple && deserializedProp.items) {
        delete deserializedProp.items.contentMediaType;
        delete deserializedProp.items['x-formatting-context'];
      }
      // Re-derive the correct type now that the stale fields are removed.
      deserializedProp.derivedType =
        derivedPropTypes.find((type) =>
          type.derive({
            ...propForDerivation,
            contentMediaType: undefined,
            'x-formatting-context': undefined,
          }),
        )?.type ?? null;
    }

    return deserializedProp;
  });
}

/**
 * Serializes slots for saving in the JS Component config entity.
 *
 * @see ui/tests/fixtures/code-component-slots.json
 * @see ui/tests/unit/code-editor-utils.cy.jsx
 */
export function serializeSlots(slots: CodeComponentSlot[]) {
  // Filter out slots without a name since they are not valid yet.
  return slots
    .filter((slot) => slot.name)
    .reduce(
      (acc, slot) => {
        const { name, example } = slot;
        return {
          ...acc,
          [getPropMachineName(name)]: {
            title: name,
            ...(example && { examples: [example] }),
          },
        };
      },
      {} as Record<string, CodeComponentSlotSerialized>,
    );
}

/**
 * Deserializes slots from the JS Component config entity.
 *
 * @see ui/tests/fixtures/code-component-slots.json
 * @see ui/tests/unit/code-editor-utils.cy.jsx
 */
export function deserializeSlots(
  slots: Record<string, CodeComponentSlotSerialized>,
): CodeComponentSlot[] {
  if (!slots) {
    return [];
  }
  return Object.entries(slots).map(([, slot]) => ({
    id: uuidv4(),
    name: slot.title,
    example: slot.examples?.length ? slot.examples[0] : '',
  }));
}

/**
 * Deserializes a code component.
 */
export function deserializeCodeComponent(
  codeComponent: CodeComponentSerialized,
): CodeComponent {
  const props = deserializeProps(codeComponent.props);
  // Lift the persisted `entityFields` onto the props editing state.
  // @see serializeDataDependencies
  const { entityFields, ...dataDependencies } =
    codeComponent.dataDependencies ?? {};
  if (entityFields) {
    for (const prop of props) {
      const expressions = prop.name
        ? entityFields[getPropMachineName(prop.name)]
        : undefined;
      if (expressions?.length) {
        prop.entityFieldExpressions = expressions;
      }
    }
  }
  return {
    ...codeComponent,
    type: codeComponent.type ?? 'react',
    props,
    slots: deserializeSlots(codeComponent.slots),
    sourceCodeJs: codeComponent.sourceCodeJs ?? '',
    sourceCodeCss: codeComponent.sourceCodeCss ?? '',
    compiledJs: codeComponent.compiledJs ?? '',
    compiledCss: codeComponent.compiledCss ?? '',
    importedJsComponents: codeComponent.importedJsComponents ?? [],
    dataFetches: {},
    dataDependencies,
  };
}

/**
 * Formats a string into a valid JS identifier for imports.
 * ex. import ${formatted} from '@/components/source'
 */
export function formatToValidImportName(name: string): string {
  if (!name) return '';
  // Remove special characters and spaces, keeping alphanumeric and underscore
  let formatted = name.replace(/[^\w\s]/g, '');
  // Convert to PascalCase (capitalize first letter of each word)
  formatted = formatted
    .split(/[\s_-]+/)
    .map((word) => word.charAt(0).toUpperCase() + word.slice(1))
    .join('');
  // Ensure it doesn't start with a number
  if (/^\d/.test(formatted)) {
    formatted = 'Component' + formatted;
  }
  return formatted;
}

/**
 * Detects if there is a valid change in props or slots.
 * Adding an item with an empty name is not considered a valid change.
 */
export function detectValidPropOrSlotChange(
  current: CodeComponentProp[] | CodeComponentSlot[],
  last: CodeComponentProp[] | CodeComponentSlot[],
): boolean {
  // If arrays are identical, no change
  if (isEqual(current, last)) {
    return false;
  }

  // Create a version of current without empty name items
  const currentWithoutEmpty = current.filter((item) => item.name !== '');
  const lastWithoutEmpty = last.filter((item) => item.name !== '');

  // Check if the only difference is empty-named items
  // by comparing the filtered current with last
  if (isEqual(currentWithoutEmpty, lastWithoutEmpty)) {
    return false;
  }

  // There are other changes besides empty-named items
  return true;
}

function serializeVideoSrc(example: CodeComponentPropVideoExample) {
  const allowedExamplesForServer = Object.values(CONFIG_EXAMPLE_URLS);
  for (const allowedPath of allowedExamplesForServer) {
    if (example.src.endsWith(allowedPath as string)) {
      return { ...example, src: allowedPath as string };
    }
  }
  // If no match, return the original.
  return example;
}

function deserializeVideoSrc(example: CodeComponentPropVideoExample) {
  const moduleBaseUrl = getCanvasModuleBaseUrl();
  const configExampleUrls = Object.values(CONFIG_EXAMPLE_URLS);
  for (const configUrl of configExampleUrls) {
    if (example.src.includes(configUrl)) {
      const pathForPreview = `${moduleBaseUrl}${configUrl}`;
      return { ...example, src: pathForPreview as string };
    }
  }
  return example;
}
