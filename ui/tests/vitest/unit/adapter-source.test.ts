import { describe, expect, it } from 'vitest';

import {
  BRIDGING_ADAPTER_IDS,
  buildCandidateTree,
  candidateShortLabel,
  COMBINE_MAX_PARTS,
  combineChainInputName,
  combineHasContent,
  combinePartsToInputs,
  combineSourceToParts,
  combineTextCandidates,
  createStep,
  createStepWithPrimaryField,
  getPrimaryInputName,
  humanizeInputName,
  isChainComplete,
  isStepComplete,
  normalizeChainSteps,
  parseMappingRows,
  serializeMappingRows,
  sourceToSteps,
  stepsToSource,
  supportsLiteralBinding,
} from '@/components/form/components/drupal/adapterSource';

import type {
  AdapterInputSlot,
  AdapterSuggestion,
  CombinePart,
  SlotCandidate,
} from '@/components/form/components/drupal/adapterSource';

const stringStatic = {
  sourceType: 'static:field_item:string',
  expression: 'ℹ︎string␟value',
  value: null,
};

const booleanStatic = {
  sourceType: 'static:field_item:boolean',
  expression: 'ℹ︎boolean␟value',
  value: null,
};

const titleCandidate = {
  id: 'candidate-title',
  label: 'Title',
  source: {
    sourceType: 'entity-field',
    expression: 'ℹ︎␜entity:node:article␝title␞␟value',
  },
};

const altCandidate = {
  id: 'candidate-alt',
  label: 'Image → Alternative text',
  source: {
    sourceType: 'entity-field',
    expression: 'ℹ︎␜entity:node:article␝field_image␞␟alt',
  },
};

const makeSlot = (
  name: string,
  required: boolean,
  overrides: Partial<AdapterInputSlot> = {},
): AdapterInputSlot => ({
  name,
  required,
  mirrorsOutput: false,
  schema: null,
  candidates: [titleCandidate, altCandidate],
  static: stringStatic,
  ...overrides,
});

const equalsSuggestion: AdapterSuggestion = {
  id: 'suggestion-equals',
  label: 'Equals',
  adapter: {
    id: 'equals',
    inputs: [
      makeSlot('value', true),
      makeSlot('comparison', true),
      makeSlot('then', true, {
        mirrorsOutput: true,
        schema: { type: 'string' },
      }),
      makeSlot('else', false, {
        mirrorsOutput: true,
        schema: { type: 'string' },
      }),
      makeSlot('negate', false, {
        schema: { type: 'boolean' },
        candidates: [],
        static: booleanStatic,
      }),
    ],
  },
};

const wrapSuggestion: AdapterSuggestion = {
  id: 'suggestion-wrap',
  label: 'Wrap',
  adapter: {
    id: 'wrap',
    inputs: [
      makeSlot('value', true),
      makeSlot('prefix', false),
      makeSlot('suffix', false),
    ],
  },
};

const mappingSuggestion: AdapterSuggestion = {
  id: 'suggestion-mapping',
  label: 'Mapping',
  adapter: {
    id: 'mapping',
    inputs: [
      makeSlot('value', true),
      makeSlot('cases', true, { candidates: [] }),
      // The default slot mirrors the target prop shape; its schema type
      // drives the coercion of edited mapping case outputs.
      makeSlot('default', false, {
        mirrorsOutput: true,
        schema: { type: 'integer' },
      }),
    ],
  },
};

const combineSuggestion: AdapterSuggestion = {
  id: 'suggestion-combine',
  label: 'Combine',
  adapter: {
    id: 'combine',
    inputs: [
      makeSlot('text_1', true),
      makeSlot('text_2', true),
      makeSlot('text_3', false),
      makeSlot('text_4', false),
      makeSlot('separator', false, { candidates: [] }),
    ],
  },
};

// A bridging adapter: `value` (required, the primary input) plus a required
// `default` that the user must still fill after picking a field.
const fallbackSuggestion: AdapterSuggestion = {
  id: 'suggestion-fallback',
  label: 'Fallback',
  adapter: {
    id: 'fallback',
    inputs: [
      makeSlot('value', true, { mirrorsOutput: true }),
      makeSlot('default', true, { mirrorsOutput: true }),
    ],
  },
};

