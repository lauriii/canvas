import { describe, expect, it } from 'vitest';

import { setComponentStyles } from '@/features/layout/layoutModelSlice';
import {
  STYLE_UNDO_MERGE_MS,
  styleUndoGroup,
} from '@/features/styles/styleUndo';
import {
  acceptedKeys,
  accessibleName,
  allowedHere,
  chooseControl,
  convertCustom,
  customValue,
  degradedEntries,
  describeSkipped,
  groupByCategory,
  matchesFilter,
  parseCustomValue,
  pasteStyles,
  scrubPixelsPerStep,
  stepCustom,
  visibleOptions,
} from '@/features/styles/styleUtils';

import valueCases from '../../../../packages/drupal-canvas/src/style-values.fixtures.json';

import type { StyleCustom, StyleDefinition, StyleOption } from '@/types/Styles';

const option = (id: string, label = id, extra: Partial<StyleOption> = {}) =>
  ({
    id,
    label,
    description: '',
    value: id,
    attribute: 'class',
    status: 'stable',
    icon: null,
    ...extra,
  }) as StyleOption;

const style = (
  id: string,
  options: StyleOption[],
  extra: Partial<StyleDefinition> = {},
) =>
  ({
    id,
    label: id[0].toUpperCase() + id.slice(1),
    description: '',
    category: '',
    weight: 0,
    status: 'stable',
    provider: 'theme',
    providerLabel: 'Olivero',
    providerType: 'theme',
    library: '',
    options,
    fallbacks: {},
    notOffered: {},
    tokens: null,
    custom: null,
    ...extra,
  }) as StyleDefinition;

describe('chooseControl', () => {
  it('uses a switch for one option', () => {
    expect(chooseControl([option('on')])).toBe('switch');
  });
  it('uses a toolbar when every option has an icon', () => {
    expect(
      chooseControl([
        option('start', 'Start', { icon: 'align-left' }),
        option('end', 'End', { icon: 'align-right' }),
      ]),
    ).toBe('toolbar');
  });
  it('uses a segmented control for two to four short labels', () => {
    expect(chooseControl([option('s', 'Small'), option('l', 'Large')])).toBe(
      'segmented',
    );
  });
  it('fits segments sized to their labels beside the label', () => {
    expect(
      chooseControl(
        ['Small', 'Medium', 'Large', 'Huge'].map((label) =>
          option(label, label),
        ),
      ),
    ).toBe('segmented');
    expect(
      chooseControl(
        ['Compact', 'Regular', 'Generous'].map((label) => option(label, label)),
      ),
    ).toBe('segmented');
    expect(
      chooseControl(
        ['Narrow', 'Regular', 'Wide', 'Full'].map((label) =>
          option(label, label),
        ),
      ),
    ).toBe('segmented');
    for (const labels of [
      ['Small', 'Medium', 'Large'],
      ['Small', 'Regular', 'Large'],
      ['Tight', 'Regular', 'Wide'],
      ['1:1', '4:3', '16:9', '3:4'],
    ]) {
      expect(chooseControl(labels.map((label) => option(label, label)))).toBe(
        'segmented',
      );
    }
  });
  it('uses a select for long labels or many options', () => {
    expect(
      chooseControl([
        option('xs', 'Extra small'),
        option('md', 'Medium'),
        option('xl', 'Extra large'),
      ]),
    ).toBe('select');
    expect(
      chooseControl([
        option('xxs', 'Extra extra small'),
        option('md', 'Medium'),
        option('xxl', 'Extra extra large'),
      ]),
    ).toBe('select');
    expect(chooseControl('abcde'.split('').map((id) => option(id)))).toBe(
      'select',
    );
  });
});

