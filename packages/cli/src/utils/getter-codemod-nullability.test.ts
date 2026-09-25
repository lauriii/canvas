import { describe, expect, it } from 'vitest';
import { parse } from '@babel/parser';

import { migrateGetterCalls } from './getter-codemod';

describe('nullable getter migration', () => {
  it.each([
    ['getPageData', 'usePageContext', 'pageTitle'],
    ['getSiteData', 'useSiteContext', 'branding'],
  ])(
    'preserves optional access and explicit fallbacks for %s',
    (getter, hook, field) => {
      for (const statement of [
        `const data = read(); return data?.${field};`,
        `const { ${field}: value = 'Unavailable' } = read() ?? {}; return value;`,
        `const data = (read() ?? {}) ?? {}; return data;`,
        `const data = read(); const { ${field}: value } = data ?? {}; return value;`,
      ]) {
        const source = `import { ${getter} as read } from 'drupal-canvas';
export default function Example() { ${statement} }`;
        const result = migrateGetterCalls(source, 'index.jsx');
        expect(result.changed).toBe(true);
        expect(result.warnings).toEqual([]);
        expect(result.source)
          .toBe(`import { ${hook} } from 'drupal-canvas/react';
export default function Example() { ${statement.replace('read()', `${hook}()`)} }`);
        expect(() =>
          parse(result.source, { sourceType: 'module' }),
        ).not.toThrow();
        expect(migrateGetterCalls(result.source, 'index.jsx').changed).toBe(
          false,
        );
      }
    },
  );

  it.each([
    'const { pageTitle } = read();',
    "const { pageTitle: title = 'Unavailable' } = read();",
    'const { mainEntity: { uuid } } = read();',
    'const { mainEntity: { uuid } = {} } = read();',
    'const [first] = read();',
    'let pageTitle; ({ pageTitle } = read());',
    'const { pageTitle } = (read() as any);',
    'const { pageTitle } = read()!;',
    'const { pageTitle } = (read() satisfies any);',
  ])('fails closed for direct nullable destructuring: %s', (statement) => {
    const source = `import { getPageData as read, getSiteData, JsonApiClient } from 'drupal-canvas';
export default function Example() {
  const site = getSiteData();
  ${statement}
  return new JsonApiClient();
}`;
    const result = migrateGetterCalls(source, 'index.tsx');
    expect(result).toMatchObject({
      changed: false,
      source,
      conversions: [],
      usesGetters: true,
      constructsClient: true,
    });
    expect(result.warnings).toEqual([
      expect.stringContaining('`read()` is destructured without a null guard'),
      expect.stringContaining(
        '`new JsonApiClient()` is not migrated automatically',
      ),
    ]);
    expect(result.warnings[0]).toContain('`usePageContext() ?? {}`');
  });

  it.each([
    ['getPageData', 'usePageContext', 'pageTitle'],
    ['getSiteData', 'useSiteContext', 'baseUrl'],
  ])('rejects the literal bare %s example', (getter, hook, field) => {
    const source = `import { ${getter} } from 'drupal-canvas';
export default function Example() { const { ${field} } = ${getter}(); return ${field}; }`;
    const result = migrateGetterCalls(source, 'index.jsx');
    expect(result).toMatchObject({ changed: false, source, conversions: [] });
    expect(result.warnings).toEqual([
      expect.stringContaining(`\`${hook}() ?? {}\``),
    ]);
  });

  it('migrates an explicit namespace fallback without altering nested defaults', () => {
    const source = `import * as utils from 'drupal-canvas';
export default function Example() { const { branding: { siteName } = {} } = utils.getSiteData() ?? {}; return siteName; }`;
    const result = migrateGetterCalls(source, 'index.jsx');
    expect(result.changed).toBe(true);
    expect(result.warnings).toEqual([]);
    expect(result.source).toContain(
      'const { branding: { siteName } = {} } = useSiteContext() ?? {}',
    );
    expect(() => parse(result.source, { sourceType: 'module' })).not.toThrow();
    expect(migrateGetterCalls(result.source, 'index.jsx').changed).toBe(false);
  });

  it('rejects site namespace destructuring without changing a safe page call', () => {
    const source = `import * as utils from 'drupal-canvas';
export default function Example() {
  const page = utils.getPageData();
  const { branding: { siteName } = {} } = utils.getSiteData();
  return page?.pageTitle || siteName;
}`;
    const result = migrateGetterCalls(source, 'index.jsx');
    expect(result).toMatchObject({ changed: false, source, conversions: [] });
    expect(result.warnings).toEqual([
      expect.stringContaining('`useSiteContext() ?? {}`'),
    ]);
  });

  it.each([
    'condition && (getPageData() ?? {})',
    'condition || (getPageData() ?? {})',
    'condition ?? (getPageData() ?? {})',
    '(condition && getPageData()) ?? {}',
    'condition ? (getPageData() ?? {}) : {}',
    'condition ? {} : (getPageData() ?? {})',
    'condition && getPageData()',
    'condition ?? getPageData()',
  ])('still rejects conditional evaluation: %s', (expression) => {
    const source = `import { getPageData } from 'drupal-canvas';
export default function Example({condition}) { const page = ${expression}; return page; }`;
    const result = migrateGetterCalls(source, 'index.jsx');
    expect(result).toMatchObject({ changed: false, source, conversions: [] });
    expect(result.warnings).toEqual([
      expect.stringContaining('called conditionally'),
    ]);
  });

  it('rejects a getter in a destructuring default even with a null fallback', () => {
    const source = `import { getPageData } from 'drupal-canvas';
export default function Example(props) { const { page = getPageData() ?? {} } = props; return page; }`;
    const result = migrateGetterCalls(source, 'index.jsx');
    expect(result).toMatchObject({ changed: false, source });
    expect(result.warnings).toEqual([
      '`getPageData()` is called conditionally (inside AssignmentPattern)',
    ]);
  });

  it('leaves explicit manual nullable-hook conversion unchanged', () => {
    const source = `import { usePageContext } from 'drupal-canvas/react';
export default function Example() { const { pageTitle = 'Unavailable' } = usePageContext() ?? {}; return pageTitle; }`;
    expect(migrateGetterCalls(source, 'index.jsx')).toMatchObject({
      changed: false,
      source,
      warnings: [],
    });
    const title = (page: { pageTitle?: string } | null) => {
      const { pageTitle = 'Unavailable' } = page ?? {};
      return pageTitle;
    };
    expect(title(null)).toBe('Unavailable');
    expect(title({})).toBe('Unavailable');
    expect(title({ pageTitle: 'Present' })).toBe('Present');
  });
});
