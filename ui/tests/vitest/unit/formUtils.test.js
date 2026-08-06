import {
  coerceValueForSchema,
  shouldSkipPropValidation,
  validateProp,
} from '@/components/form/react-hook-form/fields/componentFieldValidation';
import {
  formStateToObject,
  getPropsValues,
  propInputData,
} from '@/components/form/react-hook-form/fields/componentFormData';
import { getCurrentValueFromProps } from '@/components/form/react-hook-form/utils';

let formState = {
  'canvas_component_props[all-props][heading][0][value]': 'hello, world!',
  'canvas_component_props[all-props][headingMultiple][0][value]':
    'hello, world!',
  'canvas_component_props[all-props][headingMultiple][1][value]':
    'goodbye, world!',
  'canvas_component_props[all-props][subheading][0][value]': '',
  'canvas_component_props[all-props][cta1][0][value]': '',
  'canvas_component_props[all-props][cta1href][0][uri]': 'https://drupal.org',
  'canvas_component_props[all-props][cta1href][0][title]': 'Do it',
  'canvas_component_props[all-props][cta2][0][value]': '',
  'canvas_component_props[all-props][a_boolean][value]': true,
  'canvas_component_props[all-props][options_select]': 'fine thx',
  'canvas_component_props[all-props][unchecked_boolean][value]': false,
  'canvas_component_props[all-props][date][0][value][date]': '2025-02-02',
  'canvas_component_props[all-props][datetime][0][value][date]': '2025-01-31',
  'canvas_component_props[all-props][datetime][0][value][time]': '20:30:33',
  'canvas_component_props[all-props][dateMultiple][0][value][date]':
    '2025-02-02',
  'canvas_component_props[all-props][dateMultiple][1][value][date]':
    '2025-02-03',
  'canvas_component_props[all-props][datetimeMultiple][0][value][date]':
    '2025-01-31',
  'canvas_component_props[all-props][datetimeMultiple][0][value][time]':
    '20:30:33',
  'canvas_component_props[all-props][datetimeMultiple][1][value][date]':
    '2025-02-01',
  'canvas_component_props[all-props][datetimeMultiple][1][value][time]':
    '20:30:35',
  'canvas_component_props[all-props][email][0][value]': 'bob@example.com',
  'canvas_component_props[all-props][number][0][value]': '123',
  'canvas_component_props[all-props][float][0][value]': 123.45,
  'canvas_component_props[all-props][textarea][0][value]': `Hi there
Multiline
Value`,
  'canvas_component_props[all-props][linkNoTitle][0][uri]':
    'http://example.com',
  'canvas_component_props[all-props][linkNoTitleEmpty][0][uri]': '',
  'canvas_component_props[all-props][entityReferenceAutocomplete][0][target_id]':
    'A node title (3)',
  'canvas_component_props[all-props][media][selection][0][target_id]': 3,
  'canvas_component_props[all-props][mediaMultiple][selection][0][target_id]': 3,
  'canvas_component_props[all-props][mediaMultiple][selection][1][target_id]': 4,
  'canvas_component_props[all-props][colors][]': ['green', 'yellow'],
  'canvas_component_props[all-props][singleColor][]': ['green'],
  'canvas_component_props[all-props][emptyColors][]': [],
  form_build_id: 'this-is-a-form-build-id',
  form_token: 'this-is-a-form-token',
  form_id: 'component_instance_form',
};
let inputAndUiData = {
  version: '82b745980fd23b55',
  selectedComponent: 'all-props',
  selectedComponentType: 'sdc.sdc_test_all_props.all-props',
  layout: [],
  model: {
    'all-props': {
      resolved: {},
      // Minimal source representation.
      source: {
        a_boolean: {},
        unchecked_boolean: {},
        number: {},
        float: {},
        datetime: {
          sourceTypeSettings: {
            instance: {},
            storage: {
              datetime_type: 'datetime',
            },
          },
        },
        date: {
          sourceTypeSettings: {
            instance: {},
            storage: {
              datetime_type: 'date',
            },
          },
        },
        datetimeMultiple: {
          sourceTypeSettings: {
            instance: {},
            storage: {
              datetime_type: 'datetime',
            },
          },
        },
        dateMultiple: {
          sourceTypeSettings: {
            instance: {},
            storage: {
              datetime_type: 'date',
            },
          },
        },
        cta1href: {
          sourceTypeSettings: {
            instance: {
              // Simulate a title.
              title: 1,
            },
            storage: {},
          },
        },
        linkNoTitle: {
          sourceTypeSettings: {
            instance: {
              title: 0,
            },
            storage: {},
          },
        },
        linkNoTitleEmpty: {
          sourceTypeSettings: {
            instance: {
              title: 0,
            },
            storage: {},
          },
        },
        entityReferenceAutocomplete: {},
      },
    },
  },
  components: {
    'sdc.sdc_test_all_props.all-props': {
      propSources: {
        a_boolean: {
          jsonSchema: {
            type: 'boolean',
          },
        },
        unchecked_boolean: {
          jsonSchema: {
            type: 'boolean',
          },
        },
        number: {
          jsonSchema: {
            type: 'integer',
          },
        },
        float: {
          jsonSchema: {
            type: 'number',
          },
        },
        datetime: {
          sourceTypeSettings: {
            instance: {},
            storage: {
              datetime_type: 'datetime',
            },
          },
        },
        datetimeMultiple: {
          sourceTypeSettings: {
            instance: {},
            storage: {
              datetime_type: 'datetime',
            },
          },
        },
        date: {
          sourceTypeSettings: {
            instance: {},
            storage: {
              datetime_type: 'date',
            },
          },
        },
        dateMultiple: {
          sourceTypeSettings: {
            instance: {},
            storage: {
              datetime_type: 'date',
            },
          },
        },
        cta1href: {
          sourceTypeSettings: {
            instance: {
              // Simulate a title.
              title: 1,
            },
            storage: {},
          },
        },
        linkNoTitle: {
          sourceTypeSettings: {
            instance: {
              title: 0,
            },
            storage: {},
          },
        },
        linkNoTitleEmpty: {
          sourceTypeSettings: {
            instance: {
              title: 0,
            },
            storage: {},
          },
        },
        entityReferenceAutocomplete: {},
      },
    },
  },
};
// This metadata is defined in PHP and is duplicated here to improve testability.
// ⚠️ This should be kept in sync! ⚠️
// @see \Drupal\canvas\Hook\ReduxIntegratedFieldWidgetsHooks::fieldWidgetInfoAlter()
// @see \Drupal\canvas\Hook\ReduxIntegratedFieldWidgetsHooks::mediaLibraryFieldWidgetInfoAlter()
const transformConfig = {
  heading: { mainProperty: {} },
  headingMultiple: { mainProperty: { multiple: true } },
  subheading: { mainProperty: {} },
  cta1: { mainProperty: {} },
  cta1href: { link: {} },
  linkNoTitle: { link: {} },
  linkNoTitleEmpty: { link: {} },
  entityReferenceAutocomplete: {
    mainProperty: { name: 'target_id' },
    entityAutocompleteTargetId: {},
  },
  cta2: { mainProperty: {} },
  textarea: { mainProperty: {} },
  number: { mainProperty: {} },
  float: { mainProperty: {} },
  email: { mainProperty: {} },
  a_boolean: {
    mainProperty: {},
  },
  unchecked_boolean: {
    mainProperty: {},
  },
  datetime: {
    mainProperty: {},
    dateTime: {},
  },
  date: {
    mainProperty: {},
    dateTime: {},
  },
  datetimeMultiple: {
    mainProperty: { multiple: true },
    dateTime: { multiple: true },
  },
  dateMultiple: {
    mainProperty: { multiple: true },
    dateTime: { multiple: true },
  },
  media: {
    mediaSelection: {},
    mainProperty: { name: 'target_id' },
  },
  mediaMultiple: {
    mediaSelection: { multiple: true },
    mainProperty: { name: 'target_id', multiple: true },
  },
};