describe('visibleOptions', () => {
  const definition = style('background', [
    option('old', 'Old', { status: 'deprecated' }),
    option('new', 'New'),
  ]);
  it('hides deprecated options unless current, and sorts them last', () => {
    expect(visibleOptions(definition, undefined).map((o) => o.id)).toEqual([
      'new',
    ]);
    expect(visibleOptions(definition, 'old').map((o) => o.id)).toEqual([
      'new',
      'old',
    ]);
  });
});

describe('degradedEntries', () => {
  const definitions = { spacing: style('spacing', [option('large')]) };
  it('reports unavailable styles and options, and unaccepted styles', () => {
    expect(
      degradedEntries({ spacing: 'large', gone: 'x' }, definitions, [
        'spacing',
      ]),
    ).toEqual({ gone: 'unavailable' });
    expect(
      degradedEntries({ spacing: 'huge' }, definitions, ['spacing']),
    ).toEqual({ spacing: 'unavailable' });
    expect(degradedEntries({ spacing: 'large' }, definitions, [])).toEqual({
      spacing: 'not_accepted',
    });
  });

  it('reports brand kit fallbacks and options this site does not offer', () => {
    const branded = {
      palette: style('palette', [option('base')], {
        fallbacks: { muted: 'base' },
        notOffered: { muted: 'Muted', ink: 'Ink' },
      }),
    };
    expect(
      degradedEntries({ palette: 'muted' }, branded, ['palette', 'corners']),
    ).toEqual({ palette: 'fallback' });
    expect(degradedEntries({ palette: 'ink' }, branded, ['palette'])).toEqual({
      palette: 'not_offered',
    });
    expect(
      degradedEntries({ corners: 'round' }, branded, ['corners'], {
        corners: 'Corners',
      }),
    ).toEqual({ corners: 'not_offered' });
  });
});

describe('filtering and grouping', () => {
  const padding = style('padding', [option('s', 'Small')], {
    category: 'Spacing',
    description: 'Inner space',
  });
  const misc = style('misc', [option('x', 'Pad me')]);
  it('matches labels, descriptions, and option labels', () => {
    expect(matchesFilter(padding, 'pad')).toBe(true);
    expect(matchesFilter(padding, 'inner')).toBe(true);
    expect(matchesFilter(misc, 'pad')).toBe(true);
    expect(matchesFilter(padding, 'color')).toBe(false);
  });
  it('groups uncategorized styles last under Other', () => {
    expect(
      groupByCategory([misc, padding]).map(({ category }) => category),
    ).toEqual(['Spacing', 'Other']);
  });
});

describe('pasteStyles', () => {
  it('skips options this site does not offer', () => {
    const result = pasteStyles(
      { spacing: 'huge' },
      [{ uuid: 'a', styles: undefined, accepted: ['spacing'] }],
      'replace',
      { spacing: style('spacing', [option('large')]) },
    );
    expect(result.changes).toEqual([{ uuid: 'a', styles: {} }]);
    expect(result.skipped).toEqual({ spacing: 1 });
  });

  const copied = { spacing: 'large', columns: '3' };
  it('replaces, keeping only accepted styles, and counts skipped ones', () => {
    const result = pasteStyles(
      copied,
      [
        {
          uuid: 'a',
          styles: { tone: 'dark' },
          accepted: ['spacing', 'columns'],
        },
        { uuid: 'b', styles: undefined, accepted: ['spacing'] },
      ],
      'replace',
    );
    expect(result.changes).toEqual([
      { uuid: 'a', styles: { spacing: 'large', columns: '3' } },
      { uuid: 'b', styles: { spacing: 'large' } },
    ]);
    expect(result.skipped).toEqual({ columns: 1 });
    expect(
      describeSkipped(result.skipped, { columns: style('columns', []) }, (n) =>
        n === 1 ? 'section' : 'sections',
      ),
    ).toBe("Columns wasn't pasted on 1 section: it doesn't support it.");
  });
  it('merge keeps target styles the source does not set', () => {
    expect(
      pasteStyles(
        { spacing: 'large' },
        [{ uuid: 'a', styles: { tone: 'dark' }, accepted: ['spacing'] }],
        'merge',
      ).changes,
    ).toEqual([{ uuid: 'a', styles: { tone: 'dark', spacing: 'large' } }]);
  });
});

