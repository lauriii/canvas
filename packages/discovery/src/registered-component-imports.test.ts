import {
  mkdirSync,
  mkdtempSync,
  readFileSync,
  rmSync,
  writeFileSync,
} from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { createRegisteredComponentImports } from './registered-component-imports';

const roots: string[] = [];

afterEach(() => {
  for (const root of roots.splice(0)) {
    rmSync(root, { recursive: true, force: true });
  }
});

/**
 * Creates a project with files, and the imports of its discovered components.
 *
 * @param files
 *   The files by path relative to the project root.
 * @param entries
 *   The entry files of the discovered components.
 */
const create = (files: Record<string, string>, entries: string[]) => {
  const root = mkdtempSync(path.join(os.tmpdir(), 'canvas-imports-'));
  roots.push(root);
  for (const [file, source] of Object.entries(files)) {
    mkdirSync(path.dirname(path.join(root, file)), { recursive: true });
    writeFileSync(path.join(root, file), source);
  }
  const readSource = vi.fn((file: string) => readFileSync(file, 'utf-8'));
  const imports = createRegisteredComponentImports({
    entryPaths: entries.map((entry) => path.join(root, entry)),
    readSource,
  });
  return {
    root,
    readSource,
    ...imports,
    // The entry file, relative to the project root, that an import resolves to.
    resolveFrom: (importer: string, specifier: string) => {
      const module = imports.resolve(specifier, path.join(root, importer));
      return module && path.relative(root, module.id);
    },
  };
};

describe('createRegisteredComponentImports', () => {
  it('follows relative imports of discovered components, also in JavaScript', () => {
    const { resolveFrom } = create(
      {
        'components/card/index.jsx': '',
        'components/card/title.jsx': '',
        'components/icon/index.js': '',
        'components/ui.kit/index.js': '',
        'components/card/footer/index.jsx': '',
      },
      [
        'components/card/index.jsx',
        'components/card/title.jsx',
        'components/icon/index.js',
        'components/ui.kit/index.js',
        'components/card/footer/index.jsx',
      ],
    );
    const card = 'components/card/index.jsx';

    expect(resolveFrom(card, './title')).toBe('components/card/title.jsx');
    expect(resolveFrom(card, './title.jsx')).toBe('components/card/title.jsx');
    expect(resolveFrom(card, '../icon')).toBe('components/icon/index.js');
    expect(resolveFrom(card, '../icon/')).toBe('components/icon/index.js');
    expect(resolveFrom(card, './footer')).toBe(
      'components/card/footer/index.jsx',
    );
    // A directory with a dot in its name.
    expect(resolveFrom(card, '../ui.kit')).toBe('components/ui.kit/index.js');
  });

  it('resolves JavaScript extensions to TypeScript files that exist instead', () => {
    const { resolveFrom } = create(
      {
        'components/card/index.jsx': '',
        'components/card/badge.tsx': '',
        'components/card/title.tsx': '',
        'components/card/both.js': '',
        'components/card/both.ts': '',
        'components/card/helper.js': '',
        'components/card/helper.ts': '',
      },
      [
        'components/card/index.jsx',
        'components/card/badge.tsx',
        'components/card/title.tsx',
        'components/card/both.js',
        'components/card/both.ts',
        'components/card/helper.ts',
      ],
    );
    // Also in JavaScript modules.
    const card = 'components/card/index.jsx';

    expect(resolveFrom(card, './badge.js')).toBe('components/card/badge.tsx');
    expect(resolveFrom(card, './title.jsx')).toBe('components/card/title.tsx');
    // The existing file first.
    expect(resolveFrom(card, './both.js')).toBe('components/card/both.js');
    // Also when it is a helper.
    expect(resolveFrom(card, './helper.js')).toBeNull();
  });

  it('does not follow imports that load other files', () => {
    const { resolveFrom, readSource } = create(
      {
        'components/card/index.tsx': '',
        'components/card/index.css': '',
        // A helper that shadows the directory of a discovered component.
        'components/badge.ts': '',
        'components/badge/index.tsx': '',
        // A component that is not discovered.
        'components/draft/index.tsx': '',
        // Data that shadows the directory of a discovered component.
        'components/icon.json': '{}',
        'components/icon/index.tsx': '',
      },
      [
        'components/card/index.tsx',
        'components/badge/index.tsx',
        'components/icon/index.tsx',
      ],
    );
    const card = 'components/card/index.tsx';

    for (const specifier of [
      '../badge',
      '../icon',
      '../draft',
      './index.css',
      '../badge/index.tsx?raw',
      '../badge/index.tsx#other',
      '../missing',
      // Without a resolution of non-relative imports.
      '@/components/badge',
      'badge',
    ]) {
      expect(resolveFrom(card, specifier)).toBeNull();
    }
    expect(readSource).not.toHaveBeenCalled();
    // Once the helper is gone.
    expect(
      create(
        {
          'components/card/index.tsx': '',
          'components/badge/index.tsx': '',
        },
        ['components/card/index.tsx', 'components/badge/index.tsx'],
      ).resolveFrom(card, '../badge'),
    ).toBe('components/badge/index.tsx');
  });

  it('does not follow imports of directories with a package.json file', () => {
    const { root, resolveFrom, checkedFiles } = create(
      {
        'components/card/index.tsx': '',
        'components/kit/package.json': '{ "main": "./index.tsx" }',
        'components/kit/index.tsx': '',
        'components/badge/index.tsx': '',
      },
      [
        'components/card/index.tsx',
        'components/kit/index.tsx',
        'components/badge/index.tsx',
      ],
    );
    const card = 'components/card/index.tsx';

    expect(resolveFrom(card, '../kit')).toBeNull();
    // An import of the entry file itself.
    expect(resolveFrom(card, '../kit/index')).toBe('components/kit/index.tsx');
    expect(resolveFrom(card, '../badge')).toBe('components/badge/index.tsx');
    expect([...checkedFiles].map((file) => path.relative(root, file))).toEqual(
      expect.arrayContaining([
        'components/kit/package.json',
        'components/badge/package.json',
      ]),
    );
  });

  it('records the files that resolution checked', () => {
    const { root, resolve, checkedFiles } = create(
      { 'components/card/index.tsx': '' },
      ['components/card/index.tsx'],
    );
    resolve('./later', path.join(root, 'components/card/index.tsx'));

    const checked = [...checkedFiles].map((file) => path.relative(root, file));
    expect(checked).toContain('components/card/later.tsx');
    expect(checked).toContain('components/card/later/index.jsx');
    expect(
      checked.every((file) => file.startsWith('components/card/later')),
    ).toBe(true);
  });

  it('loads each entry file once, and only JavaScript and TypeScript files', () => {
    const { root, load, resolve, readSource } = create(
      {
        'components/card/index.tsx': '',
        'components/badge/index.tsx': 'export default 1;',
        'components/vue/index.vue': '<template><p /></template>',
      },
      [
        'components/card/index.tsx',
        'components/badge/index.tsx',
        'components/vue/index.vue',
      ],
    );
    const badge = path.join(root, 'components/badge/index.tsx');

    expect(load(badge)).toBe(
      resolve('../badge', path.join(root, 'components/card/index.tsx')),
    );
    expect(readSource).toHaveBeenCalledTimes(1);
    expect(load(path.join(root, 'components/vue/index.vue')).source).toBeNull();
    expect(
      load(path.join(root, 'components/missing/index.tsx')).source,
    ).toBeNull();
  });
});
