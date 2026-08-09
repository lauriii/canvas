import { describe, expect, it } from 'vitest';

import { NodeType } from './layoutModelSlice';
import {
  collectSimulationInputs,
  evaluateSegmentForVisitor,
  resolveVisitorVariants,
} from './simulateVisitor';

import type {
  DayOfWeekCondition,
  GeolocationCondition,
  QueryParameterCondition,
  Segment,
  SegmentRules,
  UtmParametersCondition,
} from '@/types/Personalization';
import type {
  ComponentModels,
  ComponentNode,
  RegionNode,
} from './layoutModelSlice';
import type { SimulatedVisitor } from './simulateVisitor';

const visitor = (
  overrides: Partial<SimulatedVisitor> = {},
): SimulatedVisitor => ({ query: {}, ...overrides });

const makeSegment = (
  id: string,
  rules?: SegmentRules,
  status = true,
): Segment => ({ id, label: id, status, weight: 0, rules });

const queryRule = (
  overrides: Partial<QueryParameterCondition> = {},
): QueryParameterCondition => ({
  id: 'query_parameter',
  negate: false,
  parameter: 'coupon',
  value: 'WEEKEND',
  matching: 'exact',
  ...overrides,
});

const utmRule = (
  overrides: Partial<UtmParametersCondition> = {},
): UtmParametersCondition => ({
  id: 'utm_parameters',
  negate: false,
  all: true,
  parameters: [],
  ...overrides,
});

const geoRule = (
  overrides: Partial<GeolocationCondition> = {},
): GeolocationCondition => ({
  id: 'geolocation',
  negate: false,
  countries: ['BE'],
  ...overrides,
});

const dayRule = (
  overrides: Partial<DayOfWeekCondition> = {},
): DayOfWeekCondition => ({
  id: 'day_of_week',
  negate: false,
  days: ['saturday', 'sunday'],
  ...overrides,
});

const evaluateRules = (rules: SegmentRules, who: SimulatedVisitor): boolean =>
  evaluateSegmentForVisitor(makeSegment('s', rules), who);

