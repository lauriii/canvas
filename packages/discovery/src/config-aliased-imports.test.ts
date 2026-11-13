import { mkdirSync, mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { afterEach, describe, expect, it } from 'vitest';

import { createConfigAliasedImports } from './config-aliased-imports';
import { createRegisteredComponentImports } from './registered-component-imports';

const roots: string[] = [];

afterEach(() => {
  for (const root of roots.splice(0)) {
    rmSync(root, { recursive: true, force: true });
  }
});

const write = (root: string, files: Record<string, string>) => {
  for (const [file, source] of Object.entries(files)) {
    mkdirSync(path.dirname(path.join(root, file)), { recursive: true });
    writeFileSync(path.join(root, file), source);
  }
};

/**
 * Creates a workspace with files, and resolves imports in its project.
 *
 * @param files
 *   The files by path relative to the workspace.
 * @param entries
 *   The entry files of the discovered components.
 * @param project
 *   The project root, relative to the workspace.
 */
const create = (
  files: Record<string, string>,
  entries: string[],
  project = 'app',
) => {
  const workspace = mkdtempSync(path.join(os.tmpdir(), 'canvas-aliases-'));
  roots.push(workspace);
  write(workspace, files);
  const imports = () =>
    createRegisteredComponentImports({
      entryPaths: entries.map((entry) => path.join(workspace, entry)),
      readSource: () => '',
      resolveAliased: createConfigAliasedImports({
        projectRoot: path.join(workspace, project),
      }),
    });
  let current = imports();
  return {
    workspace,
    get checkedFiles() {
      return current.checkedFiles;
    },
    // Starts over, as for another build.
    refresh: () => {
      current = imports();
    },
    resolveFrom: (importer: string, specifier: string) => {
      const module = current.resolve(specifier, path.join(workspace, importer));
      return module && path.relative(workspace, module.id);
    },
  };
};

const CARD = 'app/src/components/card/index.tsx';
const BADGE = 'app/src/components/badge/index.tsx';

describe('createConfigAliasedImports', () => {
  it('resolves the paths of tsconfig.json, with the configuration it extends', () => {
    const { resolveFrom } = create(
      {
        'config/tsconfig.base.json': JSON.stringify({
          compilerOptions: { baseUrl: '../app', paths: { '@/*': ['./src/*'] } },
        }),
        'app/tsconfig.json': `{
          // Comments are allowed.
          "extends": "../config/tsconfig.base.json",
          "compilerOptions": {
            "paths": {
              "@/*": ["./src/*"],
              "#ui/*": ["\${configDir}/src/components/*"]
            }
          }
        }`,
        [CARD]: '',
        [BADGE]: '',
      },
      [CARD, BADGE],
    );

    // Against the inherited baseUrl.
    expect(resolveFrom(CARD, '@/components/badge')).toBe(BADGE);
    expect(resolveFrom(CARD, '#ui/badge')).toBe(BADGE);
    expect(resolveFrom(CARD, '@/components/missing')).toBeNull();
  });

  it('resolves the paths of jsconfig.json in JavaScript projects', () => {
    const { resolveFrom } = create(
      {
        'app/jsconfig.json': JSON.stringify({
          compilerOptions: { paths: { '~/*': ['./components/*'] } },
        }),
        'app/components/card/index.jsx': '',
        'app/components/badge.js': '',
      },
      ['app/components/card/index.jsx', 'app/components/badge.js'],
    );

    expect(resolveFrom('app/components/card/index.jsx', '~/badge')).toBe(
      'app/components/badge.js',
    );
    // Without baseUrl, imports that match no pattern are not resolved.
    expect(resolveFrom('app/components/card/index.jsx', 'badge')).toBeNull();
  });

  it('resolves non-relative imports against baseUrl', () => {
    const { resolveFrom } = create(
      {
        'app/tsconfig.json': JSON.stringify({
          compilerOptions: { baseUrl: 'src' },
        }),
        [CARD]: '',
        [BADGE]: '',
      },
      [CARD, BADGE],
    );

    expect(resolveFrom(CARD, 'components/badge')).toBe(BADGE);
    expect(resolveFrom(CARD, 'react')).toBeNull();
  });

  it('applies the nearest configuration within the project', () => {
    const { resolveFrom } = create(
      {
        // Outside the project.
        'tsconfig.json': JSON.stringify({
          compilerOptions: { paths: { 'outer/*': ['./app/src/components/*'] } },
        }),
        'app/tsconfig.json': JSON.stringify({
          compilerOptions: { paths: { '@/*': ['./src/*'] } },
        }),
        // In the same directory, tsconfig.json applies.
        'app/src/components/nested/tsconfig.json': JSON.stringify({
          compilerOptions: { paths: { '~/*': ['../*'] } },
        }),
        'app/src/components/nested/jsconfig.json': JSON.stringify({
          compilerOptions: { paths: { 'js/*': ['../*'] } },
        }),
        'app/src/components/nested/index.tsx': '',
        [CARD]: '',
        [BADGE]: '',
      },
      ['app/src/components/nested/index.tsx', CARD, BADGE],
    );
    const nested = 'app/src/components/nested/index.tsx';

    expect(resolveFrom(nested, '~/badge')).toBe(BADGE);
    expect(resolveFrom(nested, 'js/badge')).toBeNull();
    expect(resolveFrom(nested, '@/components/badge')).toBeNull();
    expect(resolveFrom(CARD, '@/components/badge')).toBe(BADGE);
    expect(resolveFrom(CARD, 'outer/badge')).toBeNull();
  });

  it('does not apply configuration outside the project', () => {
    const { resolveFrom } = create(
      {
        // Of the repository that contains the project, for example.
        'tsconfig.json': JSON.stringify({
          compilerOptions: { paths: { '@/*': ['./app/src/*'] } },
        }),
        [CARD]: '',
        [BADGE]: '',
      },
      [CARD, BADGE],
    );

    expect(resolveFrom(CARD, '@/components/badge')).toBeNull();
    expect(resolveFrom(CARD, '../badge')).toBe(BADGE);
  });

  it('resolves the most specific pattern, and its candidates in order', () => {
    const { resolveFrom } = create(
      {
        'app/tsconfig.json': JSON.stringify({
          compilerOptions: {
            paths: {
              '@/*': ['./src/*'],
              '@/components/*': ['./src/overrides/*', './src/components/*'],
            },
          },
        }),
        // A helper that the first candidate resolves to.
        'app/src/overrides/badge.ts': '',
        [CARD]: '',
        [BADGE]: '',
        'app/src/components/icon/index.tsx': '',
      },
      [CARD, BADGE, 'app/src/components/icon/index.tsx'],
    );

    expect(resolveFrom(CARD, '@/components/badge')).toBeNull();
    expect(resolveFrom(CARD, '@/components/icon')).toBe(
      'app/src/components/icon/index.tsx',
    );
  });

  it('applies configuration that is created, changed, or deleted', () => {
    const { workspace, resolveFrom, refresh, checkedFiles } = create(
      { [CARD]: '', [BADGE]: '' },
      [CARD, BADGE],
    );
    expect(resolveFrom(CARD, '@/components/badge')).toBeNull();
    // The missing configuration files that would apply.
    expect(
      [...checkedFiles].map((file) => path.relative(workspace, file)),
    ).toEqual(
      expect.arrayContaining([
        'app/src/components/card/tsconfig.json',
        'app/tsconfig.json',
        'app/jsconfig.json',
      ]),
    );

    write(workspace, {
      'app/jsconfig.json': JSON.stringify({
        compilerOptions: { paths: { '@/*': ['./src/*'] } },
      }),
    });
    refresh();
    expect(resolveFrom(CARD, '@/components/badge')).toBe(BADGE);

    rmSync(path.join(workspace, 'app/jsconfig.json'));
    refresh();
    expect(resolveFrom(CARD, '@/components/badge')).toBeNull();
  });
});
