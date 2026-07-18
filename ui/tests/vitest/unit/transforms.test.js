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

  it('Should strip empty rows surrounding a filled row', () => {
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
    ).toEqual(['https://example.com']);
  });

  it('Should strip leading empty rows', () => {
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
    ).toEqual(['https://example.com']);
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

  it('Should strip trailing empty rows when the first row is filled', () => {
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

  it('Should strip empty rows between filled rows', () => {
    fieldData.sourceTypeSettings.instance.title = 0;
    expect(
      transforms.link(
        {
          0: { uri: 'https://example.com/first', _weight: '0' },
          1: { uri: '', _weight: '1' },
          2: { uri: 'https://example.com/third', _weight: '2' },
        },
        { multiple: true },
        fieldData,
      ),
    ).toEqual(['https://example.com/first', 'https://example.com/third']);
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

describe('Transforms - entityAutocompleteTargetId', () => {
  it('Should return target ID from single autocomplete value', () => {
    expect(transforms.entityAutocompleteTargetId('A node title (3)')).to.equal(
      '3',
    );
  });

  it('Should return target IDs from multiple autocomplete values', () => {
    expect(
      transforms.entityAutocompleteTargetId(
        'A node title (3), Another node title (42)',
      ),
    ).to.deep.equal(['3', '42']);
  });

  it('Should parse emoji titles from autocomplete values', () => {
    expect(
      transforms.entityAutocompleteTargetId('😎 A node title (7)'),
    ).to.equal('7');
  });

  it('Should handle a trailing comma in single-value tags input', () => {
    expect(
      transforms.entityAutocompleteTargetId('A node title (3), '),
    ).to.equal('3');
  });

  it('Should map array values when given array input', () => {
    expect(
      transforms.entityAutocompleteTargetId([
        'A node title (3)',
        'Another node title (42)',
      ]),
    ).to.deep.equal(['3', '42']);
  });

  it('Should pass through an already-extracted target ID unchanged', () => {
    // The transform is re-applied on every commit; the second pass receives
    // the previously-extracted id (e.g. '3'), and must return it unchanged so
    // the value is not destroyed. Mirrors the pass-through behavior of
    // \Drupal\Core\Entity\Element\EntityAutocomplete::extractEntityIdFromAutocompleteInput.
    expect(transforms.entityAutocompleteTargetId('3')).to.equal('3');
    expect(transforms.entityAutocompleteTargetId('A node title')).to.equal(
      'A node title',
    );
  });

  it('Should be idempotent for single values', () => {
    const first = transforms.entityAutocompleteTargetId('A node title (3)');
    expect(first).to.equal('3');
    expect(transforms.entityAutocompleteTargetId(first)).to.equal('3');
  });

  it('Should be idempotent for array values', () => {
    const first = transforms.entityAutocompleteTargetId([
      'A node title (3)',
      'Another node title (42)',
    ]);
    expect(first).to.deep.equal(['3', '42']);
    expect(transforms.entityAutocompleteTargetId(first)).to.deep.equal([
      '3',
      '42',
    ]);
  });

  it('Should pass through bare ids in an array', () => {
    expect(transforms.entityAutocompleteTargetId(['3', '42'])).to.deep.equal([
      '3',
      '42',
    ]);
  });

  it('Should accept arrays mixing bare ids and "Label (id)" values', () => {
    expect(
      transforms.entityAutocompleteTargetId(['A node title (3)', '42']),
    ).to.deep.equal(['3', '42']);
  });

  it('Should return null for null input', () => {
    expect(transforms.entityAutocompleteTargetId(null)).to.equal(null);
  });

  it('Should return null for an empty string', () => {
    expect(transforms.entityAutocompleteTargetId('')).to.equal(null);
  });

  it('Should keep an internal comma inside a quoted label', () => {
    // Mirrors Drupal.autocomplete.splitValues quote handling.
    expect(
      transforms.entityAutocompleteTargetId('"Hello, world" (3)'),
    ).to.equal('3');
  });

  it('Should split tags around quoted labels containing commas', () => {
    expect(
      transforms.entityAutocompleteTargetId('"Hello, world" (3), Another (5)'),
    ).to.deep.equal(['3', '5']);
  });

  it('Should keep legitimate ids when a tags-mode entry is unparseable', () => {
    // Unparseable entries are treated as bare ids (idempotent pass-through);
    // server-side validation surfaces the actual error.
    expect(
      transforms.entityAutocompleteTargetId('A node title (3), invalid'),
    ).to.deep.equal(['3', 'invalid']);
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

  it('should strip empty rows surrounding a filled row', () => {
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
    ).toEqual(['2024-06-01']);
  });

  it('should strip all empty rows including leading ones', () => {
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
    ).toEqual(['2024-06-01']);
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

  it('should strip trailing empty rows when the first row is filled', () => {
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

  it('should strip empty rows between filled rows', () => {
    expect(
      transforms.dateTime(
        [
          { date: '2024-01-01', time: '' },
          { date: '', time: '' },
          { date: '2024-06-01', time: '' },
        ],
        { type: 'date', multiple: true },
        datePropSource,
      ),
    ).toEqual(['2024-01-01', '2024-06-01']);
  });
});

describe('Transforms - dateTime (datetime type)', () => {
  const dateTimePropSource = {
    sourceType: 'static:field_item:datetime',
    sourceTypeSettings: {
      storage: { datetime_type: 'datetime' },
    },
  };

  // Selecting a date while leaving the time empty used to build an unparseable
  // date and throw "RangeError: Invalid time value"; the empty time now falls
  // back to noon UTC.
  // @see https://www.drupal.org/project/canvas/issues/3573426
  it('should map a selected date with an empty time to noon UTC without throwing', () => {
    expect(() =>
      transforms.dateTime(
        { date: '2026-02-13', time: '' },
        { type: 'datetime' },
        dateTimePropSource,
      ),
    ).not.toThrow();
    expect(
      transforms.dateTime(
        { date: '2026-02-13', time: '' },
        { type: 'datetime' },
        dateTimePropSource,
      ),
    ).toEqual('2026-02-13T12:00:00.000Z');
  });

  it('should map a full datetime record to a UTC ISO string', () => {
    expect(
      transforms.dateTime(
        { date: '2026-02-13', time: '10:30:00' },
        { type: 'datetime' },
        dateTimePropSource,
      ),
    ).toEqual('2026-02-13T10:30:00.000Z');
  });

  // A present time with a missing date also produced an Invalid Date; it must
  // degrade to null rather than throw.
  it('should return null for a missing date rather than throwing', () => {
    expect(() =>
      transforms.dateTime(
        { date: '', time: '10:30:00' },
        { type: 'datetime' },
        dateTimePropSource,
      ),
    ).not.toThrow();
    expect(
      transforms.dateTime(
        { date: '', time: '10:30:00' },
        { type: 'datetime' },
        dateTimePropSource,
      ),
    ).toEqual(null);
    expect(
      transforms.dateTime(
        { date: '', time: '' },
        { type: 'datetime' },
        dateTimePropSource,
      ),
    ).toEqual(null);
  });

  it('should drop empty datetime rows in multiple mode', () => {
    expect(
      transforms.dateTime(
        [
          { date: '', time: '' },
          { date: '2026-02-13', time: '10:30:00' },
          { date: '', time: '' },
        ],
        { type: 'datetime', multiple: true },
        dateTimePropSource,
      ),
    ).toEqual(['2026-02-13T10:30:00.000Z']);
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