describe('evaluateSegmentForVisitor', () => {
  describe('segment-level semantics', () => {
    it('never matches a missing segment', () => {
      expect(evaluateSegmentForVisitor(undefined, visitor())).toBe(false);
    });

    it('never matches a disabled segment, even without rules', () => {
      expect(
        evaluateSegmentForVisitor(
          makeSegment('s', undefined, false),
          visitor(),
        ),
      ).toBe(false);
      expect(
        evaluateSegmentForVisitor(
          makeSegment('s', { query_parameter: queryRule() }, false),
          visitor({ query: { coupon: 'WEEKEND' } }),
        ),
      ).toBe(false);
    });

    it('matches everyone when the segment has zero rules', () => {
      expect(evaluateSegmentForVisitor(makeSegment('s'), visitor())).toBe(true);
      expect(evaluateSegmentForVisitor(makeSegment('s', {}), visitor())).toBe(
        true,
      );
    });

    it('requires every rule to match', () => {
      const rules: SegmentRules = {
        query_parameter: queryRule(),
        geolocation: geoRule(),
      };
      const matchingVisitor = visitor({
        query: { coupon: 'WEEKEND' },
        country: 'BE',
      });
      expect(evaluateRules(rules, matchingVisitor)).toBe(true);
      expect(
        evaluateRules(rules, visitor({ query: { coupon: 'WEEKEND' } })),
      ).toBe(false);
      expect(evaluateRules(rules, visitor({ country: 'BE' }))).toBe(false);
    });
  });

  describe('query_parameter', () => {
    it('compares exactly', () => {
      const rules: SegmentRules = { query_parameter: queryRule() };
      expect(
        evaluateRules(rules, visitor({ query: { coupon: 'WEEKEND' } })),
      ).toBe(true);
      expect(
        evaluateRules(rules, visitor({ query: { coupon: 'WEEKDAY' } })),
      ).toBe(false);
    });

    it('compares by prefix', () => {
      const rules: SegmentRules = {
        query_parameter: queryRule({ matching: 'starts_with', value: 'WEEK' }),
      };
      expect(
        evaluateRules(rules, visitor({ query: { coupon: 'WEEKEND' } })),
      ).toBe(true);
      expect(
        evaluateRules(rules, visitor({ query: { coupon: 'DAILY' } })),
      ).toBe(false);
    });

    it('matches presence, including an empty value', () => {
      const rules: SegmentRules = {
        query_parameter: queryRule({ matching: 'present' }),
      };
      expect(
        evaluateRules(rules, visitor({ query: { coupon: 'anything' } })),
      ).toBe(true);
      expect(evaluateRules(rules, visitor({ query: { coupon: '' } }))).toBe(
        true,
      );
    });

    it('never matches an absent parameter, in any matching mode', () => {
      const other = visitor({ query: { other: 'WEEKEND' } });
      expect(evaluateRules({ query_parameter: queryRule() }, other)).toBe(
        false,
      );
      expect(
        evaluateRules(
          { query_parameter: queryRule({ matching: 'starts_with' }) },
          other,
        ),
      ).toBe(false);
      expect(
        evaluateRules(
          { query_parameter: queryRule({ matching: 'present' }) },
          other,
        ),
      ).toBe(false);
    });

    it('inverts with negate, including for absent parameters', () => {
      const rules: SegmentRules = {
        query_parameter: queryRule({ negate: true }),
      };
      expect(
        evaluateRules(rules, visitor({ query: { coupon: 'WEEKEND' } })),
      ).toBe(false);
      expect(
        evaluateRules(rules, visitor({ query: { coupon: 'WEEKDAY' } })),
      ).toBe(true);
      expect(evaluateRules(rules, visitor())).toBe(true);
    });
  });

  describe('utm_parameters', () => {
    const source = {
      key: 'utm_source',
      value: 'news',
      matching: 'exact',
    } as const;
    const campaign = {
      key: 'utm_campaign',
      value: 'summer',
      matching: 'starts_with',
    } as const;

    it('matches everyone when no parameters are configured', () => {
      expect(evaluateRules({ utm_parameters: utmRule() }, visitor())).toBe(
        true,
      );
      expect(
        evaluateRules({ utm_parameters: utmRule({ negate: true }) }, visitor()),
      ).toBe(false);
    });

    it('requires every parameter when all is true', () => {
      const rules: SegmentRules = {
        utm_parameters: utmRule({ parameters: [source, campaign] }),
      };
      expect(
        evaluateRules(
          rules,
          visitor({ query: { utm_source: 'news', utm_campaign: 'summer24' } }),
        ),
      ).toBe(true);
      expect(
        evaluateRules(rules, visitor({ query: { utm_source: 'news' } })),
      ).toBe(false);
    });

    it('accepts any single parameter when all is false', () => {
      const rules: SegmentRules = {
        utm_parameters: utmRule({ all: false, parameters: [source, campaign] }),
      };
      expect(
        evaluateRules(rules, visitor({ query: { utm_campaign: 'summer24' } })),
      ).toBe(true);
      expect(
        evaluateRules(rules, visitor({ query: { utm_campaign: 'winter' } })),
      ).toBe(false);
    });

    it('rejects an empty actual value for starts_with', () => {
      // The server reads an absent parameter as the empty string and
      // starts_with explicitly rejects it, even for an empty configured
      // value.
      const rules: SegmentRules = {
        utm_parameters: utmRule({
          parameters: [
            { key: 'utm_campaign', value: '', matching: 'starts_with' },
          ],
        }),
      };
      expect(evaluateRules(rules, visitor())).toBe(false);
      expect(
        evaluateRules(rules, visitor({ query: { utm_campaign: 'summer' } })),
      ).toBe(true);
    });

    it('matches an absent parameter exactly against an empty value', () => {
      // Mirrors the server, where the absent parameter reads as ''.
      const rules: SegmentRules = {
        utm_parameters: utmRule({
          parameters: [{ key: 'utm_term', value: '', matching: 'exact' }],
        }),
      };
      expect(evaluateRules(rules, visitor())).toBe(true);
    });

    it('inverts with negate', () => {
      const rules: SegmentRules = {
        utm_parameters: utmRule({ negate: true, parameters: [source] }),
      };
      expect(
        evaluateRules(rules, visitor({ query: { utm_source: 'news' } })),
      ).toBe(false);
      expect(evaluateRules(rules, visitor())).toBe(true);
    });
  });

  describe('geolocation', () => {
    it('matches a configured country, uppercasing the input', () => {
      const rules: SegmentRules = { geolocation: geoRule() };
      expect(evaluateRules(rules, visitor({ country: 'BE' }))).toBe(true);
      expect(evaluateRules(rules, visitor({ country: 'be' }))).toBe(true);
      expect(evaluateRules(rules, visitor({ country: 'NL' }))).toBe(false);
    });

    it('never matches without a country', () => {
      expect(evaluateRules({ geolocation: geoRule() }, visitor())).toBe(false);
      expect(
        evaluateRules({ geolocation: geoRule() }, visitor({ country: '' })),
      ).toBe(false);
    });

    it('requires a region match when regions are configured', () => {
      const rules: SegmentRules = {
        geolocation: geoRule({ countries: ['US'], regions: ['NY', 'CA'] }),
      };
      expect(
        evaluateRules(rules, visitor({ country: 'US', region: 'ny' })),
      ).toBe(true);
      expect(
        evaluateRules(rules, visitor({ country: 'US', region: 'TX' })),
      ).toBe(false);
      expect(evaluateRules(rules, visitor({ country: 'US' }))).toBe(false);
    });

    it('ignores the region when none are configured', () => {
      const rules: SegmentRules = { geolocation: geoRule({ regions: [] }) };
      expect(
        evaluateRules(rules, visitor({ country: 'BE', region: 'XX' })),
      ).toBe(true);
    });

    it('inverts with negate, including for an unknown country', () => {
      const rules: SegmentRules = { geolocation: geoRule({ negate: true }) };
      expect(evaluateRules(rules, visitor({ country: 'BE' }))).toBe(false);
      expect(evaluateRules(rules, visitor({ country: 'NL' }))).toBe(true);
      expect(evaluateRules(rules, visitor())).toBe(true);
    });
  });

  describe('day_of_week', () => {
    it('matches a configured day', () => {
      const rules: SegmentRules = { day_of_week: dayRule() };
      expect(evaluateRules(rules, visitor({ day: 'saturday' }))).toBe(true);
      expect(evaluateRules(rules, visitor({ day: 'monday' }))).toBe(false);
    });

    it('never matches without a day', () => {
      expect(evaluateRules({ day_of_week: dayRule() }, visitor())).toBe(false);
    });

    it('inverts with negate', () => {
      const rules: SegmentRules = { day_of_week: dayRule({ negate: true }) };
      expect(evaluateRules(rules, visitor({ day: 'saturday' }))).toBe(false);
      expect(evaluateRules(rules, visitor({ day: 'monday' }))).toBe(true);
      expect(evaluateRules(rules, visitor())).toBe(true);
    });
  });
});