describe('Form state to object', () => {
  it('Should transform flat structure into a nested object', () => {
    const asObject = formStateToObject(formState, 'all-props');
    expect(asObject).to.deep.equal({
      heading: [{ value: 'hello, world!' }],
      headingMultiple: [
        { value: 'hello, world!' },
        { value: 'goodbye, world!' },
      ],
      subheading: [{ value: '' }],
      cta1: [{ value: '' }],
      cta1href: [{ uri: 'https://drupal.org', title: 'Do it' }],
      cta2: [{ value: '' }],
      linkNoTitle: [{ uri: 'http://example.com' }],
      linkNoTitleEmpty: [{ uri: '' }],
      entityReferenceAutocomplete: [{ target_id: 'A node title (3)' }],
      a_boolean: { value: 'true' },
      unchecked_boolean: { value: 'false' },
      date: [
        {
          value: {
            date: '2025-02-02',
          },
        },
      ],
      datetime: [
        {
          value: {
            date: '2025-01-31',
            time: '20:30:33',
          },
        },
      ],
      dateMultiple: [
        {
          value: {
            date: '2025-02-02',
          },
        },
        {
          value: {
            date: '2025-02-03',
          },
        },
      ],
      datetimeMultiple: [
        {
          value: {
            date: '2025-01-31',
            time: '20:30:33',
          },
        },
        {
          value: {
            date: '2025-02-01',
            time: '20:30:35',
          },
        },
      ],
      options_select: 'fine thx',
      email: [{ value: 'bob@example.com' }],
      number: [{ value: '123' }],
      float: [{ value: '123.45' }],
      textarea: [
        {
          value: `Hi there
Multiline
Value`,
        },
      ],
      media: {
        selection: [{ target_id: '3' }],
      },
      mediaMultiple: {
        selection: [{ target_id: '3' }, { target_id: '4' }],
      },
      colors: ['green', 'yellow'],
      singleColor: ['green'],
      emptyColors: [],
    });
  });

  it('Should handle array values without [] suffix in key', () => {
    // Same test but with keys lacking the Drupal `[]` suffix, e.g. when
    // dispatched directly from JavaScript rather than server-rendered HTML.
    const formStateWithoutBrackets = {
      ...formState,
      'canvas_component_props[all-props][colors]': ['green', 'yellow'],
      'canvas_component_props[all-props][singleColor]': ['green'],
      'canvas_component_props[all-props][emptyColors]': [],
    };
    // Remove the []-suffixed keys.
    delete formStateWithoutBrackets[
      'canvas_component_props[all-props][colors][]'
    ];
    delete formStateWithoutBrackets[
      'canvas_component_props[all-props][singleColor][]'
    ];
    delete formStateWithoutBrackets[
      'canvas_component_props[all-props][emptyColors][]'
    ];
    const asObject = formStateToObject(formStateWithoutBrackets, 'all-props');
    expect(asObject.colors).to.deep.equal(['green', 'yellow']);
    expect(asObject.singleColor).to.deep.equal(['green']);
    expect(asObject.emptyColors).to.deep.equal([]);
  });
});

