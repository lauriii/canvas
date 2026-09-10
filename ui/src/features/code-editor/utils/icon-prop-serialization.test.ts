import { describe, expect, it } from 'vitest';

import {
  deserializeProps,
  serializeProps,
} from '@/features/code-editor/utils/utils';

import type { CodeComponentProp } from '@/types/CodeComponent';

const ICON_REF = 'json-schema-definitions://canvas.module/icon';

describe('icon prop serialization', () => {
  it('serializes an unscoped icon prop', () => {
    const props: CodeComponentProp[] = [
      {
        id: 'test-uuid',
        name: 'My icon',
        type: 'string',
        derivedType: 'icon',
        $ref: ICON_REF,
        example: 'canvas_test:star',
      },
    ];
    expect(serializeProps(props)).toEqual({
      myIcon: {
        title: 'My icon',
        type: 'string',
        $ref: ICON_REF,
        examples: ['canvas_test:star'],
      },
    });
  });

  it('serializes a pack-scoped icon prop with its pattern', () => {
    const props: CodeComponentProp[] = [
      {
        id: 'test-uuid',
        name: 'My icon',
        type: 'string',
        derivedType: 'icon',
        $ref: ICON_REF,
        pattern: '^(canvas_test|phosphor):.+$',
        example: 'canvas_test:star',
      },
    ];
    expect(serializeProps(props)).toEqual({
      myIcon: {
        title: 'My icon',
        type: 'string',
        $ref: ICON_REF,
        pattern: '^(canvas_test|phosphor):.+$',
        examples: ['canvas_test:star'],
      },
    });
  });

  it('deserializes an icon prop and re-derives the icon type', () => {
    const [prop] = deserializeProps({
      myIcon: {
        title: 'My icon',
        type: 'string',
        $ref: ICON_REF,
        pattern: '^(canvas_test):.+$',
        examples: ['canvas_test:star'],
      },
    });
    expect(prop.derivedType).toBe('icon');
    expect(prop.$ref).toBe(ICON_REF);
    expect(prop.pattern).toBe('^(canvas_test):.+$');
    expect(prop.example).toBe('canvas_test:star');
  });

  it('round-trips an icon prop', () => {
    const serialized = {
      myIcon: {
        title: 'My icon',
        type: 'string' as const,
        $ref: ICON_REF,
        pattern: '^(canvas_test|phosphor):.+$',
        examples: ['phosphor:acorn'],
      },
    };
    const roundTripped = serializeProps(deserializeProps(serialized));
    expect(roundTripped).toEqual(serialized);
  });

  it('does not derive a plain text prop as icon', () => {
    const [prop] = deserializeProps({
      myText: {
        title: 'My text',
        type: 'string',
      },
    });
    expect(prop.derivedType).toBe('text');
  });
});
