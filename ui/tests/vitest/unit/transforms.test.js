import transforms from '@/utils/transforms';

describe('Transforms - link', () => {
  const fieldData = {
    expression: '',
    sourceType: 'static:field_item:link',
    sourceTypeSettings: {
      instance: {
        title: 0,
      },
    },
  };

  it('Should return just a URI if title is disabled', () => {
    fieldData.sourceTypeSettings.instance.title = 0;
    expect(
      transforms.link([{ uri: 'https://example.com' }], {}, fieldData),
    ).toEqual('https://example.com');
  });

  it('Should support multiple values', () => {
    fieldData.sourceTypeSettings.instance.title = 0;
    expect(
      transforms.link(
        [
          { uri: 'https://example.com' },
          { uri: 'https://another.example.com' },
        ],
        { multiple: true },
        fieldData,
      ),
    ).toEqual(['https://example.com', 'https://another.example.com']);
  });

  it('Should return URI and title if title is enabled', () => {
    fieldData.sourceTypeSettings.instance.title = 2;
    expect(
      transforms.link(
        [{ uri: 'https://example.com', title: 'Click me' }],
        {},
        fieldData,
      ),
    ).toEqual({ uri: 'https://example.com', title: 'Click me' });
  });

  it('Should match on autocomplete, no title', () => {
    fieldData.sourceTypeSettings.instance.title = 0;
    expect(
      transforms.link([{ uri: 'A node title (3)' }], {}, fieldData),
    ).toEqual('entity:node/3');
  });

  it('Should match on autocomplete, with title', () => {
    fieldData.sourceTypeSettings.instance.title = 2;
    expect(
      transforms.link(
        [{ uri: 'A node title (3)', title: 'Click me' }],
        {},
        fieldData,
      ),
    ).toEqual({ uri: 'entity:node/3', title: 'Click me' });
  });

  it('Should return null for null value', () => {
    expect(transforms.link(null, {}, fieldData)).toEqual(null);
  });

  it('Should return null for undefined value', () => {
    expect(transforms.link(undefined, {}, fieldData)).toEqual(null);
  });

  it('Should return empty array for null value when multiple', () => {
    expect(transforms.link(null, { multiple: true }, fieldData)).toEqual([]);
  });

  it('Should return empty array for undefined value when multiple', () => {
    expect(transforms.link(undefined, { multiple: true }, fieldData)).toEqual(
      [],
    );
  });

  it('Should not alter an already-resolved entity:node URI without title', () => {
    fieldData.sourceTypeSettings.instance.title = 0;
    expect(transforms.link([{ uri: 'entity:node/42' }], {}, fieldData)).toEqual(
      'entity:node/42',
    );
  });

  it('Should not alter an already-resolved entity:node URI with title', () => {
    fieldData.sourceTypeSettings.instance.title = 2;
    expect(
      transforms.link(
        [{ uri: 'entity:node/42', title: 'Some page' }],
        {},
        fieldData,
      ),
    ).toEqual({ uri: 'entity:node/42', title: 'Some page' });
  });

  it('Should not alter an already-resolved entity:node URI in multiple mode', () => {
    fieldData.sourceTypeSettings.instance.title = 0;
    expect(
      transforms.link(
        [{ uri: 'entity:node/42' }, { uri: 'entity:node/99' }],
        { multiple: true },
        fieldData,
      ),
    ).toEqual(['entity:node/42', 'entity:node/99']);
  });

  it('Should handle a record with a null uri when title is enabled', () => {
    fieldData.sourceTypeSettings.instance.title = 2;
    expect(
      transforms.link([{ uri: null, title: 'Click me' }], {}, fieldData),
    ).toEqual({ uri: null, title: 'Click me' });
  });

  it('Should normalize weighted object payloads and sort by weight for multiple values', () => {
    fieldData.sourceTypeSettings.instance.title = 0;
    expect(
      transforms.link(
        {
          0: { uri: 'https://example.com/second', _weight: '1' },
          1: { uri: 'https://example.com/first', _weight: '0' },
          add_more: '',
        },
        { multiple: true },
        fieldData,
      ),
    ).toEqual(['https://example.com/first', 'https://example.com/second']);
  });

  it('Should keep object values for titled links and sort by weight', () => {
    fieldData.sourceTypeSettings.instance.title = 1;
    expect(
      transforms.link(
        {
          0: { uri: 'Content (42)', title: 'Second', _weight: '1' },
          1: { uri: '/first', title: 'First', _weight: '0' },
          add_more: '',
        },
        { multiple: true },
        fieldData,
      ),
    ).toEqual([
      { uri: '/first', title: 'First', _weight: '0' },
      { uri: 'entity:node/42', title: 'Second', _weight: '1' },
    ]);
  });

  it('Should preserve the position of a value when earlier rows are empty', () => {
    // Row 0 is empty, row 1 has a value. The result must keep a null
    // placeholder at index 0 so the backend renders the value in row 1,
    // not row 0.
    fieldData.sourceTypeSettings.instance.title = 0;
    expect(
      transforms.link(
        {
          0: { uri: '', _weight: '0' },
          1: { uri: 'https://example.com', _weight: '1' },
          2: { uri: '', _weight: '2' },
        },
        { multiple: true },
        fieldData,
      ),
    ).toEqual([null, 'https://example.com']);
  });

  it('Should trim trailing nulls but keep leading/middle nulls', () => {
    // Rows 0 and 1 are empty, row 2 has a value.
    fieldData.sourceTypeSettings.instance.title = 0;
    expect(
      transforms.link(
        {
          0: { uri: '', _weight: '0' },
          1: { uri: '', _weight: '1' },
          2: { uri: 'https://example.com', _weight: '2' },
        },
        { multiple: true },
        fieldData,
      ),
    ).toEqual([null, null, 'https://example.com']);
  });

  it('Should return an empty array when all rows are empty', () => {
    fieldData.sourceTypeSettings.instance.title = 0;
    expect(
      transforms.link(
        {
          0: { uri: '', _weight: '0' },
          1: { uri: '', _weight: '1' },
        },
        { multiple: true },
        fieldData,
      ),
    ).toEqual([]);
  });

  it('Should trim trailing empty rows when the first row is filled', () => {
    fieldData.sourceTypeSettings.instance.title = 0;
    expect(
      transforms.link(
        {
          0: { uri: 'https://example.com/first', _weight: '0' },
          1: { uri: '', _weight: '1' },
          2: { uri: '', _weight: '2' },
        },
        { multiple: true },
        fieldData,
      ),
    ).toEqual(['https://example.com/first']);
  });
});

