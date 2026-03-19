import {
  coerceValueForSchema,
  formStateToObject,
  getPropSchemas,
  getPropsValues,
  validateProp,
} from '@/components/form/formUtil';

let formState = {
  'canvas_component_props[all-props][heading][0][value]': 'hello, world!',
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
  'canvas_component_props[all-props][email][0][value]': 'bob@example.com',
  'canvas_component_props[all-props][number][0][value]': 123,
  'canvas_component_props[all-props][float][0][value]': 123.45,
  'canvas_component_props[all-props][textarea][0][value]': `Hi there
Multiline
Value`,
  'canvas_component_props[all-props][linkNoTitle][0][uri]':
    'http://example.com',
  'canvas_component_props[all-props][linkNoTitleEmpty][0][uri]': '',
  'canvas_component_props[all-props][media][selection][0][target_id]': 3,
  form_build_id: 'this-is-a-form-build-id',
  form_token: 'this-is-a-form-token',
  form_id: 'component_instance_form',
};
let inputAndUiData = {
  selectedComponent: 'all-props',
  selectedComponentType: 'sdc.sdc_test_all_props.all-props',
  layout: [],
  model: {
    'all-props': {
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
        date: {
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
  subheading: { mainProperty: {} },
  cta1: { mainProperty: {} },
  cta1href: { link: {} },
  linkNoTitle: { link: {} },
  linkNoTitleEmpty: { link: {} },
  cta2: { mainProperty: {} },
  textarea: { mainProperty: {} },
  number: { mainProperty: {} },
  float: { mainProperty: {} },
  email: { mainProperty: {} },
  a_boolean: {
    mainProperty: { list: false },
  },
  unchecked_boolean: {
    mainProperty: { list: false },
  },
  datetime: {
    mainProperty: {},
    dateTime: {},
  },
  date: {
    mainProperty: {},
    dateTime: {},
  },
  media: {
    mediaSelection: {},
    mainProperty: { name: 'target_id' },
  },
};

describe('Form state to object', () => {
  it('Should transform flat structure into a nested object', () => {
    const asObject = formStateToObject(formState, 'all-props');
    expect(asObject).to.deep.equal({
      heading: [{ value: 'hello, world!' }],
      subheading: [{ value: '' }],
      cta1: [{ value: '' }],
      cta1href: [{ uri: 'https://drupal.org', title: 'Do it' }],
      cta2: [{ value: '' }],
      linkNoTitle: [{ uri: 'http://example.com' }],
      linkNoTitleEmpty: [{ uri: '' }],
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
    });
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
      subheading: '',
      cta1: '',
      cta2: '',
      cta1href: { uri: 'https://drupal.org', title: 'Do it' },
      linkNoTitle: 'http://example.com',
      linkNoTitleEmpty: '',
      textarea: `Hi there
Multiline
Value`,
      email: 'bob@example.com',
      number: 123,
      float: 123.45,
      options_select: 'fine thx',
      date: '2025-02-02',
      datetime: '2025-01-31T20:30:33.000Z',
      media: '3',
    });
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
});
