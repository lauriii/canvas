import { promises as fs } from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';

import { collectImports, parseFileImports } from './import-analyzer';

describe('parseFileImports', () => {
  let tmpDir: string;

  beforeEach(async () => {
    tmpDir = await fs.mkdtemp(
      path.join(os.tmpdir(), 'canvas-import-analyzer-test-'),
    );
  });

  afterEach(async () => {
    await fs.rm(tmpDir, { recursive: true, force: true });
  });

  async function writeFixture(name: string, content: string): Promise<string> {
    const filePath = path.join(tmpDir, name);
    await fs.writeFile(filePath, content);
    return filePath;
  }

  it('categorizes third-party import as third-party', async () => {
    const filePath = await writeFixture(
      'motion.tsx',
      `import { motion } from "motion/react";`,
    );
    const imports = await parseFileImports(filePath);
    expect(imports).toContainEqual(
      expect.objectContaining({
        source: 'motion/react',
        category: 'third-party',
      }),
    );
  });

  it('categorizes @fontsource packages as third-party', async () => {
    const filePath = await writeFixture(
      'inter.tsx',
      `import '@fontsource/inter';`,
    );
    const imports = await parseFileImports(filePath);
    expect(imports).toContainEqual(
      expect.objectContaining({
        source: '@fontsource/inter',
        category: 'third-party',
      }),
    );
  });

  it('categorizes @/ alias imports as alias', async () => {
    const filePath = await writeFixture(
      'alias.tsx',
      `import { formatDate } from "@/lib/utils";`,
    );
    const imports = await parseFileImports(filePath);
    expect(imports).toContainEqual(
      expect.objectContaining({ source: '@/lib/utils', category: 'alias' }),
    );
  });

  it('marks asset imports with appropriate flags', async () => {
    const filePath = await writeFixture(
      'assets.tsx',
      `
        import heroImg from '@/components/hero/hero.jpg';
        import cartIcon from '@/components/cart/cart.svg';
        import '@/utils/styles/carousel.css';
      `,
    );
    const imports = await parseFileImports(filePath);

    expect(imports).toContainEqual(
      expect.objectContaining({
        source: '@/components/hero/hero.jpg',
        isImage: true,
      }),
    );
    expect(imports).toContainEqual(
      expect.objectContaining({
        source: '@/components/cart/cart.svg',
        isSVG: true,
      }),
    );
    expect(imports).toContainEqual(
      expect.objectContaining({
        source: '@/utils/styles/carousel.css',
        isCSS: true,
      }),
    );
  });

  it('categorizes relative imports as relative', async () => {
    const filePath = await writeFixture(
      'relative.tsx',
      `import { foo } from './foo';`,
    );
    const imports = await parseFileImports(filePath);
    expect(imports).toContainEqual(
      expect.objectContaining({ source: './foo', category: 'relative' }),
    );
  });

  it('parses re-exports', async () => {
    const filePath = await writeFixture(
      'reexport.tsx',
      `
        export { foo } from './foo';
        export * from 'motion/react';
      `,
    );
    const imports = await parseFileImports(filePath);

    expect(imports).toContainEqual(
      expect.objectContaining({ source: './foo', category: 'relative' }),
    );
    expect(imports).toContainEqual(
      expect.objectContaining({
        source: 'motion/react',
        category: 'third-party',
      }),
    );
  });

  it('preserves CSS subpath imports like swiper/css', async () => {
    const filePath = await writeFixture(
      'slider.tsx',
      `
        import Swiper from 'swiper';
        import 'swiper/css';
      `,
    );
    const imports = await parseFileImports(filePath);

    // Both swiper and swiper/css should be detected as third-party
    expect(imports).toContainEqual(
      expect.objectContaining({ source: 'swiper', category: 'third-party' }),
    );
    expect(imports).toContainEqual(
      expect.objectContaining({
        source: 'swiper/css',
        category: 'third-party',
      }),
    );
  });
});

describe('collectImports', () => {
  let tmpDir: string;

  beforeEach(async () => {
    tmpDir = await fs.mkdtemp(
      path.join(os.tmpdir(), 'canvas-collect-imports-test-'),
    );
  });

  afterEach(async () => {
    await fs.rm(tmpDir, { recursive: true, force: true });
  });

  async function writeFixture(name: string, content: string): Promise<string> {
    const dir = path.dirname(path.join(tmpDir, name));
    await fs.mkdir(dir, { recursive: true });
    const filePath = path.join(tmpDir, name);
    await fs.writeFile(filePath, content);
    return filePath;
  }

  it('excludes Canvas builtin alias imports from alias results', async () => {
    const entryFile = await writeFixture(
      'components/button/index.tsx',
      `
        import { cn } from "@/lib/utils";
        import { FormattedText } from "@/lib/FormattedText";
        import { myHelper } from "@/lib/my-helper";
      `,
    );
    // Only provide the non-builtin local file
    await writeFixture('lib/my-helper.ts', 'export const myHelper = 1;');

    const result = await collectImports(
      [entryFile],
      path.join(tmpDir, 'components'),
      tmpDir,
    );

    // Builtin aliases should not appear in aliasImports or unresolvedAliasImports
    expect(result.aliasImports.has('@/lib/utils')).toBe(false);
    expect(result.aliasImports.has('@/lib/FormattedText')).toBe(false);
    expect(result.unresolvedAliasImports.has('@/lib/utils')).toBe(false);
    expect(result.unresolvedAliasImports.has('@/lib/FormattedText')).toBe(
      false,
    );
    // Non-builtin alias should still be collected
    expect(result.aliasImports.has('@/lib/my-helper')).toBe(true);
  });
});
