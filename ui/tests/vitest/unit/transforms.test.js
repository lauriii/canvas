import transforms from '@/utils/transforms';

describe('Transforms - link', () => {
  const fieldData = {
    sourceTypeSettings: {
      instance: {},
    },
  };

  it('Should return just a URI if title is disabled', () => {
    fieldData.sourceTypeSettings.instance.title = 0;
    expect(
      transforms.link([{ uri: 'https://example.com' }], {}, fieldData),
    ).toEqual('https://example.com');
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
});

describe('Transforms - dateTime', () => {
  it('should return null when propSource is undefined', () => {
    expect(transforms.dateTime({ date: '' }, {}, undefined)).to.equal(null);
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
