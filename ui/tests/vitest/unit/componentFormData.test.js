import { getPropsValues } from '@/components/form/react-hook-form/fields/componentFormData';
import { createComponentFormStateHandler } from '@/components/form/react-hook-form/fields/componentFormHandlers';

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

describe('createComponentFormStateHandler - form rendering a subset of props', () => {
  const selectedComponent = 'test-uuid';
  const selectedComponentType = 'sdc.test.image-feature';

  // The stored input of a media prop is the media ID. The resolved value the
  // server echoes back is the evaluated image object.
  const imageSource = {
    sourceType: 'static:field_item:entity_reference',
    expression:
      'ℹ︎entity_reference␟entity␜␜entity:media:image␝field_media_image',
    value: 3,
  };
  const evaluatedImage = { src: '/files/photo.jpg', alt: 'A photo' };
  const eyebrowSource = {
    sourceType: 'static:field_item:string',
    expression: 'ℹ︎string␟value',
  };

  const component = {
    id: selectedComponentType,
    propSources: {
      image: {
        ...imageSource,
        jsonSchema: { type: 'object' },
        default_values: { resolved: {}, source: {} },
      },
      eyebrow: {
        ...eyebrowSource,
        jsonSchema: { type: 'string' },
        default_values: { resolved: {}, source: {} },
      },
    },
  };

  it('keeps the stored source of props that are not in the form state', () => {
    const patchComponent = vi.fn();
    const inputAndUiData = {
      selectedComponent,
      selectedComponentType,
      components: { [selectedComponentType]: component },
      layout: [],
      version: '1.0',
      editorFrameContext: 'entity',
      model: {
        [selectedComponent]: {
          resolved: { image: evaluatedImage, eyebrow: 'Old' },
          source: { image: imageSource, eyebrow: eyebrowSource },
        },
      },
    };
    const handler = createComponentFormStateHandler({
      dispatch: vi.fn(),
      transforms: { eyebrow: { mainProperty: {} } },
      selectedComponentType,
      selectedComponent,
      propName: 'eyebrow',
      isScalarProp: false,
      polledBackgroundUpdate: { current: null },
      component,
      patchComponent,
      editorFrameContext: 'entity',
      version: '1.0',
    });

    // An escape-hatch island renders `eyebrow` only: `image` is edited by a
    // native widget and never enters the form state.
    handler(
      {
        [`canvas_component_props[${selectedComponent}][eyebrow][0][value]`]:
          'New',
      },
      inputAndUiData,
      {},
    );

    expect(patchComponent).toHaveBeenCalledTimes(1);
    const { source, resolved } = patchComponent.mock.calls[0][1];
    expect(resolved.eyebrow).toBe('New');
    expect(source.eyebrow.value).toBe('New');
    expect(source.image).toEqual(imageSource);
  });
});
