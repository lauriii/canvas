import { promises as fs } from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { afterEach, describe, expect, it } from 'vitest';

import {
  isResolvedByImportMap,
  readSiteImports,
  resolveFromImportMap,
  SITE_IMPORTS_FILE,
  writeSiteImports,
} from './site-imports';

const tempDirs: string[] = [];

afterEach(async () => {
  await Promise.all(
    tempDirs.map((dir) => fs.rm(dir, { recursive: true, force: true })),
  );
  tempDirs.length = 0;
});

async function makeTempDir(): Promise<string> {
  const dir = await fs.mkdtemp(path.join(os.tmpdir(), 'canvas-site-imports-'));
  tempDirs.push(dir);
  return dir;
}

describe('site imports', () => {
  it('round-trips the recorded import map', async () => {
    const root = await makeTempDir();
    const imports = {
      react: '/modules/contrib/canvas/react.js',
      'canvas_forms/useCanvasForm': '/modules/custom/canvas_forms/js/form.js',
    };

    await writeSiteImports(root, imports);
    await expect(readSiteImports(root)).resolves.toEqual(imports);
  });

  it('reports unknown rather than empty when nothing was pulled', async () => {
    const root = await makeTempDir();
    await expect(readSiteImports(root)).resolves.toBeNull();
  });

  it('reports unknown when the file is unreadable', async () => {
    const root = await makeTempDir();
    await fs.writeFile(path.join(root, SITE_IMPORTS_FILE), 'not json', 'utf-8');
    await expect(readSiteImports(root)).resolves.toBeNull();

    await fs.writeFile(path.join(root, SITE_IMPORTS_FILE), '{}', 'utf-8');
    await expect(readSiteImports(root)).resolves.toBeNull();
  });

  it('matches specifiers the way an import map does', () => {
    const imports = {
      'canvas_forms/useCanvasForm': '/modules/custom/canvas_forms/js/form.js',
      'canvas_ui/': '/modules/custom/canvas_ui/js/',
    };

    expect(isResolvedByImportMap('canvas_forms/useCanvasForm', imports)).toBe(
      true,
    );
    // A trailing-slash key maps every specifier beneath it.
    expect(isResolvedByImportMap('canvas_ui/anything/deep', imports)).toBe(
      true,
    );
    // A bare key does not: it is an exact match only.
    expect(isResolvedByImportMap('canvas_forms/useOther', imports)).toBe(false);
    expect(isResolvedByImportMap('canvas_forms', imports)).toBe(false);
    expect(isResolvedByImportMap('react', imports)).toBe(false);
    // Inherited object properties are not entries.
    expect(isResolvedByImportMap('toString', imports)).toBe(false);
  });

  it('resolves specifiers to the mapped URL', () => {
    const imports = {
      'canvas_forms/useCanvasForm': '/modules/custom/canvas_forms/js/form.js',
      'canvas_ui/': '/modules/custom/canvas_ui/js/',
      'canvas_ui/deep/': '/modules/custom/canvas_ui/other/',
    };

    expect(resolveFromImportMap('canvas_forms/useCanvasForm', imports)).toBe(
      '/modules/custom/canvas_forms/js/form.js',
    );
    // A trailing-slash key appends the rest of the specifier.
    expect(resolveFromImportMap('canvas_ui/button.js', imports)).toBe(
      '/modules/custom/canvas_ui/js/button.js',
    );
    // The longest matching prefix wins.
    expect(resolveFromImportMap('canvas_ui/deep/x.js', imports)).toBe(
      '/modules/custom/canvas_ui/other/x.js',
    );
    expect(resolveFromImportMap('react', imports)).toBeNull();
  });
});