const allSuggestions = [
  equalsSuggestion,
  wrapSuggestion,
  mappingSuggestion,
  combineSuggestion,
];

// Builds the configured "Equals" step used across several tests: value bound
// to the Title field, comparison and then bound to literals.
const buildEqualsStep = () => {
  const step = createStep(equalsSuggestion);
  step.bindings.value = {
    mode: 'field',
    enabled: true,
    candidateId: titleCandidate.id,
    source: titleCandidate.source,
  };
  step.bindings.comparison = { mode: 'literal', enabled: true, value: '0' };
  step.bindings.then = { mode: 'literal', enabled: true, value: 'Free' };
  return step;
};

const serializedEquals = {
  sourceType: 'adapter:equals',
  adapterInputs: {
    value: titleCandidate.source,
    comparison: { ...stringStatic, value: '0' },
    then: { ...stringStatic, value: 'Free' },
  },
};

describe('humanizeInputName', () => {
  it('capitalizes and replaces underscores', () => {
    expect(humanizeInputName('text_1')).toBe('Text 1');
    expect(humanizeInputName('needle')).toBe('Needle');
    expect(humanizeInputName('value')).toBe('Value');
    expect(humanizeInputName('starts_with')).toBe('Starts with');
  });
});

describe('getPrimaryInputName', () => {
  it('returns the first required input in declaration order', () => {
    expect(getPrimaryInputName(equalsSuggestion.adapter)).toBe('value');
    expect(getPrimaryInputName(combineSuggestion.adapter)).toBe('text_1');
  });
});

describe('stepsToSource', () => {
  it('serializes a single step with field and literal bindings', () => {
    expect(stepsToSource([buildEqualsStep()])).toEqual(serializedEquals);
  });

  it('omits optional inputs that are not configured', () => {
    const source = stepsToSource([buildEqualsStep()]);
    expect(source?.adapterInputs).not.toHaveProperty('else');
    expect(source?.adapterInputs).not.toHaveProperty('negate');
  });

  it('nests a two-step chain with the last step outermost', () => {
    const wrapper = createStep(wrapSuggestion);
    wrapper.bindings.prefix = { mode: 'literal', enabled: true, value: '$' };
    expect(stepsToSource([buildEqualsStep(), wrapper])).toEqual({
      sourceType: 'adapter:wrap',
      adapterInputs: {
        value: serializedEquals,
        prefix: { ...stringStatic, value: '$' },
      },
    });
  });

  it('serializes mapping cases rows to a JSON object string', () => {
    const step = createStep(mappingSuggestion);
    step.bindings.value = {
      mode: 'field',
      enabled: true,
      candidateId: titleCandidate.id,
      source: titleCandidate.source,
    };
    step.bindings.cases = {
      mode: 'literal',
      enabled: true,
      rows: [
        { key: 'blue', value: 'primary' },
        { key: 'red', value: 'danger' },
        // Rows without a key are not serialized.
        { key: '', value: 'ignored' },
      ],
    };
    expect(stepsToSource([step])).toEqual({
      sourceType: 'adapter:mapping',
      adapterInputs: {
        value: titleCandidate.source,
        cases: { ...stringStatic, value: '{"blue":"primary","red":"danger"}' },
      },
    });
  });

  it('coerces new mapping rows through the mirrored output slot type', () => {
    const step = createStep(mappingSuggestion);
    step.bindings.value = {
      mode: 'field',
      enabled: true,
      candidateId: titleCandidate.id,
      source: titleCandidate.source,
    };
    // The fixture's `default` slot mirrors an integer output shape.
    step.bindings.cases = {
      mode: 'literal',
      enabled: true,
      rows: [{ key: 'blue', value: '1' }],
    };
    expect(stepsToSource([step])?.adapterInputs.cases).toEqual({
      ...stringStatic,
      value: '{"blue":1}',
    });
  });

  it('serializes a combine step from its pill-editor parts', () => {
    const step = createStep(combineSuggestion);
    // The pill editor: a field, literal text, then another field.
    step.parts = [
      {
        kind: 'field',
        label: 'Title',
        source: titleCandidate.source,
        candidateId: titleCandidate.id,
      },
      { kind: 'text', text: 'and' },
      {
        kind: 'field',
        label: 'Alternative text',
        source: altCandidate.source,
        candidateId: altCandidate.id,
      },
    ];
    // An explicit empty separator is emitted so the parts concatenate directly.
    expect(stepsToSource([step])).toEqual({
      sourceType: 'adapter:combine',
      adapterInputs: {
        text_1: titleCandidate.source,
        text_2: { ...stringStatic, value: 'and' },
        text_3: altCandidate.source,
        separator: { ...stringStatic, value: '' },
      },
    });
  });

  it('returns null for an empty chain', () => {
    expect(stepsToSource([])).toBeNull();
  });
});