describe('Transforms - main property', () => {
  const fieldData = {
    expression: '',
    sourceType: 'static:field_item:link',
    sourceTypeSettings: {
      instance: {},
    },
  };

  it('Should return just the main property', () => {
    expect(
      transforms.mainProperty(
        [{ uri: 'https://example.com' }],
        { name: 'uri' },
        fieldData,
      ),
    ).toEqual('https://example.com');
  });

  it('Should allow for non-list checkboxes', () => {
    expect(
      transforms.mainProperty({ value: true }, { name: 'value' }, fieldData),
    ).toEqual(true);
  });

  it('Should work with multi-value fields', () => {
    expect(
      transforms.mainProperty(
        [{ value: 'because' }, { value: 'you' }, { value: 'asked' }],
        { name: 'value', multiple: true },
        fieldData,
      ),
    ).toEqual(['because', 'you', 'asked']);
  });

  it('Should handle null values for multi-value fields', () => {
    expect(
      transforms.mainProperty(
        null,
        { name: 'value', multiple: true },
        fieldData,
      ),
    ).toEqual([null]);
  });

  it('Should handle undefined values for multi-value fields', () => {
    expect(
      transforms.mainProperty(
        undefined,
        { name: 'value', multiple: true },
        fieldData,
      ),
    ).toEqual([null]);
  });

  it('Should sort by field item weight when present', () => {
    expect(
      transforms.mainProperty(
        {
          0: { target_id: '3', weight: '0' },
          1: { target_id: '4', weight: '1' },
          2: { target_id: '5', weight: '2' },
        },
        { name: 'target_id', multiple: true },
        fieldData,
      ),
    ).toEqual(['3', '4', '5']);
  });

  it('Should sort by field item weight after reordering', () => {
    expect(
      transforms.mainProperty(
        {
          0: { target_id: '5', weight: '0' },
          1: { target_id: '3', weight: '1' },
          2: { target_id: '4', weight: '2' },
        },
        { name: 'target_id', multiple: true },
        fieldData,
      ),
    ).toEqual(['5', '3', '4']);
  });

  it('Should sort field items by `_weight` when `weight` is not present', () => {
    expect(
      transforms.mainProperty(
        {
          0: { target_id: '4', _weight: '0' },
          1: { target_id: '3', _weight: '1' },
        },
        { name: 'target_id', multiple: true },
        fieldData,
      ),
    ).toEqual(['4', '3']);
  });

  it('Should handle numeric weight values', () => {
    expect(
      transforms.mainProperty(
        {
          0: { target_id: '3', weight: 1 },
          1: { target_id: '4', weight: 0 },
        },
        { name: 'target_id', multiple: true },
        fieldData,
      ),
    ).toEqual(['4', '3']);
  });

  it('Should filter out empty strings from multi-value fields', () => {
    expect(
      transforms.mainProperty(
        { 0: { value: 'tag1' }, 1: { value: '' }, 2: { value: 'tag3' } },
        { name: 'value', multiple: true },
        fieldData,
      ),
    ).toEqual(['tag1', 'tag3']);
  });

  it('Should return empty array when all values are empty strings', () => {
    expect(
      transforms.mainProperty(
        { 0: { value: '' } },
        { name: 'value', multiple: true },
        fieldData,
      ),
    ).toEqual([]);
  });
});