const makeCase = (uuid: string): ComponentNode => ({
  nodeType: NodeType.Component,
  uuid,
  type: 'p13n.case@v1',
  slots: [
    {
      nodeType: NodeType.Slot,
      id: `${uuid}/content`,
      name: 'content',
      components: [],
    },
  ],
});

const makeSwitch = (uuid: string, cases: ComponentNode[]): ComponentNode => ({
  nodeType: NodeType.Component,
  uuid,
  type: 'p13n.switch@v1',
  slots: [
    {
      nodeType: NodeType.Slot,
      id: `${uuid}/content`,
      name: 'content',
      components: cases,
    },
  ],
});

const makeLayout = (components: ComponentNode[]): RegionNode[] => [
  {
    nodeType: NodeType.Region,
    id: 'content',
    name: 'Content',
    components,
  },
];

describe('resolveVisitorVariants', () => {
  const segments: Record<string, Segment> = {
    default: makeSegment('default'),
    coupon: makeSegment('coupon', { query_parameter: queryRule() }),
    belgium: makeSegment('belgium', { geolocation: geoRule() }),
  };

  const layout = makeLayout([
    makeSwitch('switch-1', [
      makeCase('case-coupon'),
      makeCase('case-belgium'),
      makeCase('case-default'),
    ]),
  ]);

  const model: ComponentModels = {
    'switch-1': {
      resolved: { variants: ['offer', 'belgium_offer', 'default'] },
    },
    'case-coupon': { resolved: { variant_id: 'offer', segments: ['coupon'] } },
    'case-belgium': {
      resolved: { variant_id: 'belgium_offer', segments: ['belgium'] },
    },
    'case-default': {
      resolved: { variant_id: 'default', segments: ['default'] },
    },
  };

  it('serves the first variant whose segments all match', () => {
    expect(
      resolveVisitorVariants(
        layout,
        model,
        segments,
        visitor({ query: { coupon: 'WEEKEND' }, country: 'BE' }),
      ),
    ).toEqual({ 'switch-1': 'offer' });
    expect(
      resolveVisitorVariants(
        layout,
        model,
        segments,
        visitor({ country: 'BE' }),
      ),
    ).toEqual({ 'switch-1': 'belgium_offer' });
    expect(resolveVisitorVariants(layout, model, segments, visitor())).toEqual({
      'switch-1': 'default',
    });
  });

  it('skips disabled cases', () => {
    const disabledModel: ComponentModels = {
      ...model,
      'case-coupon': {
        resolved: { variant_id: 'offer', segments: ['coupon'], disabled: true },
      },
    };
    expect(
      resolveVisitorVariants(
        layout,
        disabledModel,
        segments,
        visitor({ query: { coupon: 'WEEKEND' }, country: 'BE' }),
      ),
    ).toEqual({ 'switch-1': 'belgium_offer' });
  });

  it('requires every segment of a case to match', () => {
    const strictModel: ComponentModels = {
      ...model,
      'case-coupon': {
        resolved: { variant_id: 'offer', segments: ['coupon', 'belgium'] },
      },
    };
    expect(
      resolveVisitorVariants(
        layout,
        strictModel,
        segments,
        visitor({ query: { coupon: 'WEEKEND' } }),
      ),
    ).toEqual({ 'switch-1': 'default' });
    expect(
      resolveVisitorVariants(
        layout,
        strictModel,
        segments,
        visitor({ query: { coupon: 'WEEKEND' }, country: 'BE' }),
      ),
    ).toEqual({ 'switch-1': 'offer' });
  });

  it('falls back to the default variant when nothing matches', () => {
    // The default case targets a missing segment, so no case can match.
    const brokenSegments = { coupon: segments.coupon };
    expect(
      resolveVisitorVariants(layout, model, brokenSegments, visitor()),
    ).toEqual({ 'switch-1': 'default' });
  });

  it('resolves each switch independently', () => {
    const twoSwitchLayout = makeLayout([
      makeSwitch('switch-1', [
        makeCase('case-coupon'),
        makeCase('case-default'),
      ]),
      makeSwitch('switch-2', [
        makeCase('case-belgium-2'),
        makeCase('case-default-2'),
      ]),
    ]);
    const twoSwitchModel: ComponentModels = {
      'switch-1': { resolved: { variants: ['offer', 'default'] } },
      'switch-2': { resolved: { variants: ['belgium_offer', 'default'] } },
      'case-coupon': {
        resolved: { variant_id: 'offer', segments: ['coupon'] },
      },
      'case-default': {
        resolved: { variant_id: 'default', segments: ['default'] },
      },
      'case-belgium-2': {
        resolved: { variant_id: 'belgium_offer', segments: ['belgium'] },
      },
      'case-default-2': {
        resolved: { variant_id: 'default', segments: ['default'] },
      },
    };
    expect(
      resolveVisitorVariants(
        twoSwitchLayout,
        twoSwitchModel,
        segments,
        visitor({ country: 'BE' }),
      ),
    ).toEqual({ 'switch-1': 'default', 'switch-2': 'belgium_offer' });
  });
});