describe('isChainComplete', () => {
  it('detects an incomplete configuration', () => {
    const step = createStep(equalsSuggestion);
    step.bindings.value = {
      mode: 'field',
      enabled: true,
      candidateId: titleCandidate.id,
      source: titleCandidate.source,
    };
    // comparison and then are still unbound.
    expect(isChainComplete([step])).toBe(false);

    // An empty-string literal does not count as bound.
    step.bindings.comparison = { mode: 'literal', enabled: true, value: '' };
    step.bindings.then = { mode: 'literal', enabled: true, value: 'Free' };
    expect(isChainComplete([step])).toBe(false);
  });

  it('accepts a complete configuration', () => {
    expect(isChainComplete([buildEqualsStep()])).toBe(true);
  });

  it('treats an enabled but empty optional slot as complete', () => {
    const step = buildEqualsStep();
    step.bindings.else = { mode: 'literal', enabled: true, value: '' };
    expect(isChainComplete([step])).toBe(true);
  });

  it('auto-binds the primary input of steps after the first', () => {
    const wrapper = createStep(wrapSuggestion);
    // The wrapper's `value` primary input is fed by the previous step, so the
    // chain is complete without a user binding for it.
    expect(isChainComplete([buildEqualsStep(), wrapper])).toBe(true);
    // As step 1 the same configuration is incomplete.
    expect(isChainComplete([wrapper])).toBe(false);
  });

  it('rejects an empty chain', () => {
    expect(isChainComplete([])).toBe(false);
  });
});

