import { promises as fs } from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { afterEach, describe, expect, it, vi } from 'vitest';
import {
  discoverCanvasProject,
  resolveCanvasConfig,
} from '@drupal-canvas/discovery';

import { buildCanvasProject } from './build-project';

vi.mock('tailwindcss-in-browser', () => ({
  compilePartialCss: vi.fn(async (source: string) => source),
}));

const currentDir = path.dirname(fileURLToPath(import.meta.url));
const packageRoot = path.resolve(currentDir, '../..');
const repositoryRoot = path.resolve(packageRoot, '../..');
const fixtureRoot = path.join(packageRoot, 'test-fixtures/local-codebases');
const temporaryDirectories: string[] = [];

async function copyFixtureProject(fixtureName: string): Promise<string> {
  const temporaryRoot = await fs.mkdtemp(
    path.join(os.tmpdir(), `canvas-build-${fixtureName}-`),
  );
  const projectRoot = await fs.realpath(temporaryRoot);
  temporaryDirectories.push(projectRoot);

  await fs.cp(path.join(fixtureRoot, fixtureName), projectRoot, {
    recursive: true,
  });
  await fs.symlink(
    path.join(repositoryRoot, 'node_modules'),
    path.join(projectRoot, 'node_modules'),
    'dir',
  );

  return projectRoot;
}

async function withWorkingDirectory<T>(
  directory: string,
  callback: () => Promise<T>,
): Promise<T> {
  const previousDirectory = process.cwd();
  process.chdir(directory);

  try {
    return await callback();
  } finally {
    process.chdir(previousDirectory);
  }
}

async function buildFixtureProject(fixtureName: string) {
  const projectRoot = await copyFixtureProject(fixtureName);
  const config = resolveCanvasConfig({ hostRoot: projectRoot });
  const componentRoot = path.resolve(projectRoot, config.componentDir);

  const discoveryResult = await discoverCanvasProject({
    componentRoot,
    projectRoot,
  });

  const buildResult = await withWorkingDirectory(projectRoot, () =>
    buildCanvasProject({
      projectRoot,
      componentDir: config.componentDir,
      aliasBaseDir: config.aliasBaseDir,
      outputDir: 'dist',
      discoveryResult,
      cleanOutputDir: true,
      buildTailwind: false,
      requireJsEntries: true,
      useLocalGlobalCss: true,
    }),
  );

  return { projectRoot, config, discoveryResult, buildResult };
}

async function readDistFile(
  projectRoot: string,
  relativePath: string,
): Promise<string> {
  return fs.readFile(path.join(projectRoot, 'dist', relativePath), 'utf-8');
}

async function expectManifestFilesExist(
  projectRoot: string,
  entries: Record<string, string>,
): Promise<void> {
  for (const outputPath of Object.values(entries)) {
    await expect(
      fs.access(path.join(projectRoot, 'dist', outputPath.replace('./', ''))),
    ).resolves.toBeUndefined();
  }
}

afterEach(async () => {
  await Promise.all(
    temporaryDirectories.map((directory) =>
      fs.rm(directory, { recursive: true, force: true }),
    ),
  );
  temporaryDirectories.length = 0;
});

