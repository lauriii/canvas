import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it } from 'vitest';

import valueCases from './style-values.fixtures.json';
import fixtures from './styles.fixtures.json';
import {
  extendCn,
  parseCustomStyleValue,
  parseInlineStyle,
  resolveStyles,
  styleOptionsFromSelection,
  StyleOptionsProvider,
  useStyleOptions,
} from './styles.js';

import type {
  StyleCustomDefinition,
  StyleDefinition,
  StyleSelection,
} from './styles.js';

describe('custom values and tokens', () => {
  const definitions: StyleDefinition[] = [
    {
      id: 'background',
      label: 'Background',
      options: {
        'canvas-color:5b7e2a4c-1d3f-4e8a-9b2c-0f1e2d3c4b5a': {
          id: 'canvas-color:5b7e2a4c-1d3f-4e8a-9b2c-0f1e2d3c4b5a',
          label: 'Lagoon',
          value: '',
          attribute: 'class',
          kind: 'token',
          swatch: '#1b6f8a',
          cssVariable: '--brand-lagoon',
        },
      },
      tokens: { value: 'bg-(--style-bg)', property: '--style-bg' },
    },
    {
      id: 'gap',
      label: 'Gap',
      options: {},
      custom: {
        type: 'length',
        units: ['px', 'rem'],
        min: 0,
        max: 256,
        value: 'gap-(--gap)',
        property: '--gap',
      },
    },
  ];

  it('resolves like ComponentStyles::resolveWithDefinitions()', () => {
    // @see tests/src/Unit/Style/StyleValuesTest.php
    expect(
      resolveStyles(
        {
          background: 'canvas-color:5b7e2a4c-1d3f-4e8a-9b2c-0f1e2d3c4b5a',
          gap: '=24px',
        },
        definitions,
      ),
    ).toEqual({
      className: 'bg-(--style-bg) gap-(--gap)',
      style: { '--style-bg': 'var(--brand-lagoon, #1b6f8a)', '--gap': '24px' },
    });
  });

  it('drops custom values that break the rules', () => {
    expect(resolveStyles({ gap: '=999px' }, definitions).className).toBe(
      undefined,
    );
    expect(
      parseCustomStyleValue('=24px;color:red', definitions[1].custom!),
    ).toBe(undefined);
  });
});

describe('shared custom value cases', () => {
  // The same cases run in PHP (StyleValuesTest) and the editor.
  it.each(valueCases.parse)('$spec $value', ({ spec, value, css }) => {
    const normalized = valueCases.specs[spec as keyof typeof valueCases.specs]
      .normalized as unknown as StyleCustomDefinition;
    expect(parseCustomStyleValue(value, normalized) ?? null).toBe(css);
  });
});

describe('resolveStyles', () => {
  // The same fixtures are resolved by ComponentStyles::resolveWithDefinitions().
  // @see tests/src/Unit/Style/ComponentStylesTest.php
  it.each(fixtures.cases)('$name', ({ selection, expected }) => {
    const resolved = resolveStyles(
      selection as StyleSelection,
      fixtures.definitions as unknown as Record<string, StyleDefinition>,
    );
    expect(resolved.className ?? null).toEqual(expected.className);
    expect(resolved.style ?? null).toEqual(expected.style);
  });
});

describe('parseInlineStyle', () => {
  it('keeps custom properties and camelCases the rest', () => {
    expect(parseInlineStyle('--gap: 1rem; margin-top: 2px; broken')).toEqual({
      '--gap': '1rem',
      marginTop: '2px',
    });
  });
});

describe('useStyleOptions', () => {
  const Probe = () => {
    const options = useStyleOptions();
    return (
      <>
        {Object.entries(options)
          .map(([id, value]) => `${id}=${value}`)
          .join(',')}
      </>
    );
  };

  it('returns {} outside a provider', () => {
    expect(renderToStaticMarkup(<Probe />)).toBe('');
  });

  it('returns option IDs, reducing breakpoint values to base', () => {
    expect(
      renderToStaticMarkup(
        <StyleOptionsProvider
          styles={{ tone: 'dark', spacing: { base: 'large', md: 'small' } }}
        >
          <Probe />
        </StyleOptionsProvider>,
      ),
    ).toBe('tone=dark,spacing=large');
  });

  it('reduces a selection without React', () => {
    expect(styleOptionsFromSelection(null)).toEqual({});
  });
});

describe('extendCn', () => {
  it('uses a custom tailwind-merge configuration', () => {
    const cn = extendCn({
      extend: { classGroups: { 'font-size': [{ text: ['hero'] }] } },
    });
    // Without the configuration, tailwind-merge reads `text-hero` as a color
    // and drops `text-red-500`.
    expect(cn('text-red-500', 'text-hero')).toBe('text-red-500 text-hero');
  });
});