describe('sourceToSteps', () => {
  it('round-trips a single step', () => {
    const steps = sourceToSteps(serializedEquals, allSuggestions);
    expect(steps).toHaveLength(1);
    expect(steps?.[0].adapter.id).toBe('equals');
    // The field binding is matched back to its candidate.
    expect(steps?.[0].bindings.value.mode).toBe('field');
    expect(steps?.[0].bindings.value.candidateId).toBe(titleCandidate.id);
    // Literal bindings are recognized.
    expect(steps?.[0].bindings.comparison.mode).toBe('literal');
    expect(steps?.[0].bindings.comparison.value).toBe('0');
    // Unbound optional inputs stay disabled.
    expect(steps?.[0].bindings.else.enabled).toBe(false);
    expect(stepsToSource(steps!)).toEqual(serializedEquals);
  });

  it('round-trips a two-step chain', () => {
    const chained = {
      sourceType: 'adapter:wrap',
      adapterInputs: {
        value: serializedEquals,
        prefix: { ...stringStatic, value: '$' },
      },
    };
    const steps = sourceToSteps(chained, allSuggestions);
    expect(steps).toHaveLength(2);
    // Innermost step first.
    expect(steps?.[0].adapter.id).toBe('equals');
    expect(steps?.[1].adapter.id).toBe('wrap');
    expect(steps?.[1].bindings.prefix.value).toBe('$');
    expect(stepsToSource(steps!)).toEqual(chained);
  });

  it('round-trips mapping cases rows', () => {
    const source = {
      sourceType: 'adapter:mapping',
      adapterInputs: {
        value: titleCandidate.source,
        cases: { ...stringStatic, value: '{"blue":"primary","red":"danger"}' },
      },
    };
    const steps = sourceToSteps(source, allSuggestions);
    expect(steps?.[0].bindings.cases.rows).toEqual([
      { key: 'blue', value: 'primary', originalValue: 'primary' },
      { key: 'red', value: 'danger', originalValue: 'danger' },
    ]);
    expect(stepsToSource(steps!)).toEqual(source);
  });

  it('round-trips typed mapping case outputs without losing their types', () => {
    const source = {
      sourceType: 'adapter:mapping',
      adapterInputs: {
        value: titleCandidate.source,
        cases: { ...stringStatic, value: '{"1":1,"flag":true}' },
      },
    };
    const steps = sourceToSteps(source, allSuggestions);
    expect(steps?.[0].bindings.cases.rows).toEqual([
      { key: '1', value: '1', originalValue: 1 },
      { key: 'flag', value: 'true', originalValue: true },
    ]);
    // Unedited rows keep their original typed values.
    expect(stepsToSource(steps!)).toEqual(source);
  });

  it('preserves a static source written from a different template verbatim', () => {
    const integerStaticSource = {
      sourceType: 'static:field_item:integer',
      expression: 'ℹ︎integer␟value',
      value: 5,
    };
    // The equals `comparison` slot accepts anything but its static template
    // is a string: decoding this as a literal would turn the integer into a string.
    const source = {
      sourceType: 'adapter:equals',
      adapterInputs: {
        value: titleCandidate.source,
        comparison: integerStaticSource,
        then: { ...stringStatic, value: 'Free' },
      },
    };
    const steps = sourceToSteps(source, allSuggestions);
    expect(steps?.[0].bindings.comparison.mode).toBe('field');
    expect(steps?.[0].bindings.comparison.source).toEqual(integerStaticSource);
    expect(stepsToSource(steps!)).toEqual(source);
  });

  it('round-trips combine through pill-editor parts', () => {
    const source = {
      sourceType: 'adapter:combine',
      adapterInputs: {
        text_1: titleCandidate.source,
        text_2: { ...stringStatic, value: 'and' },
        text_3: altCandidate.source,
        separator: { ...stringStatic, value: '' },
      },
    };
    const steps = sourceToSteps(source, allSuggestions);
    // Combine reconstructs pill-editor parts rather than per-slot bindings.
    expect(steps?.[0].parts).toEqual([
      {
        kind: 'field',
        label: 'Title',
        source: titleCandidate.source,
        candidateId: titleCandidate.id,
      },
      { kind: 'text', text: 'and' },
      {
        kind: 'field',
        label: 'Alternative text',
        source: altCandidate.source,
        candidateId: altCandidate.id,
      },
    ]);
    expect(stepsToSource(steps!)).toEqual(source);
  });

  it('returns null for non-adapter sources', () => {
    expect(sourceToSteps(titleCandidate.source, allSuggestions)).toBeNull();
    expect(sourceToSteps(undefined, allSuggestions)).toBeNull();
    expect(sourceToSteps(null, allSuggestions)).toBeNull();
  });

  it('returns null when the outermost adapter has no suggestion', () => {
    expect(
      sourceToSteps(
        { sourceType: 'adapter:unknown', adapterInputs: {} },
        allSuggestions,
      ),
    ).toBeNull();
  });

  it('preserves a nested unknown adapter as an opaque bound value', () => {
    const unknownInner = {
      sourceType: 'adapter:unknown',
      adapterInputs: {},
    };
    const source = {
      sourceType: 'adapter:wrap',
      adapterInputs: {
        value: unknownInner,
        prefix: { ...stringStatic, value: '$' },
      },
    };
    const steps = sourceToSteps(source, allSuggestions);
    expect(steps).toHaveLength(1);
    expect(steps?.[0].bindings.value.mode).toBe('field');
    expect(steps?.[0].bindings.value.source).toEqual(unknownInner);
    expect(stepsToSource(steps!)).toEqual(source);
  });
});

