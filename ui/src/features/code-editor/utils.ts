import { v4 as uuidv4 } from 'uuid';
import { camelCase } from 'lodash';
import getOverrideExampleData from '@/features/code-editor/component-data/getOverrideExampleData';
import derivedPropTypes from '@/features/code-editor/component-data/derivedPropTypes';

import type {
  CodeComponentProp,
  CodeComponentPropPreviewValue,
  CodeComponentPropSerialized,
  CodeComponentSlot,
  CodeComponentSlotSerialized,
  CodeComponentPropImageExample,
  CodeComponent,
  CodeComponentSerialized,
} from '@/types/CodeComponent';
import type { File } from '@babel/types';

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
  let propValues = {} as Record<string, CodeComponentPropPreviewValue>;
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
 * Returns prop values for the code editor preview of an override.
 *
 * @see ui/src/features/code-editor/Preview.tsx
 *
 * @param block - The overridden block to get the prop values for.
 * @returns The prop values.
 */
export function getExamplePropValuesForOverridePreview(block: string) {
  return getOverrideExampleData(block, 'props') || {};
}

/**
 * Returns slot names for the code editor preview of an override.
 *
 * @see ui/src/features/code-editor/Preview.tsx
 *
 * @param block - The overridden block to get the slot names for.
 * @returns The slot names.
 */
export function getExampleSlotNamesForOverridePreview(block: string) {
  const data = getOverrideExampleData(block, 'slots');
  return data ? Object.keys(data) : [];
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

/**
 * Returns JS for the code editor preview for example slots of an override.
 *
 * @see ui/src/features/code-editor/Preview.tsx
 *
 * @param block - The overridden block to get the JS for.
 * @returns The JS for the example slot.
 */
export function getJsForExampleSlotsOverridePreview(block: string) {
  const data = getOverrideExampleData(block, 'slots');
  if (!data) {
    return '';
  }
  return Object.keys(data)
    .map((slot) => {
      return `export function ${slot}() { return (${data[slot] as string});}`;
    })
    .join('\n');
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
  return props.reduce(
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
      } = prop;
      const isNumberType = ['integer', 'number'].includes(type);
      const processed: CodeComponentPropSerialized = {
        title: name,
        type,
        ...(example && {
          examples: [isNumberType ? Number(example) : example],
        }),
        ...(enumValues && {
          enum: isNumberType ? enumValues.map(Number) : enumValues,
        }),
        ...($ref && { $ref }),
        ...(format && { format }),
        ...(contentMediaType && { contentMediaType }),
        ...(xFormattingContext && {
          'x-formatting-context': xFormattingContext,
        }),
      };
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
  return Object.entries(props).map(([key, prop]) => {
    const {
      title,
      type,
      examples,
      enum: enumValues,
      $ref,
      format,
      contentMediaType,
      'x-formatting-context': xFormattingContext,
    } = prop;
    let example: CodeComponentProp['example'] = '';
    if (examples?.length) {
      example =
        type === 'object'
          ? (examples[0] as unknown as CodeComponentPropImageExample)
          : String(examples[0]);
    }

    const derivedType =
      derivedPropTypes.find((type) => type.derive(prop))?.type ?? null;

    const deserializedProp = {
      id: uuidv4(),
      name: title,
      type,
      example,
      ...(enumValues && { enum: enumValues.map(String) }),
      ...($ref && { $ref }),
      ...(format && { format }),
      ...(contentMediaType && { contentMediaType }),
      ...(xFormattingContext && { 'x-formatting-context': xFormattingContext }),
      derivedType,
    };

    // Backwards compatibility
    // @see https://www.drupal.org/i/3520843
    if (derivedType === 'formattedText' && prop.$ref?.includes('textarea')) {
      deserializedProp.contentMediaType = 'text/html';
      deserializedProp['x-formatting-context'] = 'block';
      delete deserializedProp.$ref;
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
  return slots.reduce(
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
  return Object.entries(slots).map(([key, slot]) => ({
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
  };
}

/**
 * Extracts import statements from an AST.
 *
 * @param ast - ast object.
 * @param scope - An optional string to filter imports by a specific scope. If provided, only imports starting with this scope are included.
 */
export const getImportsFromAst = (ast: File, scope?: string) =>
  ast.program.body
    .filter((d) => d.type === 'ImportDeclaration')
    .reduce<string[]>((carry, d) => {
      const source = d.source.value;
      if (scope && !source.startsWith(scope)) {
        return carry;
      }
      return [...carry, scope ? source.slice(scope.length) : source];
    }, []);

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