describe('Get prop values from form state', () => {
  it('Should transform values from form state', () => {
    const { propsValues } = getPropsValues(
      formState,
      inputAndUiData,
      transformConfig,
    );
    expect(propsValues).to.deep.equal({
      a_boolean: true,
      unchecked_boolean: false,
      heading: 'hello, world!',
      headingMultiple: ['hello, world!', 'goodbye, world!'],
      subheading: '',
      cta1: '',
      cta2: '',
      cta1href: { uri: 'https://drupal.org', title: 'Do it' },
      linkNoTitle: 'http://example.com',
      linkNoTitleEmpty: '',
      entityReferenceAutocomplete: '3',
      textarea: `Hi there
Multiline
Value`,
      email: 'bob@example.com',
      number: 123,
      float: 123.45,
      options_select: 'fine thx',
      date: '2025-02-02',
      datetime: '2025-01-31T20:30:33.000Z',
      dateMultiple: ['2025-02-02', '2025-02-03'],
      datetimeMultiple: [
        '2025-01-31T20:30:33.000Z',
        '2025-02-01T20:30:35.000Z',
      ],
      media: '3',
      mediaMultiple: ['3', '4'],
      colors: ['green', 'yellow'],
      singleColor: ['green'],
      emptyColors: [],
    });
  });

  it('Should transform multiple entity autocomplete values', () => {
    const multiCardinalityFormState = {
      ...formState,
      'canvas_component_props[all-props][entityReferenceAutocomplete][0][target_id]':
        'A 😎 node title (3), Another node title (42)',
    };

    const { propsValues } = getPropsValues(
      multiCardinalityFormState,
      inputAndUiData,
      transformConfig,
    );

    expect(propsValues.entityReferenceAutocomplete).to.deep.equal(['3', '42']);
  });

  it('Should pass through an already-extracted entity autocomplete id', () => {
    // The transform runs on every commit, so values that were extracted on a
    // prior pass (a bare id like '3') must round-trip unchanged rather than
    // being dropped. Without idempotency, picking a value once would never
    // patch the backend because the second pass turns the id into null and
    // drops the prop. @see entityAutocompleteTargetId in transforms.ts
    const alreadyExtractedFormState = {
      ...formState,
      'canvas_component_props[all-props][entityReferenceAutocomplete][0][target_id]':
        '3',
    };

    const { propsValues } = getPropsValues(
      alreadyExtractedFormState,
      inputAndUiData,
      transformConfig,
    );

    expect(propsValues.entityReferenceAutocomplete).to.equal('3');
  });

  it('Should truncate array props that exceed maxItems', () => {
    // The component should not fail to render if there are more items selected
    // This applies to both SDC and code components.
    const maxItemsFormState = {
      'canvas_component_props[all-props][tags][]': ['red', 'green', 'blue'],
      form_build_id: 'test-form-build-id',
      form_token: 'test-form-token',
      form_id: 'component_instance_form',
    };
    const maxItemsInputAndUiData = {
      version: '82b745980fd23b55',
      selectedComponent: 'all-props',
      selectedComponentType: 'sdc.sdc_test_all_props.all-props',
      layout: [],
      model: {
        'all-props': {
          resolved: {},
          source: {},
        },
      },
      components: {
        'sdc.sdc_test_all_props.all-props': {
          propSources: {
            tags: {
              jsonSchema: {
                type: 'array',
                items: {
                  type: 'string',
                  enum: ['red', 'green', 'blue', 'yellow'],
                },
                maxItems: 2,
              },
            },
          },
        },
      },
    };

    const { propsValues } = getPropsValues(
      maxItemsFormState,
      maxItemsInputAndUiData,
      {},
    );

    // Should be truncated to 2 items (maxItems: 2), not 3.
    expect(propsValues.tags).to.deep.equal(['red', 'green']);
  });
});