describe('accessibleName', () => {
  const spacing = style('spacing', [option('large', 'Large')]);
  it('names the style, the option, and whether it is set', () => {
    expect(accessibleName(spacing, 'large')).toBe('Spacing, Large, set');
    expect(accessibleName(spacing, undefined)).toBe('Spacing, not set');
  });
});

describe('styleUndoGroup', () => {
  const change = (mergeKey: string) =>
    setComponentStyles({ changes: [], mergeKey });
  it('merges changes to the same style within the merge window', () => {
    const first = styleUndoGroup(change('a:spacing'), 1000);
    const second = styleUndoGroup(change('a:spacing'), 1000 + 100);
    const later = styleUndoGroup(
      change('a:spacing'),
      1100 + STYLE_UNDO_MERGE_MS + 1,
    );
    const other = styleUndoGroup(change('a:tone'), 3000);
    expect(first?.merges).toBe(false);
    expect(second).toEqual({ group: first?.group, merges: true });
    expect(later?.merges).toBe(false);
    expect(other?.merges).toBe(false);
    expect(styleUndoGroup({ type: 'other' })).toBeNull();
  });
});

describe('custom values, tokens, and component limits', () => {
  const gap = style('gap', [option('tight')], {
    custom: {
      type: 'length',
      units: ['px', 'rem'],
      min: 0,
      max: 160,
      step: 4,
      ranges: {
        px: { min: 0, max: 160, step: 4 },
        rem: { min: 0, max: 10, step: 0.25 },
      },
      value: 'ts-gap--custom',
      property: '--ts-gap-custom',
    },
  });
  const lagoon = option('canvas-color:1', 'Lagoon', {
    kind: 'token',
    swatch: '#1b6f8a',
    folder: 'Brand',
  });
  const fog = option('canvas-color:2', 'Fog', {
    kind: 'token',
    swatch: '#eef0f3',
    folder: 'Neutrals',
  });
  const fill = style('fill', [option('raised'), lagoon, fog]);

  it('steps custom values within bounds, keeping the unit', () => {
    const custom = gap.custom!;
    expect(stepCustom('=8rem', custom, 1)).toBe('=8.25rem');
    expect(stepCustom('=8px', custom, -10)).toBe('=0px');
    expect(stepCustom('=156px', custom, 10)).toBe('=160px');
    // An option or no value starts from the minimum, in the first unit.
    expect(stepCustom('tight', custom, 2)).toBe('=8px');
    expect(stepCustom(undefined, custom, 1)).toBe('=4px');
  });

  it('scales dragging to the range', () => {
    // 0 to 160 px in 4px steps: 40 steps, 6px each.
    expect(scrubPixelsPerStep(gap.custom!, '=8px')).toBe(6);
    // 0 to 10 rem in quarter steps: 40 steps as well.
    expect(scrubPixelsPerStep(gap.custom!, '=2rem')).toBe(6);
  });

  it("converts between units, within each unit's bounds", () => {
    const size = {
      type: 'length' as const,
      units: ['rem', 'px', 'ch'],
      min: 1,
      max: 10,
      step: 0.25,
      ranges: {
        rem: { min: 1, max: 10, step: 0.25 },
        px: { min: 16, max: 160, step: 4 },
        ch: { min: 20, max: 110, step: 1 },
      },
      value: 'size',
      property: '--size',
    };
    expect(convertCustom(4, 'rem', 'px', size)).toBe('=64px');
    expect(convertCustom(18, 'px', 'rem', size)).toBe('=1.25rem');
    // Characters don't convert: the value keeps its place in the range.
    expect(convertCustom(5.5, 'rem', 'ch', size)).toBe('=65ch');
    expect(parseCustomValue('=8px', size)).toBeUndefined();
    expect(stepCustom('=64px', size, 1)).toBe('=68px');
  });

  it('parses custom values against the style rules', () => {
    expect(parseCustomValue('=24px', gap.custom!)).toEqual({
      number: 24,
      unit: 'px',
    });
    expect(parseCustomValue('=200px', gap.custom!)).toBeUndefined();
    expect(parseCustomValue('=24em', gap.custom!)).toBeUndefined();
    expect(customValue(1.5, 'rem')).toBe('=1.5rem');
  });

  it('applies component limits by folder, color name, and option', () => {
    expect(allowedHere(lagoon, { folders: ['brand'] })).toBe(true);
    expect(allowedHere(fog, { folders: ['Brand'] })).toBe(false);
    expect(allowedHere(fog, { colors: ['fog'] })).toBe(true);
    expect(allowedHere(fill.options[0], { folders: ['Brand'] })).toBe(true);
    expect(allowedHere(fill.options[0], { options: [] })).toBe(false);
  });

  it('uses swatches when brand kit colors are choices', () => {
    expect(chooseControl(fill.options)).toBe('swatches');
  });

  it('reports custom values and limits in degraded entries', () => {
    const definitions = { gap, fill };
    expect(
      degradedEntries({ gap: '=24px' }, definitions, ['gap', 'fill']),
    ).toEqual({});
    expect(degradedEntries({ gap: '=999px' }, definitions, ['gap'])).toEqual({
      gap: 'invalid',
    });
    expect(
      degradedEntries(
        { gap: '=24px' },
        definitions,
        ['gap'],
        {},
        {
          gap: { custom: false },
        },
      ),
    ).toEqual({ gap: 'not_allowed_here' });
    expect(
      degradedEntries(
        { fill: 'canvas-color:2' },
        definitions,
        ['fill'],
        {},
        {
          fill: { folders: ['Brand'] },
        },
      ),
    ).toEqual({ fill: 'not_allowed_here' });
  });
});