describe('mapping rows helpers', () => {
  it('serializes rows to a JSON object string', () => {
    expect(
      serializeMappingRows([
        { key: 'blue', value: 'primary' },
        { key: '', value: 'ignored' },
      ]),
    ).toBe('{"blue":"primary"}');
  });

  it('parses a JSON object string into rows', () => {
    expect(parseMappingRows('{"blue":"primary"}')).toEqual([
      { key: 'blue', value: 'primary', originalValue: 'primary' },
    ]);
  });

  it('coerces edited or new case outputs to the mirrored output type', () => {
    expect(serializeMappingRows([{ key: 'a', value: '2' }], 'integer')).toBe(
      '{"a":2}',
    );
    // Values the type cannot represent stay strings.
    expect(serializeMappingRows([{ key: 'a', value: 'x' }], 'integer')).toBe(
      '{"a":"x"}',
    );
    expect(serializeMappingRows([{ key: 'a', value: 'true' }], 'boolean')).toBe(
      '{"a":true}',
    );
    expect(serializeMappingRows([{ key: 'a', value: '2.5' }], 'number')).toBe(
      '{"a":2.5}',
    );
    // Without an output type the value stays a string.
    expect(serializeMappingRows([{ key: 'a', value: '2' }])).toBe('{"a":"2"}');
  });

  it('keeps the original typed value for unedited rows only', () => {
    expect(
      serializeMappingRows([{ key: 'a', value: '1', originalValue: 1 }]),
    ).toBe('{"a":1}');
    // An edited row no longer matches its original value and is re-coerced.
    expect(
      serializeMappingRows(
        [{ key: 'a', value: '2', originalValue: 1 }],
        'integer',
      ),
    ).toBe('{"a":2}');
  });

  it('falls back to a single empty row for invalid input', () => {
    expect(parseMappingRows('not json')).toEqual([{ key: '', value: '' }]);
    expect(parseMappingRows(null)).toEqual([{ key: '', value: '' }]);
    expect(parseMappingRows('[]')).toEqual([{ key: '', value: '' }]);
  });
});

describe('supportsLiteralBinding', () => {
  it('rejects slots without a static template', () => {
    expect(supportsLiteralBinding(makeSlot('x', true, { static: null }))).toBe(
      false,
    );
  });

  it('rejects object- and array-shaped slots', () => {
    expect(
      supportsLiteralBinding(
        makeSlot('x', true, { schema: { type: 'object' } }),
      ),
    ).toBe(false);
    expect(
      supportsLiteralBinding(
        makeSlot('x', true, { schema: { type: 'array' } }),
      ),
    ).toBe(false);
    expect(
      supportsLiteralBinding(
        makeSlot('x', true, {
          schema: { $ref: 'json-schema-definitions://canvas.module/image' },
        }),
      ),
    ).toBe(false);
  });

  it('accepts primitive and "any" shapes', () => {
    // A null schema means any value is accepted.
    expect(supportsLiteralBinding(makeSlot('x', true))).toBe(true);
    expect(
      supportsLiteralBinding(
        makeSlot('x', true, { schema: { type: 'string' } }),
      ),
    ).toBe(true);
    expect(
      supportsLiteralBinding(
        makeSlot('x', true, { schema: { type: 'boolean' } }),
      ),
    ).toBe(true);
  });
});

describe('BRIDGING_ADAPTER_IDS', () => {
  it('is the curated set of fallback and format_date only', () => {
    expect(BRIDGING_ADAPTER_IDS).toEqual(['fallback', 'format_date']);
  });
});

describe('createStepWithPrimaryField', () => {
  it('pre-binds the primary input to the chosen field candidate', () => {
    const step = createStepWithPrimaryField(fallbackSuggestion, titleCandidate);
    // `value` is the primary input (first required) and is bound to the field.
    expect(step.bindings.value).toEqual({
      mode: 'field',
      enabled: true,
      candidateId: titleCandidate.id,
      source: titleCandidate.source,
    });
  });

  it('leaves the other inputs at their defaults', () => {
    const step = createStepWithPrimaryField(fallbackSuggestion, titleCandidate);
    // `default` is required but unbound, so it keeps the default binding.
    expect(step.bindings.default.enabled).toBe(true);
    expect(step.bindings.default.source).toBeUndefined();
    expect(step.bindings.default.candidateId).toBeUndefined();
  });

  it('is incomplete until the remaining required input is filled', () => {
    const step = createStepWithPrimaryField(fallbackSuggestion, titleCandidate);
    // Only the primary field is bound; `default` still needs a value.
    expect(isStepComplete(step, 0)).toBe(false);

    step.bindings.default = {
      mode: 'field',
      enabled: true,
      candidateId: altCandidate.id,
      source: altCandidate.source,
    };
    expect(isStepComplete(step, 0)).toBe(true);
    expect(stepsToSource([step])).toEqual({
      sourceType: 'adapter:fallback',
      adapterInputs: {
        value: titleCandidate.source,
        default: altCandidate.source,
      },
    });
  });
});

