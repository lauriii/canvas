import { getPropsValues } from '@/components/form/react-hook-form/fields/componentFormData';

describe('componentFormData - getPropsValues propSource fallback', () => {
  const selectedComponent = 'test-uuid';
  const selectedComponentType = 'sdc.test.date-time-example';

  const dateTimeFieldSource = {
    sourceType: 'static:field_item:datetime',
    sourceTypeSettings: {
      storage: { datetime_type: 'datetime' },
    },
  };

  const components = {
    [selectedComponentType]: {
      id: selectedComponentType,
      name: 'Date time example',
      library: 'elements',
      source: 'sdc',
      default_markup: '',
      css: '',
      js_header: '',
      js_footer: '',
      version: '1.0',
      broken: false,
      metadata: {},
      transforms: {},
      propSources: {
        date_time: {
          expression: '',
          sourceType: 'static:field_item:datetime',
          sourceTypeSettings: dateTimeFieldSource.sourceTypeSettings,
          default_values: { resolved: {}, source: {} },
        },
      },
    },
  };

  const transformConfig = {
    date_time: { dateTime: {} },
  };

  const formState = {
    [`canvas_component_props[${selectedComponent}][date_time][date]`]:
      '2026-01-15',
    [`canvas_component_props[${selectedComponent}][date_time][time]`]:
      '14:30:00',
  };

  it('does not drop the value when source[key] is missing', () => {
    // Simulates a freshly-evaluated model where `date_time` has an empty
    // default value and has not been saved yet, so the backend has
    // stripped it from `source`.
    const inputAndUiData = {
      selectedComponent,
      selectedComponentType,
      components,
      layout: [],
      version: '1.0',
      editorFrameContext: 'entity',
      model: {
        [selectedComponent]: {
          resolved: {},
          source: {},
        },
      },
    };

    const { propsValues } = getPropsValues(
      formState,
      inputAndUiData,
      transformConfig,
    );

    expect(propsValues.date_time).toBe('2026-01-15T14:30:00.000Z');
  });

  it('still works when source[key] is present', () => {
    const inputAndUiData = {
      selectedComponent,
      selectedComponentType,
      components,
      layout: [],
      version: '1.0',
      editorFrameContext: 'entity',
      model: {
        [selectedComponent]: {
          resolved: {},
          source: { date_time: dateTimeFieldSource },
        },
      },
    };

    const { propsValues } = getPropsValues(
      formState,
      inputAndUiData,
      transformConfig,
    );

    expect(propsValues.date_time).toBe('2026-01-15T14:30:00.000Z');
  });
});