describe('buildCanvasProject fixture projects', () => {
  it('builds a default graph with component dependencies, backend metadata, local imports, and vendor artifacts', async () => {
    const { projectRoot, discoveryResult, buildResult } =
      await buildFixtureProject('build-default-graph');

    expect(
      discoveryResult.components.map((component) => component.name),
    ).toEqual(['button', 'card']);
    expect(buildResult.componentResults).toEqual([
      expect.objectContaining({ itemName: 'button', success: true }),
      expect.objectContaining({ itemName: 'card', success: true }),
    ]);

    const card = buildResult.builtComponents.find(
      (component) => component.machineName === 'card',
    );
    expect(card).toBeDefined();
    expect(card?.importedJsComponents).toEqual(['button']);
    expect(card?.componentPayload.dataDependencies).toEqual({
      drupalSettings: ['v0.baseUrl', 'v0.branding'],
    });

    const cardJs = await readDistFile(projectRoot, 'components/card/index.js');
    expect(cardJs).toContain(
      'import.meta.resolve("@/components/card/hero.webp")',
    );
    expect(cardJs).toContain('import.meta.resolve("@/lib/banner.jpg")');
    expect(cardJs).toContain('from "@/components/button"');
    expect(cardJs).toContain('from "@/lib/format"');
    expect(cardJs).toContain('from "drupal-canvas"');

    await expect(
      fs.access(path.join(projectRoot, 'dist/components/card/index.css')),
    ).resolves.toBeUndefined();
    await expect(
      fs.access(path.join(projectRoot, 'dist/components/card/component.yml')),
    ).resolves.toBeUndefined();

    expect(buildResult.manifest.vendor).toEqual({
      'lodash-es': expect.stringMatching(/^\.\/vendor\/lodash-es-[\w-]+\.js$/),
    });
    expect(buildResult.manifest.local).toEqual({
      '@/components/card/hero.webp': expect.stringMatching(
        /^\.\/local\/hero-[\w-]+\.webp$/,
      ),
      '@/lib/banner.jpg': expect.stringMatching(
        /^\.\/local\/banner-[\w-]+\.jpg$/,
      ),
      '@/lib/format': expect.stringMatching(/^\.\/local\/format-[\w-]+\.js$/),
    });
    expect(buildResult.manifest.shared).not.toContain(
      buildResult.manifest.local['@/components/card/hero.webp'],
    );
    expect(buildResult.manifest.shared).not.toContain(
      buildResult.manifest.local['@/lib/banner.jpg'],
    );
    await expectManifestFilesExist(projectRoot, buildResult.manifest.vendor);
    await expectManifestFilesExist(projectRoot, buildResult.manifest.local);
  });

  it('builds custom component and alias roots with named component files', async () => {
    const { projectRoot, config, discoveryResult, buildResult } =
      await buildFixtureProject('build-custom-roots');

    expect(config.componentDir).toBe('app/widgets');
    expect(config.aliasBaseDir).toBe('app');
    expect(
      discoveryResult.components.map((component) => component.name),
    ).toEqual(['banner', 'cta']);
    expect(buildResult.componentResults).toEqual([
      expect.objectContaining({ itemName: 'banner', success: true }),
      expect.objectContaining({ itemName: 'cta', success: true }),
    ]);

    const bannerJs = await readDistFile(
      projectRoot,
      'components/banner/banner.js',
    );
    expect(bannerJs).toContain('from "@/content/label"');
    expect(bannerJs).toContain('from "@/components/cta"');
    expect(bannerJs).toContain(
      'import.meta.resolve("@/components/banner/hero.webp")',
    );
    await expect(
      fs.access(
        path.join(projectRoot, 'dist/components/banner/banner.component.yml'),
      ),
    ).resolves.toBeUndefined();
    await expect(
      fs.access(path.join(projectRoot, 'dist/components/banner/banner.css')),
    ).resolves.toBeUndefined();

    expect(buildResult.manifest.vendor).toEqual({});
    expect(buildResult.manifest.local).toEqual({
      '@/components/banner/hero.webp': expect.stringMatching(
        /^\.\/local\/hero-[\w-]+\.webp$/,
      ),
      '@/content/label': expect.stringMatching(/^\.\/local\/label-[\w-]+\.js$/),
    });
    expect(buildResult.manifest.shared).toEqual([]);
    await expectManifestFilesExist(projectRoot, buildResult.manifest.local);
  });

  it('builds the documented supported imports and assets local codebase', async () => {
    const { projectRoot, discoveryResult, buildResult } =
      await buildFixtureProject('imports-and-assets-supported-local-codebase');
    const componentNames = discoveryResult.components.map(
      (component) => component.name,
    );

    expect(componentNames).toEqual([
      'built-in-package-example',
      'button',
      'component-imports-example',
      'shared-asset-example',
      'shared-code-example',
      'shared-default-export-example',
      'shared-exported-jsx-example',
      'shared-hook-example',
      'shared-react-component-consumer-example',
      'shared-react-component-example',
      'shared-react-node-example',
      'shared-transitive-example',
      'static-assets-example',
      'svg-component-example',
      'svg-url-example',
      'third-party-package-example',
      'third-party-shared-dependency-example',
    ]);
    expect(buildResult.componentResults).toEqual(
      componentNames.map((itemName) =>
        expect.objectContaining({ itemName, success: true }),
      ),
    );

    const componentJs = async (componentName: string) =>
      readDistFile(projectRoot, `components/${componentName}/index.js`);

    await expect(componentJs('component-imports-example')).resolves.toContain(
      'from "@/components/button"',
    );
    await expect(componentJs('built-in-package-example')).resolves.toContain(
      'from "clsx"',
    );
    await expect(componentJs('shared-code-example')).resolves.toContain(
      'from "@/lib/formatTitle"',
    );
    await expect(componentJs('shared-asset-example')).resolves.toContain(
      'from "@/lib/AssetLabel"',
    );
    await expect(
      componentJs('shared-default-export-example'),
    ).resolves.toContain('from "@/lib/DefaultLabel"');
    await expect(componentJs('shared-exported-jsx-example')).resolves.toContain(
      'from "@/lib/exportedIcon"',
    );
    await expect(componentJs('shared-hook-example')).resolves.toContain(
      'from "@/lib/HookLabel"',
    );
    await expect(
      componentJs('shared-react-component-consumer-example'),
    ).resolves.toContain('from "@/lib/ExampleLabel"');
    await expect(
      componentJs('shared-react-component-example'),
    ).resolves.toContain('from "@/lib/ExampleLabel"');
    await expect(componentJs('shared-react-node-example')).resolves.toContain(
      'from "@/lib/ReactNodeLabel"',
    );
    await expect(componentJs('shared-transitive-example')).resolves.toContain(
      'from "@/lib/FormattedLabel"',
    );
    await expect(componentJs('static-assets-example')).resolves.toContain(
      'import.meta.resolve("@/components/static-assets-example/hero.webp")',
    );
    await expect(componentJs('static-assets-example')).resolves.toContain(
      'import.meta.resolve("@/assets/poster.webp")',
    );
    await expect(componentJs('static-assets-example')).resolves.toContain(
      'import.meta.resolve("@/assets/intro.mp4")',
    );
    await expect(componentJs('svg-url-example')).resolves.toContain(
      'import.meta.resolve("@/components/svg-url-example/logo.svg")',
    );
    await expect(componentJs('svg-component-example')).resolves.toContain(
      '"svg"',
    );
    await expect(componentJs('third-party-package-example')).resolves.toContain(
      'from "date-fns"',
    );
    await expect(
      componentJs('third-party-shared-dependency-example'),
    ).resolves.toContain('from "@radix-ui/react-checkbox"');
    await expect(
      componentJs('third-party-shared-dependency-example'),
    ).resolves.toContain('from "@radix-ui/react-switch"');

    expect(buildResult.manifest.vendor).toEqual({
      '@radix-ui/react-checkbox': expect.stringMatching(
        /^\.\/vendor\/@radix-ui--react-checkbox-[\w-]+\.js$/,
      ),
      '@radix-ui/react-switch': expect.stringMatching(
        /^\.\/vendor\/@radix-ui--react-switch-[\w-]+\.js$/,
      ),
      'date-fns': expect.stringMatching(/^\.\/vendor\/date-fns-[\w-]+\.js$/),
    });
    expect(buildResult.manifest.vendor).not.toHaveProperty('clsx');
    expect(buildResult.manifest.shared).toEqual(
      expect.arrayContaining([
        expect.stringMatching(/^\.\/vendor\/[\w@.-]+-[\w-]+\.js$/),
      ]),
    );
    expect(buildResult.manifest.local).toEqual({
      '@/assets/intro.mp4': expect.stringMatching(
        /^\.\/local\/intro-[\w-]+\.mp4$/,
      ),
      '@/assets/poster.webp': expect.stringMatching(
        /^\.\/local\/poster-[\w-]+\.webp$/,
      ),
      '@/components/static-assets-example/hero.webp': expect.stringMatching(
        /^\.\/local\/hero-[\w-]+\.webp$/,
      ),
      '@/components/svg-url-example/logo.svg': expect.stringMatching(
        /^\.\/local\/logo-[\w-]+\.svg$/,
      ),
      '@/lib/formatTitle': expect.stringMatching(
        /^\.\/local\/formatTitle-[\w-]+\.js$/,
      ),
      '@/lib/AssetLabel': expect.stringMatching(
        /^\.\/local\/AssetLabel-[\w-]+\.js$/,
      ),
      '@/lib/DefaultLabel': expect.stringMatching(
        /^\.\/local\/DefaultLabel-[\w-]+\.js$/,
      ),
      '@/lib/ExampleLabel': expect.stringMatching(
        /^\.\/local\/ExampleLabel-[\w-]+\.js$/,
      ),
      '@/lib/exportedIcon': expect.stringMatching(
        /^\.\/local\/exportedIcon-[\w-]+\.js$/,
      ),
      '@/lib/FormattedLabel': expect.stringMatching(
        /^\.\/local\/FormattedLabel-[\w-]+\.js$/,
      ),
      '@/lib/HookLabel': expect.stringMatching(
        /^\.\/local\/HookLabel-[\w-]+\.js$/,
      ),
      '@/lib/ReactNodeLabel': expect.stringMatching(
        /^\.\/local\/ReactNodeLabel-[\w-]+\.js$/,
      ),
    });
    expect(
      Object.keys(buildResult.manifest.local).filter(
        (specifier) => specifier === '@/lib/ExampleLabel',
      ),
    ).toHaveLength(1);
    await expect(
      fs.readdir(path.join(projectRoot, 'dist/local')),
    ).resolves.toEqual(
      expect.arrayContaining([expect.stringMatching(/^poster-/)]),
    );
    await expectManifestFilesExist(projectRoot, buildResult.manifest.vendor);
    await expectManifestFilesExist(projectRoot, buildResult.manifest.local);
  });
});