describe('coerceValueForSchema', () => {
  describe('pass-through (value unchanged)', () => {
    it('returns value as-is when schema is undefined', () => {
      expect(coerceValueForSchema('1', undefined)).to.equal('1');
      expect(coerceValueForSchema(1, undefined)).to.equal(1);
    });

    it('returns value as-is when schema has no type', () => {
      expect(coerceValueForSchema('1', {})).to.equal('1');
    });

    it('returns value as-is when schema type is not integer/number/boolean', () => {
      expect(coerceValueForSchema('hello', { type: 'string' })).to.equal(
        'hello',
      );
    });

    it('returns empty string unchanged (do not coerce empty string)', () => {
      expect(coerceValueForSchema('', { type: 'integer' })).to.equal('');
      expect(coerceValueForSchema('', { type: 'number' })).to.equal('');
    });
  });

  describe('string coercion', () => {
    it('coerces string to integer when schema type is integer', () => {
      expect(coerceValueForSchema('1', { type: 'integer' })).to.equal(1);
      expect(coerceValueForSchema('1.5', { type: 'integer' })).to.equal(1);
    });

    it('coerces string to number when schema type is number', () => {
      expect(coerceValueForSchema('1.5', { type: 'number' })).to.equal(1.5);
      expect(coerceValueForSchema('123.45', { type: 'number' })).to.equal(
        123.45,
      );
    });

    it('coerces string to boolean when schema type is boolean', () => {
      expect(coerceValueForSchema('true', { type: 'boolean' })).to.equal(true);
      expect(coerceValueForSchema('false', { type: 'boolean' })).to.equal(
        false,
      );
    });

    it('returns invalid string unchanged when coercion yields NaN', () => {
      expect(coerceValueForSchema('abc', { type: 'integer' })).to.equal('abc');
      expect(coerceValueForSchema('abc', { type: 'number' })).to.equal('abc');
    });
  });

  describe('backend-style: value is already a number (decimals/floats)', () => {
    it('passes through number when schema type is number', () => {
      expect(coerceValueForSchema(123.45, { type: 'number' })).to.equal(123.45);
      expect(coerceValueForSchema(1, { type: 'number' })).to.equal(1);
    });

    it('passes through integer when schema type is integer and value is number', () => {
      expect(coerceValueForSchema(1, { type: 'integer' })).to.equal(1);
    });

    it('passes through float unchanged when schema type is integer', () => {
      expect(coerceValueForSchema(1.5, { type: 'integer' })).to.equal(1.5);
    });

    it('passes through 1.0 when schema type is integer', () => {
      const result = coerceValueForSchema(1.0, { type: 'integer' });
      expect(result).to.equal(1);
    });
  });

  describe('value is boolean (pass-through)', () => {
    it('returns boolean unchanged when schema type is string', () => {
      expect(coerceValueForSchema(true, { type: 'string' })).to.equal(true);
      expect(coerceValueForSchema(false, { type: 'string' })).to.equal(false);
    });
  });
});

