import { promises as fs } from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { compilePartialCss } from 'tailwindcss-in-browser';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { compileJS } from '../lib/compile-js';
import { transformCss } from '../lib/transform-css';
import { buildComponent } from './build-component';
import { getGlobalCss } from './build-tailwind';
import { validateComponent } from './validate';

// Mock compile and CSS functions before importing buildComponent
vi.mock('../lib/compile-js', () => ({ compileJS: vi.fn() }));
vi.mock('../lib/transform-css', () => ({ transformCss: vi.fn() }));
vi.mock('tailwindcss-in-browser', () => ({ compilePartialCss: vi.fn() }));
vi.mock('./build-tailwind', () => ({ getGlobalCss: vi.fn() }));
vi.mock('./validate', () => ({ validateComponent: vi.fn() }));

describe('buildComponent', () => {
  let tmpDir: string;

  beforeEach(async () => {
    tmpDir = await fs.mkdtemp(
      path.join(os.tmpdir(), 'canvas-build-component-test-'),
    );
    // Re-apply default implementations after mockReset clears them
    vi.mocked(compileJS).mockReturnValue('// compiled js\n');
    vi.mocked(transformCss).mockResolvedValue('/* transformed css */');
    vi.mocked(compilePartialCss).mockResolvedValue('/* compiled tw */');
    vi.mocked(getGlobalCss).mockResolvedValue('');
    vi.mocked(validateComponent).mockResolvedValue({
      itemName: '',
      success: true,
      details: [],
    });
  });

  afterEach(async () => {
    await fs.rm(tmpDir, { recursive: true, force: true });
    vi.restoreAllMocks();
  });

  /**
   * Create a minimal DiscoveredComponent fixture in tmpDir.
   */
  async function makeComponent(
    name: string,
    { hasCss = false, kind = 'index' as 'index' | 'named' } = {},
  ) {
    const compDir = path.join(tmpDir, name);
    await fs.mkdir(compDir, { recursive: true });

    const jsEntryPath = path.join(compDir, 'index.tsx');
    await fs.writeFile(
      jsEntryPath,
      `import { motion } from "motion/react";
import { formatDate } from "@/lib/utils";
import heroImg from '@/components/hero/hero.jpg';
export default function ${name}() { return <div>{heroImg}</div>; }`,
    );

    const metadataPath = path.join(compDir, 'component.yml');
    await fs.writeFile(
      metadataPath,
      `name: ${name}\nmachineName: ${name}\nstatus: stable\nprops:\n  properties: {}\nslots: {}\n`,
    );

    let cssEntryPath: string | undefined;
    if (hasCss) {
      cssEntryPath = path.join(compDir, 'index.css');
      await fs.writeFile(cssEntryPath, `.${name} { color: red; }`);
    }

    return {
      name,
      kind,
      relativeDirectory: name,
      jsEntryPath,
      cssEntryPath,
      metadataPath,
      machineName: name,
    };
  }

  it('creates the output components/<name>/ directory', async () => {
    const component = await makeComponent('button');
    await buildComponent(component as any, true, tmpDir);

    const outDir = path.join(tmpDir, 'components', 'button');
    const stat = await fs.stat(outDir);
    expect(stat.isDirectory()).toBe(true);
  });

  it('writes compiled JS to components/<name>/index.js', async () => {
    const component = await makeComponent('button');
    await buildComponent(component as any, true, tmpDir);

    const jsPath = path.join(tmpDir, 'components', 'button', 'index.js');
    const content = await fs.readFile(jsPath, 'utf-8');
    expect(content).toBe('// compiled js\n');
  });

  it('copies component.yml to components/<name>/', async () => {
    const component = await makeComponent('button');
    await buildComponent(component as any, true, tmpDir);

    const metaPath = path.join(tmpDir, 'components', 'button', 'component.yml');
    const exists = await fs
      .access(metaPath)
      .then(() => true)
      .catch(() => false);
    expect(exists).toBe(true);
  });

  it('writes compiled CSS when cssEntryPath is provided', async () => {
    const component = await makeComponent('card', { hasCss: true });
    await buildComponent(component as any, true, tmpDir);

    const cssPath = path.join(tmpDir, 'components', 'card', 'index.css');
    const content = await fs.readFile(cssPath, 'utf-8');
    expect(content).toBe('/* transformed css */');
  });

  it('does not write CSS file when no cssEntryPath', async () => {
    const component = await makeComponent('hero', { hasCss: false });
    await buildComponent(component as any, true, tmpDir);

    const cssPath = path.join(tmpDir, 'components', 'hero', 'index.css');
    const exists = await fs
      .access(cssPath)
      .then(() => true)
      .catch(() => false);
    expect(exists).toBe(false);
  });
});