describe('candidateShortLabel', () => {
  it('returns the last path segment', () => {
    expect(candidateShortLabel('Title')).toBe('Title');
    expect(candidateShortLabel('Image → Alternative text')).toBe(
      'Alternative text',
    );
    expect(candidateShortLabel('Authored by → User → Picture → Title')).toBe(
      'Title',
    );
  });
});

describe('buildCandidateTree', () => {
  const candidate = (id: string, label: string): SlotCandidate => ({
    id,
    label,
    source: { sourceType: 'entity-field', expression: `expr-${id}` },
  });

  it('keeps single-segment labels as top-level leaves', () => {
    const title = candidate('t', 'Title');
    expect(buildCandidateTree([title])).toEqual([
      { label: 'Title', candidate: title },
    ]);
  });

  it('splits a multi-segment label into nested submenus', () => {
    const alt = candidate('a', 'Image → Alternative text');
    expect(buildCandidateTree([alt])).toEqual([
      {
        label: 'Image',
        children: [{ label: 'Alternative text', candidate: alt }],
      },
    ]);
  });

  it('merges candidates that share a path prefix', () => {
    const name = candidate('n', 'Authored by → User → Name');
    const picture = candidate('p', 'Authored by → User → Picture → Title');
    expect(buildCandidateTree([name, picture])).toEqual([
      {
        label: 'Authored by',
        children: [
          {
            label: 'User',
            children: [
              { label: 'Name', candidate: name },
              {
                label: 'Picture',
                children: [{ label: 'Title', candidate: picture }],
              },
            ],
          },
        ],
      },
    ]);
  });

  it('keeps distinct top-level paths separate', () => {
    const title = candidate('t', 'Title');
    const alt = candidate('a', 'Image → Alternative text');
    const tree = buildCandidateTree([title, alt]);
    expect(tree).toHaveLength(2);
    expect(tree[0]).toEqual({ label: 'Title', candidate: title });
    expect(tree[1].label).toBe('Image');
  });

  it('supports a node that is both a selectable leaf and a submenu', () => {
    // "User" is selectable itself and also a parent of "Name".
    const user = candidate('u', 'Authored by → User');
    const userName = candidate('un', 'Authored by → User → Name');
    expect(buildCandidateTree([user, userName])).toEqual([
      {
        label: 'Authored by',
        children: [
          {
            label: 'User',
            candidate: user,
            children: [{ label: 'Name', candidate: userName }],
          },
        ],
      },
    ]);
  });
});