describe('named parts', () => {
  const size = style('text_size', [option('s'), option('l')]);
  const accepted = acceptedKeys(['text_size'], {
    lede: { label: 'Intro text', styles: ['text_size'], limits: {} },
  });

  it("accepts part keys after the component's own styles", () => {
    expect(accepted).toEqual(['text_size', 'lede.text_size']);
  });

  it('checks part values against the style, keyed by part', () => {
    const degraded = degradedEntries(
      {
        text_size: 's',
        'lede.text_size': 'l',
        'heading.text_size': 'l',
        'lede.text_size_x': 'x',
      },
      { text_size: size },
      accepted,
    );
    expect(degraded).toEqual({
      'heading.text_size': 'not_accepted',
      'lede.text_size_x': 'unavailable',
    });
  });

  it('pastes part values only onto components with the part', () => {
    const { changes, skipped } = pasteStyles(
      { 'lede.text_size': 'l' },
      [
        { uuid: 'a', styles: {}, accepted },
        { uuid: 'b', styles: {}, accepted: ['text_size'] },
      ],
      'merge',
      { text_size: size },
    );
    expect(changes[0].styles).toEqual({ 'lede.text_size': 'l' });
    expect(changes[1].styles).toEqual({});
    expect(skipped).toEqual({ 'lede.text_size': 1 });
  });
});

describe('shared custom value cases', () => {
  // The same cases run in PHP (StyleValuesTest) and drupal-canvas.
  it.each(valueCases.parse)('$spec $value', ({ spec, value, css }) => {
    const normalized = valueCases.specs[spec as keyof typeof valueCases.specs]
      .normalized as unknown as StyleCustom;
    const parsed = parseCustomValue(value, normalized);
    expect(parsed ? `${parsed.number}${parsed.unit}` : null).toBe(css);
  });
});
