import { describe, expect, it } from 'vitest';
import { parse } from '@babel/parser';

import { diagnoseLegacyApiUsage, migrateGetterCalls } from './getter-codemod';

describe('migrateGetterCalls', () => {
  it('converts a guarded call in a component and rewrites the import', () => {
    const source = `import { getPageData } from 'drupal-canvas';

export default function PageTitle() {
  const { pageTitle } = getPageData() ?? {};
  if (!pageTitle) {
    return null;
  }
  return <h1>{pageTitle}</h1>;
}
`;
    const result = migrateGetterCalls(source, 'index.jsx');
    expect(result.changed).toBe(true);
    expect(result.source)
      .toBe(`import { usePageContext } from 'drupal-canvas/react';

export default function PageTitle() {
  const { pageTitle } = usePageContext() ?? {};
  if (!pageTitle) {
    return null;
  }
  return <h1>{pageTitle}</h1>;
}
`);
    expect(result.conversions).toEqual([
      { from: 'getPageData', to: 'usePageContext', calls: 1 },
    ]);
    expect(result.warnings).toEqual([]);
  });

  it('moves getters imported from the legacy alias to drupal-canvas', () => {
    const source = `import { getSiteData, sortMenu } from '@/lib/drupal-utils';

const Branding = () => {
  const { homeUrl, siteName } = getSiteData().branding;
  return <a href={homeUrl}>{siteName}</a>;
};

export default Branding;
`;
    const result = migrateGetterCalls(source, 'index.jsx');
    expect(result.changed).toBe(true);
    expect(result.source).toBe(`import { sortMenu } from '@/lib/drupal-utils';
import { useSiteContext } from 'drupal-canvas/react';

const Branding = () => {
  const { homeUrl, siteName } = useSiteContext().branding;
  return <a href={homeUrl}>{siteName}</a>;
};

export default Branding;
`);
  });

  it.each([
    ['drupal-canvas/drupal-utils', 'sortMenu', 'getPageData', 'getSiteData'],
    ['drupal-canvas', 'FormattedText, Image', 'getPageData', 'getSiteData'],
    [
      'drupal-canvas/drupal-utils',
      '/* keep this alias */ sortMenu as menu',
      'getPageData as readPage',
      'getSiteData as readSite',
    ],
  ])(
    'removes adjacent trailing getters from %s while retaining %s',
    (module, retained, pageImport, siteImport) => {
      const pageCall = pageImport.split(' as ').at(-1);
      const siteCall = siteImport.split(' as ').at(-1);
      const source = `import { ${retained}, ${pageImport}, ${siteImport} } from '${module}';
// Keep the component comment, too.
export default function Header() {
  const page = ${pageCall}();
  const site = ${siteCall}();
  return <h1>{page.pageTitle}{site.branding.siteName}</h1>;
}
`;
      const result = migrateGetterCalls(source, 'index.jsx');
      expect(result.changed).toBe(true);
      expect(result.warnings).toEqual([]);
      expect(() =>
        parse(result.source, { sourceType: 'module', plugins: ['jsx'] }),
      ).not.toThrow();
      expect(result.source).toContain(retained);
      expect(result.source).toContain('// Keep the component comment, too.');
      expect(result.source).toContain('const page = usePageContext();');
      expect(result.source).toContain('const site = useSiteContext();');
      expect(result.source).not.toMatch(/getPageData|getSiteData/);
      expect(result.conversions).toEqual([
        { from: 'getPageData', to: 'usePageContext', calls: 1 },
        { from: 'getSiteData', to: 'useSiteContext', calls: 1 },
      ]);
      expect(migrateGetterCalls(result.source, 'index.jsx').source).toBe(
        result.source,
      );
    },
  );

  it('replaces a getter-only legacy import and handles aliases', () => {
    const source = `import { getPageData as readPage } from '@/lib/drupal-utils';

export default function Crumbs() {
  const data = readPage();
  return <nav>{data.breadcrumbs.length}</nav>;
}
`;
    const result = migrateGetterCalls(source, 'index.tsx');
    expect(result.changed).toBe(true);
    expect(result.source)
      .toBe(`import { usePageContext } from 'drupal-canvas/react';

export default function Crumbs() {
  const data = usePageContext();
  return <nav>{data.breadcrumbs.length}</nav>;
}
`);
  });

  it('converts namespace member calls', () => {
    const source = `import * as drupal from 'drupal-canvas';

export default function Title() {
  const page = drupal.getPageData();
  return <h1>{page.pageTitle}</h1>;
}
`;
    const result = migrateGetterCalls(source, 'index.jsx');
    expect(result.changed).toBe(true);
    // The hooks are root exports only; the member call becomes a plain hook
    // call with a named import, which the data dependency detection sees.
    expect(result.source).toContain('const page = usePageContext();');
    expect(result.source).toContain(
      "import { usePageContext } from 'drupal-canvas/react';",
    );
    expect(result.source).toContain("import * as drupal from 'drupal-canvas';");
    expect(result.source).not.toContain('drupal.usePageContext');
  });

  it('converts namespace calls on the legacy helper modules to root hook imports', () => {
    const source = `import * as utils from '@/lib/drupal-utils';

export default function Title() {
  const page = utils.getPageData();
  return <h1>{page.pageTitle}</h1>;
}
`;
    const result = migrateGetterCalls(source, 'index.jsx');
    expect(result.changed).toBe(true);
    expect(result.source).toContain('const page = usePageContext();');
    expect(result.source).toContain(
      "import { usePageContext } from 'drupal-canvas/react';",
    );
    expect(result.source).not.toContain('utils.usePageContext');
  });

  it('reuses an existing hook import instead of importing it twice', () => {
    const source = `import { usePageContext } from 'drupal-canvas/react';
import { getPageData } from '@/lib/drupal-utils';

export default function Title() {
  const page = getPageData();
  const again = usePageContext();
  return <h1>{page.pageTitle}{again.pageTitle}</h1>;
}
`;
    const result = migrateGetterCalls(source, 'index.jsx');
    expect(result.changed).toBe(true);
    expect(result.source.match(/usePageContext/g)).toHaveLength(3);
    expect(result.source).toContain(
      "import { usePageContext } from 'drupal-canvas/react';",
    );
    expect(result.source).not.toContain('usePageContext, usePageContext');
    expect(result.source).not.toContain('drupal-utils');
  });

  it('leaves a file unchanged when a local declaration shadows the namespace import', () => {
    const source = `import * as drupal from 'drupal-canvas';

export default function Title({ drupal }) {
  const page = drupal.getPageData();
  return <h1>{page.pageTitle}</h1>;
}
`;
    const result = migrateGetterCalls(source, 'index.jsx');
    expect(result.changed).toBe(false);
    expect(result.warnings).toEqual([
      expect.stringContaining('`drupal` is declared more than once'),
    ]);
  });

  it('leaves a file unchanged when the namespace is used in an unverifiable way', () => {
    const computed = `import * as drupal from 'drupal-canvas';

export default function Title() {
  const page = drupal['getPageData']();
  return <h1>{page.pageTitle}</h1>;
}
`;
    expect(migrateGetterCalls(computed, 'index.jsx').changed).toBe(false);
    const asValue = `import * as drupal from 'drupal-canvas';

const api = drupal;
export default function Title() {
  const page = drupal.getPageData();
  return <h1>{page.pageTitle}{api.getSiteData().branding.siteName}</h1>;
}
`;
    const result = migrateGetterCalls(asValue, 'index.jsx');
    expect(result.changed).toBe(false);
    expect(result.warnings).toEqual([
      expect.stringContaining('cannot be verified'),
    ]);
  });

  it('recognizes React wrappers by their binding and only their component argument', () => {
    // `memo` from somewhere else, or shadowed, is not React's.
    const foreign = `import { memo } from './cache';
import { getPageData } from 'drupal-canvas';

const Title = memo(() => <h1>{getPageData().pageTitle}</h1>);
export default Title;
`;
    expect(migrateGetterCalls(foreign, 'index.jsx').changed).toBe(false);
    const shadowed = `import { memo } from 'react';
import { getPageData } from 'drupal-canvas';

function memo(fn) { return fn; }
const Title = memo(() => <h1>{getPageData().pageTitle}</h1>);
export default Title;
`;
    expect(migrateGetterCalls(shadowed, 'index.jsx').changed).toBe(false);
    // A comparator callback is not the component.
    const comparator = `import { memo } from 'react';
import { getPageData } from 'drupal-canvas';

const Title = memo(
  (props) => <h1>{props.title}</h1>,
  (a, b) => getPageData().pageTitle === a.title,
);
export default Title;
`;
    const result = migrateGetterCalls(comparator, 'index.jsx');
    expect(result.changed).toBe(false);
    expect(result.warnings).toEqual([
      expect.stringContaining('not a component or custom hook'),
    ]);
    // Aliased and namespace React imports are React's wrappers.
    const aliased = `import * as R from 'react';
import { forwardRef as fr } from 'react';
import { getPageData } from 'drupal-canvas';

export const A = R.memo(() => <h1>{getPageData().pageTitle}</h1>);
export const B = fr((props, ref) => <h1 ref={ref}>{getPageData().pageTitle}</h1>);
`;
    expect(migrateGetterCalls(aliased, 'index.jsx').changed).toBe(true);
  });

  it('leaves a file unchanged when the replacement name is shadowed anywhere', () => {
    const source = `import { usePageContext } from 'drupal-canvas/react';
import { getPageData } from '@/lib/drupal-utils';

export default function Title({ usePageContext }) {
  const page = getPageData();
  return <h1>{page.pageTitle}{usePageContext}</h1>;
}
`;
    const result = migrateGetterCalls(source, 'index.jsx');
    expect(result.changed).toBe(false);
    expect(result.warnings).toEqual([
      expect.stringContaining('`usePageContext` is already declared'),
    ]);
  });

  it("decides component positions syntactically, not by a callback's own name", () => {
    const namedComparator = `import { memo } from 'react';
import { getPageData } from 'drupal-canvas';

const Title = memo(() => null, function Compare() { return getPageData(); });
export default Title;
`;
    const comparator = migrateGetterCalls(namedComparator, 'index.jsx');
    expect(comparator.changed).toBe(false);
    expect(comparator.warnings).toEqual([
      expect.stringContaining('not a component or custom hook'),
    ]);
    const namedCallback = `import { getPageData } from 'drupal-canvas';
import { registerCallback } from './registry';

registerCallback(function Header() { return getPageData().pageTitle; });
`;
    expect(migrateGetterCalls(namedCallback, 'index.jsx').changed).toBe(false);
    // Declarations, initializers, and default exports are component
    // positions, whatever the function expression is called.
    const positions = `import { memo } from 'react';
import { getPageData } from 'drupal-canvas';

const Title = function named() { return <h1>{getPageData().pageTitle}</h1>; };
export function Heading() { return <h2>{getPageData().pageTitle}</h2>; }
export default memo(function Wrapped() { return <h3>{getPageData().pageTitle}</h3>; });
`;
    const result = migrateGetterCalls(positions, 'index.jsx');
    expect(result.changed).toBe(true);
    expect(result.conversions).toEqual([
      { from: 'getPageData', to: 'usePageContext', calls: 3 },
    ]);
  });

  it('leaves a file unchanged when a getter is re-exported', () => {
    const source = `import { getPageData } from 'drupal-canvas';

export { getPageData };
export default function Header() {
  return <h1>{getPageData().pageTitle}</h1>;
}
`;
    const result = migrateGetterCalls(source, 'index.jsx');
    expect(result.changed).toBe(false);
    expect(result.usesGetters).toBe(true);
    expect(result.warnings).toEqual([
      expect.stringContaining('`getPageData` is re-exported'),
    ]);
    const aliased = `import { getPageData } from 'drupal-canvas';

export { getPageData as pageData };
`;
    expect(migrateGetterCalls(aliased, 'index.jsx').changed).toBe(false);
  });

  it('does not treat arbitrary call wrappers as components', () => {
    const source = `import { getPageData } from 'drupal-canvas';
import { registerCallback } from './registry';

const Data = registerCallback(() => getPageData());
export default Data;
`;
    const result = migrateGetterCalls(source, 'index.jsx');
    expect(result.changed).toBe(false);
    expect(result.warnings).toEqual([
      expect.stringContaining('not a component or custom hook'),
    ]);
    const wrapped = `import React from 'react';
import { getPageData } from 'drupal-canvas';

const Title = React.memo(() => <h1>{getPageData().pageTitle}</h1>);
export default Title;
`;
    expect(migrateGetterCalls(wrapped, 'index.jsx').changed).toBe(true);
  });

  it('converts both getters and keeps a diagnostic for the client constructor', () => {
    const source = `import { getPageData, getSiteData, JsonApiClient } from 'drupal-canvas';
import useSWR from 'swr';

const client = new JsonApiClient();

export default function Related() {
  const page = getPageData();
  const site = getSiteData();
  const { data } = useSWR('related', () => client.getCollection('node--article'));
  return <p>{site.branding.siteName} {page.pageTitle} {data?.length}</p>;
}
`;
    const result = migrateGetterCalls(source, 'index.jsx');
    expect(result.changed).toBe(true);
    expect(result.source).toContain(
      "import { JsonApiClient } from 'drupal-canvas';\nimport { usePageContext, useSiteContext } from 'drupal-canvas/react';",
    );
    expect(result.source).toContain('const page = usePageContext();');
    expect(result.source).toContain('const site = useSiteContext();');
    expect(result.source).toContain('const client = new JsonApiClient();');
    expect(result.constructsClient).toBe(true);
    expect(result.warnings).toEqual([
      expect.stringContaining(
        '`new JsonApiClient()` is not migrated automatically',
      ),
    ]);
  });

  it.each([
    [
      'a conditional call',
      `import { getPageData } from 'drupal-canvas';
export default function C({ show }) {
  const title = show ? getPageData().pageTitle : '';
  return <h1>{title}</h1>;
}`,
      'called conditionally',
    ],
    [
      'a call after an early return',
      `import { getPageData } from 'drupal-canvas';
export default function C({ show }) {
  if (!show) {
    return null;
  }
  const { pageTitle } = getPageData();
  return <h1>{pageTitle}</h1>;
}`,
      'after a possible early return',
    ],
    [
      'a call with an argument',
      `import { getPageData } from 'drupal-canvas';
export default function C() {
  const data = getPageData('v0');
  return <h1>{data.pageTitle}</h1>;
}`,
      'called with arguments',
    ],
    [
      'a call outside a component',
      `import { getSiteData } from 'drupal-canvas';
const site = getSiteData();
export default function C() {
  return <h1>{site.branding.siteName}</h1>;
}`,
      'module level',
    ],
    [
      'a call inside a helper function',
      `import { getPageData } from 'drupal-canvas';
function readTitle() {
  return getPageData().pageTitle;
}
export default function C() {
  return <h1>{readTitle()}</h1>;
}`,
      'not a component or custom hook',
    ],
    [
      'a call inside an event handler',
      `import { getPageData } from 'drupal-canvas';
export default function C() {
  return <button onClick={() => console.log(getPageData())}>x</button>;
}`,
      'not a component or custom hook',
    ],
    [
      'a getter passed as a value',
      `import { getPageData } from 'drupal-canvas';
import useSWR from 'swr';
export default function C() {
  const { data } = useSWR('page', getPageData);
  return <h1>{data?.pageTitle}</h1>;
}`,
      'used without being called',
    ],
    [
      'a name conflict with the hook',
      `import { getPageData } from 'drupal-canvas';
const usePageContext = () => ({ pageTitle: 'local' });
export default function C() {
  const { pageTitle } = getPageData() ?? {};
  return <h1>{pageTitle}</h1>;
}`,
      'already declared',
    ],
    [
      'a shadowed getter binding',
      `import { getPageData } from 'drupal-canvas';
export default function C() {
  const getPageData = () => ({ pageTitle: 'local' });
  const { pageTitle } = getPageData();
  return <h1>{pageTitle}</h1>;
}`,
      'declared more than once',
    ],
  ])('leaves a file with %s unchanged', (_label, source, reason) => {
    const result = migrateGetterCalls(source, 'index.jsx');
    expect(result.changed).toBe(false);
    expect(result.source).toBe(source);
    expect(result.usesGetters).toBe(true);
    expect(result.warnings.join('\n')).toContain(reason);
  });

  it('is all-or-nothing per file', () => {
    const source = `import { getPageData, getSiteData } from 'drupal-canvas';
export default function C({ show }) {
  const site = getSiteData();
  const title = show ? getPageData().pageTitle : '';
  return <h1>{site.branding.siteName} {title}</h1>;
}`;
    const result = migrateGetterCalls(source, 'index.jsx');
    expect(result.changed).toBe(false);
    expect(result.source).toBe(source);
  });

  it('converts custom hooks and memoized components', () => {
    const source = `import { memo } from 'react';
import { getSiteData } from 'drupal-canvas';

export function useSiteName() {
  return getSiteData().branding.siteName;
}

const Footer = memo(() => {
  const site = getSiteData();
  return <footer>{site.branding.siteSlogan}</footer>;
});

export default Footer;
`;
    const result = migrateGetterCalls(source, 'index.tsx');
    expect(result.changed).toBe(true);
    expect(result.source).toContain(
      'return useSiteContext().branding.siteName;',
    );
    expect(result.source).toContain('const site = useSiteContext();');
    expect(result.conversions).toEqual([
      { from: 'getSiteData', to: 'useSiteContext', calls: 2 },
    ]);
  });

  it('supports TypeScript sources', () => {
    const source = `import { getPageData } from 'drupal-canvas';

interface Props {
  suffix?: string;
}

export default function Title({ suffix }: Props): JSX.Element | null {
  const page = getPageData() as { pageTitle: string };
  return <h1>{page.pageTitle}{suffix}</h1>;
}
`;
    const result = migrateGetterCalls(source, 'index.tsx');
    expect(result.changed).toBe(true);
    expect(result.source).toContain(
      'const page = usePageContext() as { pageTitle: string };',
    );
  });

  it('leaves files without getters unchanged and reports client construction', () => {
    const plain = `export default function Plain() { return <p>hi</p>; }`;
    expect(migrateGetterCalls(plain, 'index.jsx')).toMatchObject({
      changed: false,
      usesGetters: false,
      warnings: [],
    });
    const client = `import { JsonApiClient } from '@drupal-api-client/json-api-client';
const client = new JsonApiClient('https://example.com');
export default function List() { return null; }`;
    expect(migrateGetterCalls(client, 'index.jsx')).toMatchObject({
      changed: false,
      usesGetters: false,
      constructsClient: true,
    });
    // Importing without calling changes nothing.
    const importOnly = `import { getPageData, getSiteData, JsonApiClient } from 'drupal-canvas';
export default function Nothing() { return null; }`;
    expect(migrateGetterCalls(importOnly, 'index.jsx').changed).toBe(false);
  });

  it('reports unparsable sources instead of throwing', () => {
    const result = migrateGetterCalls(
      'import { getPageData } from',
      'index.jsx',
    );
    expect(result.changed).toBe(false);
    expect(result.warnings[0]).toContain('could not parse');
  });
});

describe('diagnoseLegacyApiUsage', () => {
  it('diagnoses helper modules without rewriting them', () => {
    expect(
      diagnoseLegacyApiUsage(
        `import { getSiteData, JsonApiClient } from 'drupal-canvas';
export const client = new JsonApiClient();
export const siteName = () => getSiteData().branding.siteName;`,
        'lib/site.js',
      ),
    ).toEqual([
      expect.stringContaining('helper modules are not migrated automatically'),
      expect.stringContaining('constructs `new JsonApiClient()`'),
    ]);
    expect(diagnoseLegacyApiUsage('export const x = 1;', 'lib/x.js')).toEqual(
      [],
    );
  });
});
