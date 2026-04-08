import { camelCase, isEqual } from 'lodash';
import { v4 as uuidv4 } from 'uuid';

import derivedPropTypes from '@/features/code-editor/component-data/derivedPropTypes';
import { CONFIG_EXAMPLE_URLS } from '@/features/code-editor/component-data/forms/FormPropTypeVideo';
import { getCanvasModuleBaseUrl } from '@/utils/drupal-globals';

import type {
  CodeComponent,
  CodeComponentProp,
  CodeComponentPropImageExample,
  CodeComponentPropPreviewValue,
  CodeComponentPropSerialized,
  CodeComponentPropVideoExample,
  CodeComponentSerialized,
  CodeComponentSlot,
  CodeComponentSlotSerialized,
} from '@/types/CodeComponent';

export function getPropMachineName(name: string) {
  return camelCase(name);
}

/**
 * Parses a prop value for the code editor preview.
 *
 * @see ui/src/features/code-editor/Preview.tsx
 *
 * @param prop - The prop to parse.
 * @returns The parsed prop value.
 */
export function parsePropValueForPreview(
  prop: CodeComponentProp,
): CodeComponentPropPreviewValue {
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
 * @returns The prop values.
 */
export function getPropValuesForPreview(
  props: CodeComponentProp[],
): Record<string, CodeComponentPropPreviewValue> {
  const propValues = {} as Record<string, CodeComponentPropPreviewValue>;
  props
    .filter((prop) => prop.name)
    .forEach((prop) => {
      propValues[getPropMachineName(prop.name)] =
        parsePropValueForPreview(prop);
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
      'x-formatting-context': xFormattingContext,
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
  return {
    ...codeComponent,
    props: deserializeProps(codeComponent.props),
    slots: deserializeSlots(codeComponent.slots),
    dataFetches: {},
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
