import { v4 as uuidv4 } from 'uuid';
import { camelCase } from 'lodash';

import type {
  CodeComponentProp,
  CodeComponentPropSerialized,
  CodeComponentSlot,
  CodeComponentSlotSerialized,
} from '@/types/CodeComponent';

export function getPropMachineName(name: string) {
  return camelCase(name);
}

export function parsePropValue(prop: CodeComponentProp) {
  switch (prop.type) {
    case 'integer':
      return Number(prop.example);
    case 'number':
      return Number(prop.example);
    case 'boolean':
      return String(prop.example) === 'true';
    default:
      return prop.example;
  }
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
      const { name, type, example, enum: enumValues, _ref } = prop;
      const isNumberType = ['integer', 'number'].includes(type);
      const processed: CodeComponentPropSerialized = {
        title: name,
        type,
        ...(_ref && { $ref: _ref }),
        ...(example && {
          examples: [isNumberType ? Number(example) : example],
        }),
        ...(enumValues && {
          enum: isNumberType ? enumValues.map(Number) : enumValues,
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
    const { title, type, examples, enum: enumValues, $ref } = prop;
    return {
      // The ID is only used to keep track of the prop in the UI when editing,
      // reordering, etc.
      id: uuidv4(),
      name: title,
      type,
      example: examples?.length ? String(examples[0]) : '',
      ...(enumValues && { enum: enumValues.map(String) }),
      ...($ref && { _ref: $ref }),
    };
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