describe('validateProp', () => {
  const minimalInputAndUiData = {
    selectedComponent: 'test-component',
    selectedComponentType: 'test.type',
    layout: [],
    model: {},
    components: {
      'test.type': {
        propSources: {
          intProp: { jsonSchema: { type: 'integer' } },
          numProp: { jsonSchema: { type: 'number' } },
        },
      },
    },
  };

  it('fails when caller passes string for integer prop (caller must coerce)', () => {
    const [valid] = validateProp('intProp', '1', minimalInputAndUiData);
    expect(valid).to.equal(false);
  });

  it('passes when caller passes number for integer prop', () => {
    const [valid] = validateProp('intProp', 1, minimalInputAndUiData);
    expect(valid).to.equal(true);
  });

  it('passes when caller passes number (float) for number prop', () => {
    const [valid] = validateProp('numProp', 123.45, minimalInputAndUiData);
    expect(valid).to.equal(true);
  });

  it('fails when caller passes string for number prop (caller must coerce)', () => {
    const [valid] = validateProp('numProp', '123.45', minimalInputAndUiData);
    expect(valid).to.equal(false);
  });

  it('passes validation for array prop whose items schema contains meta:enum', () => {
    const inputAndUiData = {
      ...minimalInputAndUiData,
      components: {
        'test.type': {
          propSources: {
            colors: {
              jsonSchema: {
                type: 'array',
                items: {
                  type: 'string',
                  enum: ['red', 'green', 'blue'],
                  'meta:enum': { red: 'Red', green: 'Green', blue: 'Blue' },
                },
              },
            },
          },
        },
      },
    };
    const [valid] = validateProp('colors', ['red'], inputAndUiData);
    expect(valid).to.equal(true);
  });
});

