import {
  getPropsValues,
  staleGroupItemNames,
} from '@/components/form/react-hook-form/fields/componentFormData';

describe('componentFormData - staleGroupItemNames', () => {
  const name = (key) => `canvas_component_props[test-uuid][${key}]`;

  it('finds the form state of items a rebuilt list no longer has', () => {
    const formStateNames = [
      name('title'),
      name('authors.0.name][0][value'),
      name('authors.0._weight'),
      name('authors.1.name][0][value'),
      name('authors.1._weight'),
      name('authors.10.name][0][value'),
      name('authors.10._weight'),
      name('ingredient.name][0][value'),
      name('links.0.label][0][value'),
      'form_build_id',
    ];
    // The server rebuilt the authors list without item 1.
    const rebuiltNames = [
      name('authors.10._weight'),
      name('authors.10.name][0][value'),
      name('authors.0._weight'),
      name('authors.0.name][0][value'),
      'authors_object_props_add_more',
    ];
    expect(staleGroupItemNames(formStateNames, rebuiltNames)).toEqual([
      name('authors.1.name][0][value'),
      name('authors.1._weight'),
    ]);
  });

  it('finds nothing when no item list was rebuilt', () => {
    expect(
      staleGroupItemNames(
        [name('authors.0.name][0][value'), name('authors.0._weight')],
        [name('title')],
      ),
    ).toEqual([]);
  });
});

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

describe('componentFormData - getPropsValues empty required object prop', () => {
  const selectedComponent = 'test-uuid';
  const selectedComponentType = 'sdc.test.object-props';
  const component = (defaultValues) => ({
    [selectedComponentType]: {
      id: selectedComponentType,
      source: 'sdc',
      propSources: {
        video: {
          expression: '',
          sourceType: 'static:field_item:canvas_video_embed',
          required: true,
          jsonSchema: { type: 'object' },
          default_values: defaultValues,
        },
      },
    },
  });
  const getVideo = (defaultValues) =>
    getPropsValues(
      { [`canvas_component_props[${selectedComponent}][video][0][value]`]: '' },
      {
        selectedComponent,
        selectedComponentType,
        components: component(defaultValues),
        layout: [],
        version: '1.0',
        model: { [selectedComponent]: { resolved: {}, source: {} } },
      },
      { video: { mainProperty: {} } },
    ).propsValues.video;

  it('falls back to the default source value of a single-value widget', () => {
    expect(
      getVideo({
        source: [{ value: 'https://www.youtube.com/watch?v=aqz-KE-bpKQ' }],
        resolved: { src: 'https://example.com/media/oembed?url=…' },
      }),
    ).toBe('https://www.youtube.com/watch?v=aqz-KE-bpKQ');
  });

  it('falls back to the resolved example otherwise', () => {
    const resolved = { src: '/image.png', alt: 'Example' };
    expect(getVideo({ source: [], resolved })).toEqual(resolved);
    expect(
      getVideo({
        source: [{ value: '2026-01-01', end_value: '2026-01-02' }],
        resolved,
      }),
    ).toEqual(resolved);
  });
});
