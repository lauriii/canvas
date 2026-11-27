import { describe, expect, it } from 'vitest';

import {
  acceptedStyleDefinitions,
  authoredStyleParts,
  collectOptionClasses,
  definitionsFromStyleLibrary,
  generateStylesCss,
  generateStylesDts,
  normalizeStyleCustom,
} from './style-outputs';

import type { StyleDefinitionSummary } from './style-outputs';

const definitions: StyleDefinitionSummary[] = [
  {
    id: 'tone',
    options: [
      { id: 'light', value: 'bg-white text-black', attribute: 'class' },
      { id: 'dark', value: 'bg-black text-white', attribute: 'class' },
    ],
  },
  {
    id: 'spacing',
    options: [
      { id: 'small', value: 'p-2', attribute: 'class' },
      { id: 'extra large', value: '--gap: 4rem', attribute: 'style' },
    ],
  },
  {
    id: 'daisy_theme',
    options: [{ id: 'cupcake', value: 'cupcake', attribute: 'data-theme' }],
  },
];

describe('.canvas style outputs', () => {
  it('generates CanvasStyleRegistry augmentation from accepted styles', () => {
    const dts = generateStylesDts(
      [
        { machineName: 'hero', styles: ['tone', 'daisy_theme', 'missing'] },
        { machineName: 'card-grid', styles: '*' },
        { machineName: 'button', styles: undefined },
      ],
      definitions,
    );
    expect(dts).toContain('export {};');
    expect(dts).toContain(
      [
        "declare module 'drupal-canvas' {",
        '  interface CanvasStyleRegistry {',
        '    button: {};',
        '    "card-grid": {',
        '      tone?: "light" | "dark";',
        '      spacing?: "small" | "extra large";',
        '    };',
        '    hero: {',
        '      tone?: "light" | "dark";',
        '      daisy_theme?: "cupcake";',
        '    };',
        '  }',
        '}',
      ].join('\n'),
    );
  });

  it("limits '*' to styles whose every option sets class or style", () => {
    expect(acceptedStyleDefinitions('*', definitions).map((d) => d.id)).toEqual(
      ['tone', 'spacing'],
    );
    expect(acceptedStyleDefinitions([], definitions)).toEqual([]);
  });

  it('lists every class option value once, split on whitespace', () => {
    expect(collectOptionClasses(definitions)).toEqual([
      'bg-black',
      'bg-white',
      'p-2',
      'text-black',
      'text-white',
    ]);
    expect(generateStylesCss(definitions)).toMatch(
      /\n@source inline\("bg-black bg-white p-2 text-black text-white"\);\n$/,
    );
    expect(generateStylesCss([])).not.toContain('@source');
  });

  it('resolves local library option defaults like core', () => {
    expect(
      definitionsFromStyleLibrary({
        id: 'brand',
        label: 'Brand',
        styles: {
          tone: {
            label: 'Tone',
            options: {
              dark: { label: 'Dark' },
              plain: { label: 'Plain', value: '' },
            },
          },
          theme: {
            label: 'Theme',
            attribute: 'data-theme',
            options: { cupcake: { label: 'Cupcake', value: 'cupcake' } },
          },
        },
      }).map((d) => d.options),
    ).toEqual([
      [
        { id: 'dark', label: 'Dark', value: 'dark', attribute: 'class' },
        { id: 'plain', label: 'Plain', value: '', attribute: 'class' },
      ],
      [
        {
          id: 'cupcake',
          label: 'Cupcake',
          value: 'cupcake',
          attribute: 'data-theme',
        },
      ],
    ]);
  });

  describe('brand kit colors and custom values', () => {
    const gap: StyleDefinitionSummary = {
      id: 'gap',
      options: [{ id: 'sm', value: 'gap-2', attribute: 'class' }],
      custom: normalizeStyleCustom({
        units: { rem: { min: 0, max: 10 }, px: { min: 0, max: 160 } },
        value: 'gap-(--gap)',
        property: '--gap',
      }),
    };
    const columns: StyleDefinitionSummary = {
      id: 'columns',
      options: [],
      custom: normalizeStyleCustom({
        type: 'integer',
        min: 1,
        max: 12,
        value: 'grid-cols-(--columns)',
        property: '--columns',
      }),
    };
    const fill: StyleDefinitionSummary = {
      id: 'fill',
      options: [
        { id: 'none', value: '', attribute: 'class' },
        {
          id: 'canvas-color:abc',
          value: '',
          attribute: 'class',
          kind: 'token',
        },
      ],
      tokens: {
        type: 'color',
        folders: [],
        value: 'bg-(--fill)',
        property: '--fill',
      },
    };

    it('normalizes authored custom declarations like the server', () => {
      expect(gap.custom).toEqual({
        type: 'length',
        units: ['rem', 'px'],
        min: 0,
        max: 10,
        step: null,
        ranges: {
          rem: { min: 0, max: 10, step: null },
          px: { min: 0, max: 160, step: null },
        },
        value: 'gap-(--gap)',
        property: '--gap',
      });
      expect(columns.custom).toMatchObject({
        type: 'integer',
        units: [''],
        min: 1,
        max: 12,
      });
      expect(
        normalizeStyleCustom({
          units: { '%': {} },
          value: 'w',
          property: '--w',
        })?.type,
      ).toBe('percentage');
      expect(
        normalizeStyleCustom({ value: 'gap', property: 'gap' }),
      ).toBeNull();
      expect(
        normalizeStyleCustom({ value: ' ', property: '--gap' }),
      ).toBeNull();
    });

    it('includes the brand kit color and custom value classes', () => {
      expect(collectOptionClasses([gap, columns, fill])).toEqual([
        'bg-(--fill)',
        'gap-(--gap)',
        'gap-2',
        'grid-cols-(--columns)',
      ]);
    });

    it('reads tokens and custom from local libraries', () => {
      const [local] = definitionsFromStyleLibrary({
        id: 'brand',
        label: 'Brand',
        styles: {
          fill: {
            label: 'Fill',
            tokens: { type: 'color', value: 'bg-(--fill)', property: '--fill' },
            custom: {
              units: { px: { min: 0 } },
              value: 'p-(--fill-pad)',
              property: '--fill-pad',
            },
          },
        },
      });
      expect(local.options).toEqual([]);
      expect(collectOptionClasses([local])).toEqual([
        'bg-(--fill)',
        'p-(--fill-pad)',
      ]);
    });

    it('types custom values and brand kit color references', () => {
      const dts = generateStylesDts(
        [{ machineName: 'grid', styles: ['gap', 'columns', 'fill'] }],
        [gap, columns, fill],
      );
      expect(dts).toContain(
        [
          '    grid: {',
          '      gap?: "sm" | `=${number}${"rem" | "px"}`;',
          '      columns?: `=${number}`;',
          '      fill?: "none" | `canvas-color:${string}`;',
          '    };',
        ].join('\n'),
      );
    });
  });

  describe('named parts', () => {
    it('keys part styles by part and style ID', () => {
      const dts = generateStylesDts(
        [
          {
            machineName: 'card',
            styles: ['tone'],
            styleParts: {
              heading: { label: 'Heading', styles: { spacing: null } },
              footer: { label: 'Footer', styles: ['tone', 'missing'] },
            },
          },
        ],
        definitions,
      );
      expect(dts).toContain(
        [
          '    card: {',
          '      tone?: "light" | "dark";',
          '      "heading.spacing"?: "small" | "extra large";',
          '      "footer.tone"?: "light" | "dark";',
          '    };',
        ].join('\n'),
      );
    });

    it('converts stored parts to the authored shape', () => {
      expect(
        authoredStyleParts({
          heading: {
            label: 'Heading',
            styles: ['fill', 'text_size'],
            limits: { fill: { folders: ['Brand'] } },
          },
          body: { label: 'Body', styles: ['text_size'] },
        }),
      ).toEqual({
        heading: {
          label: 'Heading',
          styles: { fill: { folders: ['Brand'] }, text_size: null },
        },
        body: { label: 'Body', styles: ['text_size'] },
      });
    });
  });
});