describe('shouldSkipPropValidation', () => {
  const buildTargetInForm = (name, otherInputNames = []) => {
    const form = document.createElement('form');
    const input = document.createElement('input');
    input.name = name;
    form.appendChild(input);
    otherInputNames.forEach((extraName) => {
      const extra = document.createElement('input');
      extra.name = extraName;
      form.appendChild(extra);
    });
    return input;
  };

  it('skips validation for content-entity-reference props', () => {
    // Content entity reference props have a source shape that differs from
    // their resolved value and can't be validated client-side.
    // Server-side validation remains the source of truth.
    const target = buildTargetInForm(
      'canvas_component_props[comp1][author][0][target_id]',
    );
    const inputAndUiData = {
      selectedComponent: 'comp1',
      selectedComponentType: 'js.article',
      components: {
        'js.article': {
          propSources: {
            author: {
              jsonSchema: {
                type: 'object',
                id: 'json-schema-definitions://canvas.module/content-entity-reference',
              },
            },
          },
        },
      },
    };
    expect(
      shouldSkipPropValidation(target.name, target, inputAndUiData, '3'),
    ).to.equal(true);
  });

  it('does not skip validation for an unrelated single-input prop', () => {
    // Sanity check: the CER skip should not leak to other object props that
    // happen to live in the same code path.
    const target = buildTargetInForm(
      'canvas_component_props[comp1][title][0][value]',
    );
    const inputAndUiData = {
      selectedComponent: 'comp1',
      selectedComponentType: 'js.article',
      components: {
        'js.article': {
          propSources: {
            title: { jsonSchema: { type: 'string' } },
          },
        },
      },
    };
    expect(
      shouldSkipPropValidation(target.name, target, inputAndUiData, 'hello'),
    ).to.equal(false);
  });

  it('skips validation for image props (multiple inputs per single value)', () => {
    // Image props have several inputs (fids, alt, width, height) backing one
    // prop value. propInputData lifts them into multipleInputsSingleValue, so
    // shouldSkipPropValidation returns true regardless of the schema.
    const target = buildTargetInForm(
      'canvas_component_props[comp1][image][0][fids]',
      [
        'canvas_component_props[comp1][image][0][alt]',
        'canvas_component_props[comp1][image][0][width]',
        'canvas_component_props[comp1][image][0][height]',
      ],
    );
    const inputAndUiData = {
      selectedComponent: 'comp1',
      selectedComponentType: 'js.banner',
      components: {
        'js.banner': {
          propSources: {
            image: { jsonSchema: { type: 'object' } },
          },
        },
      },
    };
    expect(
      shouldSkipPropValidation(target.name, target, inputAndUiData, '5'),
    ).to.equal(true);
  });

  it('skips validation for video props (multiple inputs per single value)', () => {
    const target = buildTargetInForm(
      'canvas_component_props[comp1][video][0][fids]',
      ['canvas_component_props[comp1][video][0][poster]'],
    );
    const inputAndUiData = {
      selectedComponent: 'comp1',
      selectedComponentType: 'js.banner',
      components: {
        'js.banner': {
          propSources: {
            video: { jsonSchema: { type: 'object' } },
          },
        },
      },
    };
    expect(
      shouldSkipPropValidation(target.name, target, inputAndUiData, '7'),
    ).to.equal(true);
  });
});

describe('propInputData', () => {
  it('should produce clean prop names for single-value direct props', () => {
    const formStateWithDirectProp = {
      'canvas_component_props[comp1][size]': 'medium',
      form_build_id: 'test',
      form_token: 'test',
      form_id: 'component_instance_form',
    };
    const uiData = {
      selectedComponent: 'comp1',
      selectedComponentType: 'sdc.test.comp1',
      components: {
        'sdc.test.comp1': {
          propSources: {
            size: {
              jsonSchema: {
                type: 'string',
                enum: ['small', 'medium', 'large'],
              },
            },
          },
        },
      },
    };
    const { propsInThisForm } = propInputData(formStateWithDirectProp, uiData);
    expect(propsInThisForm).to.include('size');
    // Single value props should not have brackets.
    expect(propsInThisForm).to.not.include(']');
  });
});

describe('getCurrentValueFromProps', () => {
  it('should return false for an unchecked checkbox despite a truthy value attribute', () => {
    // An unchecked Drupal checkbox: #checked is false, but the checkbox
    // return value ("1") is present in attributes.value and #default_value.
    expect(
      getCurrentValueFromProps({
        attributes: { type: 'checkbox', value: '1' },
        element: { '#checked': false, '#default_value': 0 },
      }),
    ).to.equal(false);
  });

  it('should return false for an unchecked checkbox when #checked is absent', () => {
    expect(
      getCurrentValueFromProps({
        attributes: { type: 'checkbox', value: '1' },
        element: {},
      }),
    ).to.equal(false);
  });

  it('should return true for a checked checkbox', () => {
    expect(
      getCurrentValueFromProps({
        attributes: { type: 'checkbox', value: '1' },
        element: { '#checked': true, '#default_value': 1 },
      }),
    ).to.equal(true);
  });

  it('should return true for a checkbox with attributes.checked set', () => {
    expect(
      getCurrentValueFromProps({
        attributes: { type: 'checkbox', value: '1', checked: 'checked' },
      }),
    ).to.equal(true);
  });

  it('should return the value attribute for non-checkbox elements', () => {
    expect(
      getCurrentValueFromProps({
        attributes: { type: 'text' },
        element: { '#value': 'hello' },
      }),
    ).to.equal('hello');
  });
});