describe('collectSimulationInputs', () => {
  const segments: Record<string, Segment> = {
    coupon: makeSegment('coupon', { query_parameter: queryRule() }),
    campaign: makeSegment('campaign', {
      utm_parameters: utmRule({
        parameters: [
          { key: 'utm_source', value: 'news', matching: 'exact' },
          { key: 'coupon', value: 'W', matching: 'starts_with' },
        ],
      }),
    }),
    benelux: makeSegment('benelux', {
      geolocation: geoRule({ countries: ['nl', 'BE'] }),
    }),
    france: makeSegment('france', {
      geolocation: geoRule({ countries: ['FR'] }),
    }),
    weekend: makeSegment('weekend', { day_of_week: dayRule() }),
    dormant: makeSegment(
      'dormant',
      { geolocation: geoRule({ countries: ['DE'] }) },
      false,
    ),
  };

  it('unions the inputs of the referenced segments', () => {
    expect(
      collectSimulationInputs(segments, [
        'coupon',
        'campaign',
        'benelux',
        'france',
        'weekend',
        'coupon',
      ]),
    ).toEqual({
      queryParameters: ['coupon', 'utm_source'],
      countries: ['BE', 'FR', 'NL'],
      days: true,
    });
  });

  it('offers only inputs the referenced segments use', () => {
    expect(collectSimulationInputs(segments, ['coupon'])).toEqual({
      queryParameters: ['coupon'],
      countries: [],
      days: false,
    });
  });

  it('ignores missing and disabled segments, which never match', () => {
    expect(collectSimulationInputs(segments, ['dormant', 'missing'])).toEqual({
      queryParameters: [],
      countries: [],
      days: false,
    });
    expect(collectSimulationInputs(undefined, ['coupon'])).toEqual({
      queryParameters: [],
      countries: [],
      days: false,
    });
  });

  it('returns nothing for zero-rule segments', () => {
    expect(
      collectSimulationInputs({ default: makeSegment('default') }, ['default']),
    ).toEqual({ queryParameters: [], countries: [], days: false });
  });
});
