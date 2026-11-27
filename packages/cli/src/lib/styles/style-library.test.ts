import { describe, expect, it } from 'vitest';

import {
  findStyleLibraryCollisions,
  parseStyleLibrary,
  serializeStyleLibrary,
  STYLE_SCHEMA_URL,
  styleLibrariesEqual,
} from './style-library';
import {
  collectStyleLibraryErrors,
  planStyleLibraryPush,
} from './style-library-push';

const FILE = '/project/styles/brand.utilities.yml';

function parse(source: string, filePath = FILE) {
  return parseStyleLibrary(filePath, source);
}

function errors(source: string) {
  return parse(source)
    .diagnostics.filter((d) => d.severity === 'error')
    .map((d) => ({ line: d.line, message: d.message }));
}

describe('style library files', () => {
  it('serializes with the schema comment first and the label below it', () => {
    const source = serializeStyleLibrary({
      id: 'brand',
      label: 'Brand',
      styles: {
        surface: {
          label: 'Surface',
          options: { light: { label: 'Light', value: 'bg-white' } },
        },
      },
    });
    const lines = source.split('\n');
    expect(lines[0]).toBe(
      `# yaml-language-server: $schema=${STYLE_SCHEMA_URL}`,
    );
    expect(lines[1]).toBe('# label: Brand');

    const parsed = parse(source);
    expect(parsed.diagnostics).toEqual([]);
    expect(parsed.library).toEqual({
      id: 'brand',
      label: 'Brand',
      styles: {
        surface: {
          label: 'Surface',
          options: { light: { label: 'Light', value: 'bg-white' } },
        },
      },
    });
  });

  it('defaults the label to the library ID and reads an empty file', () => {
    expect(parse('').library).toEqual({
      id: 'brand',
      label: 'brand',
      styles: {},
    });
    expect(parse('').diagnostics).toEqual([]);
  });

  it('rejects attributes other than class, style, data-*, and aria-*, naming the option and line', () => {
    expect(
      errors(
        [
          'surface:',
          '  label: Surface',
          '  options:',
          '    dark:',
          '      label: Dark',
          '      value: alert(1)',
          '      attribute: onclick',
        ].join('\n'),
      ),
    ).toEqual([
      {
        line: 7,
        message:
          'Option "dark" of style "surface": attribute "onclick" is not allowed. Use class, style, data-*, or aria-*.',
      },
    ]);
  });

  it('allows data-* and aria-* attributes', () => {
    expect(
      errors(
        [
          'theme:',
          '  label: Theme',
          '  attribute: data-theme',
          '  options:',
          '    dark: { label: Dark, value: dark }',
          '    hidden: { label: Hidden, value: "true", attribute: aria-hidden }',
        ].join('\n'),
      ),
    ).toEqual([]);
  });

  it('rejects library and target keys', () => {
    expect(
      errors(
        [
          'surface:',
          '  label: Surface',
          '  library: my_theme/surface',
          '  target: page',
          '  options:',
          '    light: { label: Light, value: bg-white }',
        ].join('\n'),
      ).map((e) => e.line),
    ).toEqual([3, 4]);
  });

  it('rejects invalid style and option IDs, unknown keys, and missing labels or options', () => {
    const messages = errors(
      [
        'Surface:',
        '  label: Surface',
        '  options:',
        "    '0': { label: Zero, value: a }",
        '    ok: { value: b, shade: red }',
        'empty:',
        '  label: Empty',
      ].join('\n'),
    ).map((e) => e.message);
    expect(messages).toEqual([
      '"Surface" is not a valid style ID. Style IDs contain only lowercase letters, numbers, and underscores.',
      '"0" is not a valid option ID for style "Surface".',
      'Option "ok" of style "Surface" has unknown key "shade".',
      'Option "ok" of style "Surface" must have a label.',
      'Style "empty" must have at least one option.',
    ]);
  });

  it('rejects an invalid library ID from the file name', () => {
    const parsed = parse('{}', '/project/styles/Brand-Kit.utilities.yml');
    expect(parsed.diagnostics[0].message).toContain(
      '"Brand-Kit" is not a valid library ID',
    );
  });

  it('reports YAML syntax errors with a line', () => {
    const parsed = parse('surface:\n  label: [unclosed\n');
    expect(parsed.diagnostics[0].severity).toBe('error');
    expect(parsed.diagnostics[0].line).toBeGreaterThan(0);
  });

  it('warns when an option has no value, suggesting an empty value for logic-only options', () => {
    const parsed = parse(
      [
        'tone:',
        '  label: Tone',
        '  options:',
        '    dark: { label: Dark }',
        "    plain: { label: Plain, value: '' }",
      ].join('\n'),
    );
    expect(parsed.diagnostics).toEqual([
      expect.objectContaining({
        severity: 'warning',
        line: 4,
        message:
          'Option "dark" of style "tone" has no value, so it sets class="dark". Set `value: \'\'` for an option that only drives component logic.',
      }),
    ]);
    // Warnings do not block a push.
    expect(collectStyleLibraryErrors([parsed])).toEqual([]);
  });

  it('reports collisions with extensions and between local libraries', () => {
    const style =
      'surface:\n  label: S\n  options:\n    a: { label: A, value: a }\n';
    const brand = parse(style);
    const other = parse(style, '/project/styles/other.utilities.yml');
    const collisions = findStyleLibraryCollisions(
      [brand, other],
      [{ id: 'surface', provider: 'olivero', providerType: 'theme' }],
    );
    expect(collisions.get(brand)).toEqual([
      'The style "surface" is already provided by olivero. Rename it in brand.utilities.yml.',
    ]);
    expect(collisions.get(other)).toEqual([
      'The style "surface" is already provided by olivero. Rename it in other.utilities.yml.',
      'The style "surface" is also defined in brand.utilities.yml.',
    ]);
    // A site library with the same ID is this library on the site.
    expect(
      findStyleLibraryCollisions(
        [brand],
        [{ id: 'surface', provider: 'canvas', providerType: 'site' }],
      ).size,
    ).toBe(0);
  });

  it('plans no change for a library that matches the site, ignoring style key order', () => {
    const remote = {
      id: 'brand',
      label: 'Brand',
      styles: {
        spacing: {
          label: 'Spacing',
          weight: 1,
          options: { s: { label: 'S', value: 'p-2' } },
        },
        surface: {
          label: 'Surface',
          options: {
            light: { label: 'Light', value: 'bg-white' },
            dark: { label: 'Dark', value: 'bg-black' },
          },
        },
      },
    };
    const local = parse(serializeStyleLibrary(remote));
    expect(planStyleLibraryPush([local], [remote])[0].operation).toBe(
      'unchanged',
    );

    const reordered = {
      ...remote,
      styles: {
        surface: remote.styles.surface,
        spacing: remote.styles.spacing,
      },
    };
    expect(styleLibrariesEqual(reordered, remote)).toBe(true);

    // Option order is what editors see, so it is a change.
    const optionsSwapped = {
      ...remote,
      styles: {
        ...remote.styles,
        surface: {
          label: 'Surface',
          options: {
            dark: remote.styles.surface.options.dark,
            light: remote.styles.surface.options.light,
          },
        },
      },
    };
    expect(styleLibrariesEqual(optionsSwapped, remote)).toBe(false);
    expect(
      planStyleLibraryPush([local], [{ ...remote, label: 'Renamed' }])[0]
        .operation,
    ).toBe('update');
    expect(planStyleLibraryPush([local], [])[0].operation).toBe('create');
  });

  describe('custom values and brand kit colors', () => {
    const style = (lines: string[]) =>
      ['gap:', '  label: Gap', ...lines].join('\n');

    it('accepts a units map, unitless bounds, and tokens without options', () => {
      expect(
        parse(
          [
            style([
              '  custom:',
              '    units:',
              '      rem: { min: 1, max: 10, step: 0.25 }',
              '      px: { min: 16, max: 160 }',
              '      em: ~',
              '    value: gap-(--gap)',
              '    property: --gap',
            ]),
            'columns:',
            '  label: Columns',
            '  options:',
            '    two: { label: Two, value: grid-cols-2 }',
            '  custom:',
            '    type: integer',
            '    min: 1',
            '    max: 12',
            '    value: grid-cols-(--columns)',
            '    property: --columns',
            'fill:',
            '  label: Fill',
            '  tokens:',
            '    type: color',
            '    folders: [Brand]',
            '    value: bg-(--fill)',
            '    property: --fill',
          ].join('\n'),
        ).diagnostics,
      ).toEqual([]);
    });

    it('rejects invalid custom declarations, naming the line', () => {
      expect(
        errors(
          style([
            '  custom:',
            '    type: integer',
            '    units:',
            '      rem: { min: 10, max: 1, step: 0 }',
            '      PX: { min: small }',
            '    value: ""',
            '    property: gap',
          ]),
        ),
      ).toEqual([
        {
          line: 4,
          message:
            'Custom values of style "gap": type integer only applies to unitless numbers. Remove units or type.',
        },
        {
          line: 6,
          message:
            'Custom values of style "gap" in rem: min must not be greater than max.',
        },
        {
          line: 6,
          message:
            'Custom values of style "gap" in rem: step must be greater than 0.',
        },
        {
          line: 7,
          message:
            'Custom values of style "gap": "PX" is not a valid unit. Use lowercase letters or %.',
        },
        {
          line: 7,
          message: 'Custom values of style "gap" in PX: min must be a number.',
        },
        {
          line: 8,
          message:
            'Custom values of style "gap": value must be a non-empty string.',
        },
        {
          line: 9,
          message:
            'Custom values of style "gap": property "gap" is not a valid custom property name. Use "--" followed by lowercase letters, numbers, and hyphens.',
        },
      ]);
    });

    it('rejects a units list and missing keys, and warns about ignored bounds', () => {
      const parsed = parse(
        style(['  custom:', '    units: [rem, px]', '    max: 4']),
      );
      expect(parsed.diagnostics.map((d) => [d.severity, d.message])).toEqual([
        [
          'error',
          'Custom values of style "gap": units must be a mapping of units to bounds, for example `rem: { min: 1, max: 10, step: 0.25 }`.',
        ],
        [
          'warning',
          'Custom values of style "gap": top-level max is ignored when units are set. Move the bounds under each unit.',
        ],
        ['error', 'Custom values of style "gap" must have a value.'],
        ['error', 'Custom values of style "gap" must have a property.'],
      ]);
    });

    it('rejects invalid brand kit color declarations', () => {
      expect(
        errors(
          style([
            '  tokens:',
            '    type: font',
            '    value: bg-(--fill)',
            '    property: --Fill',
          ]),
        ).map((e) => e.message),
      ).toEqual([
        'Brand kit colors of style "gap": type must be color.',
        'Brand kit colors of style "gap": property "--Fill" is not a valid custom property name. Use "--" followed by lowercase letters, numbers, and hyphens.',
      ]);
    });

    it('still requires options when neither tokens nor custom is set', () => {
      expect(errors(style([])).map((e) => e.message)).toEqual([
        'Style "gap" must have at least one option.',
      ]);
    });
  });
});