describe('Transforms - mediaSelection', () => {
  it('Should return null when there is no selection key', () => {
    expect(transforms.mediaSelection({}, { multiple: false })).toEqual(null);
  });

  it('Should return null when selection is empty', () => {
    expect(
      transforms.mediaSelection({ selection: {} }, { multiple: false }),
    ).toEqual(null);
  });

  it('Should return the first selection item for single-value fields', () => {
    expect(
      transforms.mediaSelection(
        { selection: { 0: { target_id: '42', weight: '0' } } },
        { multiple: false },
      ),
    ).toEqual({ target_id: '42', weight: '0' });
  });

  it('Should return an array of selection items for multiple-value fields', () => {
    expect(
      transforms.mediaSelection(
        {
          selection: {
            0: { target_id: '42', weight: '0' },
            1: { target_id: '43', weight: '1' },
          },
        },
        { multiple: true },
      ),
    ).toEqual([
      { target_id: '42', weight: '0' },
      { target_id: '43', weight: '1' },
    ]);
  });

  it('Should chain with mainProperty to extract target_id for single-value fields', () => {
    const intermediate = transforms.mediaSelection(
      { selection: { 0: { target_id: '42', weight: '0' } } },
      { multiple: false },
    );
    expect(
      transforms.mainProperty(intermediate, {
        name: 'target_id',
        multiple: false,
      }),
    ).toEqual('42');
  });

  it('Should chain with mainProperty to extract target_ids for multiple-value fields', () => {
    const intermediate = transforms.mediaSelection(
      {
        selection: {
          0: { target_id: '42', weight: '0' },
          1: { target_id: '43', weight: '1' },
        },
      },
      { multiple: true },
    );
    expect(
      transforms.mainProperty(intermediate, {
        name: 'target_id',
        multiple: true,
      }),
    ).toEqual(['42', '43']);
  });
});

