import { promises as fs } from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { afterEach, describe, expect, it } from 'vitest';

import { resolveCanvasConfig } from './config';

import type { CanvasConfigWarning } from './config';

async function makeTempDir(): Promise<string> {
  return fs.mkdtemp(path.join(os.tmpdir(), 'canvas-discovery-config-'));
}

async function writeFile(filePath: string, content = ''): Promise<void> {
  await fs.mkdir(path.dirname(filePath), { recursive: true });
  await fs.writeFile(filePath, content, 'utf-8');
}

const tempDirs: string[] = [];

afterEach(async () => {
  await Promise.all(
    tempDirs.map((dir) => fs.rm(dir, { recursive: true, force: true })),
  );
  tempDirs.length = 0;
});

describe('resolveCanvasConfig', () => {
  it('uses the new default global CSS path when no config file exists', async () => {
    const root = await makeTempDir();
    tempDirs.push(root);

    const warnings: CanvasConfigWarning[] = [];
    const config = resolveCanvasConfig({
      hostRoot: root,
      onWarning: (warning) => warnings.push(warning),
    });

    expect(config.globalCssPath).toBe('src/global.css');
    expect(warnings).toEqual([]);
  });

  it('falls back to the legacy global CSS path when only the legacy file exists', async () => {
    const root = await makeTempDir();
    tempDirs.push(root);
    await writeFile(path.join(root, 'src/components/global.css'));

    const warnings: CanvasConfigWarning[] = [];
    const config = resolveCanvasConfig({
      hostRoot: root,
      onWarning: (warning) => warnings.push(warning),
    });

    expect(config.globalCssPath).toBe('./src/components/global.css');
    expect(warnings).toEqual([
      expect.objectContaining({
        code: 'legacy_default_global_css_path',
        path: './src/components/global.css',
      }),
    ]);
  });

  it('uses the new default global CSS path when both default files exist', async () => {
    const root = await makeTempDir();
    tempDirs.push(root);
    await writeFile(path.join(root, 'src/global.css'));
    await writeFile(path.join(root, 'src/components/global.css'));

    const warnings: CanvasConfigWarning[] = [];
    const config = resolveCanvasConfig({
      hostRoot: root,
      onWarning: (warning) => warnings.push(warning),
    });

    expect(config.globalCssPath).toBe('src/global.css');
    expect(warnings).toEqual([]);
  });

  it('falls back for omitted globalCssPath and legacy-only CSS', async () => {
    const root = await makeTempDir();
    tempDirs.push(root);
    await writeFile(
      path.join(root, 'canvas.config.json'),
      JSON.stringify({ componentDir: 'src/components' }),
    );
    await writeFile(path.join(root, 'src/components/global.css'));

    const warnings: CanvasConfigWarning[] = [];
    const config = resolveCanvasConfig({
      hostRoot: root,
      onWarning: (warning) => warnings.push(warning),
    });

    expect(config.globalCssPath).toBe('./src/components/global.css');
    expect(warnings).toHaveLength(1);
  });

  it('uses default sync settings when no config file exists', async () => {
    const root = await makeTempDir();
    tempDirs.push(root);

    const config = resolveCanvasConfig({ hostRoot: root });

    expect(config.sync).toEqual({
      pages: true,
      contentTemplates: true,
      regions: true,
    });
  });

  it('uses sync settings from canvas.config.json', async () => {
    const root = await makeTempDir();
    tempDirs.push(root);
    await writeFile(
      path.join(root, 'canvas.config.json'),
      JSON.stringify({
        sync: {
          pages: false,
          contentTemplates: false,
          regions: false,
        },
      }),
    );

    const config = resolveCanvasConfig({ hostRoot: root });

    expect(config.sync).toEqual({
      pages: false,
      contentTemplates: false,
      regions: false,
    });
  });

  it('fills omitted sync settings with defaults', async () => {
    const root = await makeTempDir();
    tempDirs.push(root);
    await writeFile(
      path.join(root, 'canvas.config.json'),
      JSON.stringify({ sync: { pages: false } }),
    );

    const config = resolveCanvasConfig({ hostRoot: root });

    expect(config.sync).toEqual({
      pages: false,
      contentTemplates: true,
      regions: true,
    });
  });

  it('uses explicit globalCssPath without warning', async () => {
    const root = await makeTempDir();
    tempDirs.push(root);
    await writeFile(
      path.join(root, 'canvas.config.json'),
      JSON.stringify({ globalCssPath: 'src/components/global.css' }),
    );
    await writeFile(path.join(root, 'src/components/global.css'));

    const warnings: CanvasConfigWarning[] = [];
    const config = resolveCanvasConfig({
      hostRoot: root,
      onWarning: (warning) => warnings.push(warning),
    });

    expect(config.globalCssPath).toBe('src/components/global.css');
    expect(warnings).toEqual([]);
  });
});
