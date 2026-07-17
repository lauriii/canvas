import { describe, expect, it } from 'vitest';

import {
  createStep,
  getPrimaryInputName,
  humanizeInputName,
  isChainComplete,
  parseMappingRows,
  serializeMappingRows,
  sourceToSteps,
  stepsToSource,
} from '@/components/form/components/drupal/adapterSource';

import type {
  AdapterInputSlot,
  AdapterSuggestion,
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

const prefixSuffixSuggestion: AdapterSuggestion = {
  id: 'suggestion-prefix-suffix',
  label: 'Prefix / suffix',
  adapter: {
    id: 'prefix_suffix',
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
      makeSlot('default', false),
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

const allSuggestions = [
  equalsSuggestion,
  prefixSuffixSuggestion,
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
    const wrapper = createStep(prefixSuffixSuggestion);
    wrapper.bindings.prefix = { mode: 'literal', enabled: true, value: '$' };
    expect(stepsToSource([buildEqualsStep(), wrapper])).toEqual({
      sourceType: 'adapter:prefix_suffix',
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

  it('serializes combine with three texts and omits the empty separator', () => {
    const step = createStep(combineSuggestion);
    step.bindings.text_1 = {
      mode: 'field',
      enabled: true,
      candidateId: titleCandidate.id,
      source: titleCandidate.source,
    };
    step.bindings.text_2 = { mode: 'literal', enabled: true, value: 'and' };
    step.bindings.text_3 = {
      mode: 'field',
      enabled: true,
      candidateId: altCandidate.id,
      source: altCandidate.source,
    };
    // The separator is left unset: the server default (a single space)
    // applies.
    expect(stepsToSource([step])).toEqual({
      sourceType: 'adapter:combine',
      adapterInputs: {
        text_1: titleCandidate.source,
        text_2: { ...stringStatic, value: 'and' },
        text_3: altCandidate.source,
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
    const wrapper = createStep(prefixSuffixSuggestion);
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
      sourceType: 'adapter:prefix_suffix',
      adapterInputs: {
        value: serializedEquals,
        prefix: { ...stringStatic, value: '$' },
      },
    };
    const steps = sourceToSteps(chained, allSuggestions);
    expect(steps).toHaveLength(2);
    // Innermost step first.
    expect(steps?.[0].adapter.id).toBe('equals');
    expect(steps?.[1].adapter.id).toBe('prefix_suffix');
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
      { key: 'blue', value: 'primary' },
      { key: 'red', value: 'danger' },
    ]);
    expect(stepsToSource(steps!)).toEqual(source);
  });

  it('round-trips combine with three texts', () => {
    const source = {
      sourceType: 'adapter:combine',
      adapterInputs: {
        text_1: titleCandidate.source,
        text_2: { ...stringStatic, value: 'and' },
        text_3: altCandidate.source,
      },
    };
    const steps = sourceToSteps(source, allSuggestions);
    expect(steps?.[0].bindings.text_3.enabled).toBe(true);
    expect(steps?.[0].bindings.text_4.enabled).toBe(false);
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
      sourceType: 'adapter:prefix_suffix',
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
      { key: 'blue', value: 'primary' },
    ]);
  });

  it('falls back to a single empty row for invalid input', () => {
    expect(parseMappingRows('not json')).toEqual([{ key: '', value: '' }]);
    expect(parseMappingRows(null)).toEqual([{ key: '', value: '' }]);
    expect(parseMappingRows('[]')).toEqual([{ key: '', value: '' }]);
  });
});