describe('Transforms - firstRecord', () => {
  it('Should return null for null input', () => {
    expect(transforms.firstRecord(null, {}, {})).toEqual(null);
  });

  it('Should return null for an empty array', () => {
    expect(transforms.firstRecord([], {}, {})).toEqual(null);
  });

  it('Should return the value as-is for a non-array input', () => {
    expect(transforms.firstRecord('hello', {}, {})).toEqual('hello');
  });

  it('Should return a single item from a one-element array', () => {
    expect(transforms.firstRecord([{ value: 'only' }], {}, {})).toEqual({
      value: 'only',
    });
  });

  it('Should return a single item from a multi-element array', () => {
    expect(
      transforms.firstRecord(
        [
          { value: 'first' },
          { value: 'second' },
          { value: 'third' },
          { value: 'fourth' },
        ],
        {},
        {},
      ),
    ).toEqual({ value: 'first' });
  });
});

describe('Transforms - dateTime', () => {
  const datePropSource = {
    sourceType: 'static:field_item:datetime',
    sourceTypeSettings: {
      storage: { datetime_type: 'date' },
    },
  };

  it('should return null when propSource is undefined', () => {
    expect(transforms.dateTime({ date: '' }, {}, undefined)).to.equal(null);
  });

  it('should preserve the position of a value when earlier rows are empty', () => {
    // Row 0 is empty, row 1 has a value. The result must keep a null
    // placeholder at index 0 so the backend renders the value in row 1,
    // not row 0.
    expect(
      transforms.dateTime(
        [
          { date: '', time: '' },
          { date: '2024-06-01', time: '' },
          { date: '', time: '' },
        ],
        { type: 'date', multiple: true },
        datePropSource,
      ),
    ).toEqual([null, '2024-06-01']);
  });

  it('should trim trailing nulls but keep leading/middle nulls', () => {
    // Rows 0 and 1 are empty, row 2 has a value.
    expect(
      transforms.dateTime(
        [
          { date: '', time: '' },
          { date: '', time: '' },
          { date: '2024-06-01', time: '' },
        ],
        { type: 'date', multiple: true },
        datePropSource,
      ),
    ).toEqual([null, null, '2024-06-01']);
  });

  it('should return an empty array when all rows are empty', () => {
    expect(
      transforms.dateTime(
        [
          { date: '', time: '' },
          { date: '', time: '' },
        ],
        { type: 'date', multiple: true },
        datePropSource,
      ),
    ).toEqual([]);
  });

  it('should trim trailing empty rows when the first row is filled', () => {
    expect(
      transforms.dateTime(
        [
          { date: '2024-01-01', time: '' },
          { date: '', time: '' },
          { date: '', time: '' },
        ],
        { type: 'date', multiple: true },
        datePropSource,
      ),
    ).toEqual(['2024-01-01']);
  });
});

describe('Transforms - dateRange', () => {
  const fieldData = {
    sourceTypeSettings: {
      storage: {
        datetime_type: 'date',
      },
    },
  };

  it('should return null when propSource is undefined', () => {
    expect(
      transforms.dateRange(
        [{ value: { date: '' }, end_value: { date: '' } }],
        {},
        undefined,
      ),
    ).to.equal(null);
  });

  it('should map date range form values to value/end_value', () => {
    expect(
      transforms.dateRange(
        [
          {
            value: { date: '2026-05-02' },
            end_value: { date: '2026-06-02' },
          },
        ],
        {},
        fieldData,
      ),
    ).to.deep.equal({
      value: '2026-05-02',
      end_value: '2026-06-02',
    });
  });

  it('should map datetime range form values to UTC ISO date strings', () => {
    const dateTimeFieldData = {
      sourceTypeSettings: {
        storage: {
          datetime_type: 'datetime',
        },
      },
    };

    expect(
      transforms.dateRange(
        [
          {
            value: { date: '2026-05-02', time: '07:21:35' },
            end_value: { date: '2026-06-02', time: '09:45:12' },
          },
        ],
        {},
        dateTimeFieldData,
      ),
    ).to.deep.equal({
      value: '2026-05-02T07:21:35.000Z',
      end_value: '2026-06-02T09:45:12.000Z',
    });
  });
});