describe('combine pill editor helpers', () => {
  const combineSlots = combineSuggestion.adapter.inputs;
  const textStatic = (value: string) => ({ ...stringStatic, value });
  const titlePill: CombinePart = {
    kind: 'field',
    label: 'Title',
    source: titleCandidate.source,
    candidateId: titleCandidate.id,
  };
  const altPill: CombinePart = {
    kind: 'field',
    label: 'Alternative text',
    source: altCandidate.source,
    candidateId: altCandidate.id,
  };

  describe('combinePartsToInputs', () => {
    it('maps text plus one pill and skips the empty trailing run', () => {
      const parts: CombinePart[] = [
        { kind: 'text', text: 'Hello ' },
        titlePill,
        { kind: 'text', text: '' },
      ];
      expect(combinePartsToInputs(parts, combineSlots)).toEqual({
        text_1: textStatic('Hello '),
        text_2: titleCandidate.source,
        // Empty separator so the parts concatenate directly.
        separator: textStatic(''),
      });
    });

    it('maps multiple pills with text between them', () => {
      const parts: CombinePart[] = [
        titlePill,
        { kind: 'text', text: ' and ' },
        altPill,
      ];
      expect(combinePartsToInputs(parts, combineSlots)).toEqual({
        text_1: titleCandidate.source,
        text_2: textStatic(' and '),
        text_3: altCandidate.source,
        separator: textStatic(''),
      });
    });

    it('keeps leading and trailing text runs', () => {
      const parts: CombinePart[] = [
        { kind: 'text', text: 'A' },
        titlePill,
        { kind: 'text', text: 'B' },
      ];
      const inputs = combinePartsToInputs(parts, combineSlots);
      expect(inputs.text_1).toEqual(textStatic('A'));
      expect(inputs.text_2).toEqual(titleCandidate.source);
      expect(inputs.text_3).toEqual(textStatic('B'));
    });

    it('skips empty text runs entirely', () => {
      const parts: CombinePart[] = [
        { kind: 'text', text: '' },
        titlePill,
        { kind: 'text', text: '' },
      ];
      expect(combinePartsToInputs(parts, combineSlots)).toEqual({
        text_1: titleCandidate.source,
        separator: textStatic(''),
      });
    });

    it('caps the emitted parts at text_1…text_10', () => {
      const parts: CombinePart[] = Array.from({ length: 12 }, () => titlePill);
      const inputs = combinePartsToInputs(parts, combineSlots);
      const textKeys = Object.keys(inputs).filter((key) =>
        key.startsWith('text_'),
      );
      expect(textKeys).toHaveLength(COMBINE_MAX_PARTS);
      expect(inputs.text_10).toEqual(titleCandidate.source);
      expect(inputs.text_11).toBeUndefined();
    });

    it('places the previous source at its part position when chained', () => {
      const previous = { sourceType: 'adapter:equals', adapterInputs: {} };
      const parts: CombinePart[] = [
        { kind: 'text', text: 'on ' },
        { kind: 'previous' },
        { kind: 'text', text: '!' },
      ];
      expect(combinePartsToInputs(parts, combineSlots, previous)).toEqual({
        text_1: textStatic('on '),
        text_2: previous,
        text_3: textStatic('!'),
        separator: textStatic(''),
      });
    });

    it('falls back to a leading previous source when no part places it', () => {
      const previous = { sourceType: 'adapter:equals', adapterInputs: {} };
      const parts: CombinePart[] = [{ kind: 'text', text: '!' }];
      expect(combinePartsToInputs(parts, combineSlots, previous)).toEqual({
        text_1: previous,
        text_2: textStatic('!'),
        separator: textStatic(''),
      });
    });
  });

  describe('combineChainInputName', () => {
    it('finds the first text input holding a nested adapter source', () => {
      const source = {
        sourceType: 'adapter:combine',
        adapterInputs: {
          text_1: textStatic('on '),
          text_2: { sourceType: 'adapter:equals', adapterInputs: {} },
        },
      };
      expect(combineChainInputName(source)).toBe('text_2');
    });

    it('returns null when no text input holds an adapter source', () => {
      const source = {
        sourceType: 'adapter:combine',
        adapterInputs: { text_1: textStatic('!') },
      };
      expect(combineChainInputName(source)).toBeNull();
    });
  });

  describe('normalizeChainSteps', () => {
    it('strips the previous pill from a combine step that became first', () => {
      const step = createStep(combineSuggestion);
      step.parts = [{ kind: 'previous' }, { kind: 'text', text: '!' }];
      expect(normalizeChainSteps([step])[0].parts).toEqual([
        { kind: 'text', text: '!' },
      ]);
    });

    it('prepends a previous pill to a combine step that became later', () => {
      const first = createStep(equalsSuggestion);
      const combine = createStep(combineSuggestion);
      combine.parts = [{ kind: 'text', text: '!' }];
      expect(normalizeChainSteps([first, combine])[1].parts).toEqual([
        { kind: 'previous' },
        { kind: 'text', text: '!' },
      ]);
    });
  });

  describe('combineTextCandidates', () => {
    it('unions candidates across all text slots, deduplicated by id', () => {
      // text_1 is restricted (required fields only) while text_2 offers the
      // full set including followed references; the union offers both.
      const slots = [
        makeSlot('text_1', true, { candidates: [titleCandidate] }),
        makeSlot('text_2', false, {
          candidates: [titleCandidate, altCandidate],
        }),
        makeSlot('separator', false, { candidates: [] }),
      ];
      expect(combineTextCandidates(slots)).toEqual([
        titleCandidate,
        altCandidate,
      ]);
    });
  });

  describe('combineSourceToParts', () => {
    it('reconstructs text runs and pills, resolving pill labels', () => {
      const source = {
        sourceType: 'adapter:combine',
        adapterInputs: {
          text_1: textStatic('Hello '),
          text_2: titleCandidate.source,
          separator: textStatic(''),
        },
      };
      expect(combineSourceToParts(source, combineSlots)).toEqual([
        { kind: 'text', text: 'Hello ' },
        {
          kind: 'field',
          label: 'Title',
          source: titleCandidate.source,
          candidateId: titleCandidate.id,
        },
      ]);
    });

    it('shows a generic pill for a source matching no candidate', () => {
      const opaque = { sourceType: 'entity-field', expression: 'unknown' };
      const source = {
        sourceType: 'adapter:combine',
        adapterInputs: { text_1: opaque },
      };
      expect(combineSourceToParts(source, combineSlots)).toEqual([
        {
          kind: 'field',
          label: 'Field',
          source: opaque,
          candidateId: undefined,
        },
      ]);
    });

    it('turns the chain input into a previous part when chained', () => {
      const source = {
        sourceType: 'adapter:combine',
        adapterInputs: {
          text_1: textStatic('on '),
          text_2: { sourceType: 'adapter:equals', adapterInputs: {} },
          text_3: textStatic('!'),
        },
      };
      expect(combineSourceToParts(source, combineSlots, 'text_2')).toEqual([
        { kind: 'text', text: 'on ' },
        { kind: 'previous' },
        { kind: 'text', text: '!' },
      ]);
    });

    it('round-trips stored → parts → stored', () => {
      const parts: CombinePart[] = [
        { kind: 'text', text: 'Price: ' },
        titlePill,
        { kind: 'text', text: ' now' },
        altPill,
      ];
      const stored = combinePartsToInputs(parts, combineSlots);
      const source = { sourceType: 'adapter:combine', adapterInputs: stored };
      const reconstructed = combineSourceToParts(source, combineSlots);
      expect(combinePartsToInputs(reconstructed, combineSlots)).toEqual(stored);
    });
  });

  describe('combine completeness', () => {
    it('seeds a fresh combine step with a single empty text part', () => {
      const step = createStep(combineSuggestion);
      expect(step.parts).toEqual([{ kind: 'text', text: '' }]);
    });

    it('is incomplete with no non-empty part and complete once filled', () => {
      const step = createStep(combineSuggestion);
      expect(combineHasContent(step.parts ?? [])).toBe(false);
      expect(isStepComplete(step, 0)).toBe(false);
      expect(isChainComplete([step])).toBe(false);

      step.parts = [{ kind: 'text', text: 'Hi' }];
      expect(combineHasContent(step.parts)).toBe(true);
      expect(isStepComplete(step, 0)).toBe(true);
      expect(isChainComplete([step])).toBe(true);
    });

    it('requires the previous pill in a later chain step', () => {
      const step = createStep(combineSuggestion);
      step.parts = [{ kind: 'text', text: 'on ' }];
      expect(isStepComplete(step, 1)).toBe(false);
      step.parts = [{ kind: 'text', text: 'on ' }, { kind: 'previous' }];
      expect(isStepComplete(step, 1)).toBe(true);
    });

    it('serializes a configured combine step through stepsToSource', () => {
      const step = createStep(combineSuggestion);
      step.parts = [{ kind: 'text', text: 'Hello ' }, titlePill];
      expect(stepsToSource([step])).toEqual({
        sourceType: 'adapter:combine',
        adapterInputs: {
          text_1: textStatic('Hello '),
          text_2: titleCandidate.source,
          separator: textStatic(''),
        },
      });
    });

    it('round-trips a chain with a positioned previous pill', () => {
      const combine = createStep(combineSuggestion);
      combine.parts = [
        { kind: 'text', text: 'on ' },
        { kind: 'previous' },
        { kind: 'text', text: '!' },
      ];
      const source = stepsToSource([buildEqualsStep(), combine]);
      expect(source?.adapterInputs).toEqual({
        text_1: textStatic('on '),
        text_2: serializedEquals,
        text_3: textStatic('!'),
        separator: textStatic(''),
      });
      const steps = sourceToSteps(source, allSuggestions);
      expect(steps).toHaveLength(2);
      expect(steps?.[1].parts).toEqual(combine.parts);
    });
  });
});
