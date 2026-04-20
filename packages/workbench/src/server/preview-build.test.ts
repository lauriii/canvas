import { promises as fs } from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { afterEach, describe, expect, it } from 'vitest';

import { buildPreviewArtifact, parsePreviewBuildArgs } from './preview-build';

import type { CanvasConfig, DiscoveryResult } from '@drupal-canvas/discovery';
import type { PreviewPayload } from './preview-payload';

const temporaryDirectories: string[] = [];

async function makeTemporaryDirectory(): Promise<string> {
  const directory = await fs.mkdtemp(
    path.join(os.tmpdir(), 'canvas-workbench-preview-build-'),
  );
  temporaryDirectories.push(directory);
  return directory;
}

async function writeFile(filePath: string, content: string): Promise<void> {
  await fs.mkdir(path.dirname(filePath), { recursive: true });
  await fs.writeFile(filePath, content, 'utf-8');
}

function makePreviewPayload(
  projectRoot: string,
  overrides: Partial<PreviewPayload> = {},
): PreviewPayload {
  return {
    ok: true,
    request: {
      mode: 'component',
      inputPath: 'components/card/component.yml',
      projectRoot,
    },
    target: {
      id: 'card-id',
      name: 'Card',
      projectRelativePath: 'components/card/component.yml',
    },
    spec: {
      root: 'canvas-workbench-preview-root',
      elements: {},
    },
    css: '.card{color:red;}',
    iframeHtml: [
      '<!doctype html><html><head>',
      '<style data-canvas-preview-css>.card{color:red;}</style>',
      '</head><body>',
      '<script data-canvas-preview-bootstrap>window.bootstrap=true;</script>',
      '<script data-canvas-preview-runtime>window.default=true;</script>',
      '</body></html>',
    ].join(''),
    renderMode: 'interactive',
    warnings: [],
    errors: [],
    ...overrides,
  };
}

function makeDiscoveryResult(projectRoot: string): DiscoveryResult {
  return {
    componentRoot: path.join(projectRoot, 'components'),
    projectRoot,
    components: [
      {
        id: 'card-id',
        kind: 'index',
        name: 'card',
        directory: path.join(projectRoot, 'components/card'),
        relativeDirectory: 'card',
        projectRelativeDirectory: 'components/card',
        metadataPath: path.join(projectRoot, 'components/card/component.yml'),
        jsEntryPath: path.join(projectRoot, 'components/card/index.tsx'),
        cssEntryPath: path.join(projectRoot, 'components/card/index.css'),
      },
      {
        id: 'hero-id',
        kind: 'index',
        name: 'hero',
        directory: path.join(projectRoot, 'components/hero'),
        relativeDirectory: 'hero',
        projectRelativeDirectory: 'components/hero',
        metadataPath: path.join(projectRoot, 'components/hero/component.yml'),
        jsEntryPath: path.join(projectRoot, 'components/hero/index.tsx'),
        cssEntryPath: path.join(projectRoot, 'components/hero/index.css'),
      },
    ],
    pages: [
      {
        name: 'Home',
        slug: 'home',
        uuid: null,
        path: path.join(projectRoot, 'pages/home.json'),
        relativePath: 'pages/home.json',
      },
    ],
    warnings: [],
    stats: {
      scannedFiles: 0,
      ignoredFiles: 0,
    },
  };
}

function makeCanvasConfig(): CanvasConfig {
  return {
    aliasBaseDir: 'src',
    outputDir: './dist',
    componentDir: './components',
    pagesDir: './pages',
    deprecatedComponentDir: './components',
    globalCssPath: './src/global.css',
  };
}

afterEach(async () => {
  await Promise.all(
    temporaryDirectories.map((directory) =>
      fs.rm(directory, { recursive: true, force: true }),
    ),
  );
  temporaryDirectories.length = 0;
});

describe('preview-build', () => {
  it('parses args and requires --out-dir', () => {
    const parsed = parsePreviewBuildArgs([
      '--component-path',
      'components/card/component.yml',
      '--out-dir',
      '.canvas-preview',
      '--pretty',
    ]);

    expect(parsed).toEqual({
      ok: true,
      value: {
        mode: 'component',
        inputPath: 'components/card/component.yml',
        outDir: '.canvas-preview',
        pretty: true,
      },
    });

    const missingOutDir = parsePreviewBuildArgs([
      '--component-path',
      'components/card/component.yml',
    ]);
    expect(missingOutDir.ok).toBe(false);

    const unknownArg = parsePreviewBuildArgs([
      '--component-path',
      'components/card/component.yml',
      '--out-dir',
      '.canvas-preview',
      '--unknown',
    ]);
    expect(unknownArg.ok).toBe(false);
  });

  it('exports component default and mock html files with manifest', async () => {
    const projectRoot = await makeTemporaryDirectory();
    const outputDir = '.canvas-preview/component';

    await writeFile(
      path.join(projectRoot, 'components/card/mocks.json'),
      JSON.stringify(
        {
          mocks: [
            {
              name: 'Mock One',
              props: {
                title: 'One',
              },
            },
            {
              name: 'Mock Two',
              props: {
                title: 'Two',
              },
            },
          ],
        },
        null,
        2,
      ),
    );
    await writeFile(
      path.join(projectRoot, '.env'),
      [
        'CANVAS_SITE_URL=https://canvas.example.test',
        'CANVAS_JSONAPI_PREFIX=api',
      ].join('\n'),
    );
    await writeFile(path.join(projectRoot, 'src/global.css'), 'body { }');

    const payload = await buildPreviewArtifact(
      {
        mode: 'component',
        inputPath: 'components/card/component.yml',
        projectRoot,
        outDir: outputDir,
      },
      {
        buildPreviewPayload: async (options) =>
          makePreviewPayload(options.projectRoot),
        discover: async () => makeDiscoveryResult(projectRoot),
        resolveConfig: () => makeCanvasConfig(),
        extractMetadata: async () => ({
          label: 'Card',
          exampleProps: {
            title: 'Example',
          },
          requiredPropNames: [],
        }),
        bundleInteractivePreview: async () => ({
          js: 'console.log("mock-runtime");',
          css: '.mock { color: red; }',
        }),
      },
    );

    expect(payload.ok).toBe(true);
    expect(payload.summary).toEqual({
      generatedHtmlCount: 3,
      mockCount: 2,
    });
    expect(payload.manifestPath).toBe(
      path.join(path.resolve(projectRoot, outputDir), 'manifest.json'),
    );

    const outputDirectory = path.resolve(projectRoot, outputDir);
    const files = await fs.readdir(outputDirectory);
    expect(files.sort()).toEqual(
      [
        'component-default.html',
        'component-mock-01.html',
        'component-mock-02.html',
        'manifest.json',
      ].sort(),
    );

    const [defaultHtml, firstMockHtml, manifestText] = await Promise.all([
      fs.readFile(
        path.join(outputDirectory, 'component-default.html'),
        'utf-8',
      ),
      fs.readFile(
        path.join(outputDirectory, 'component-mock-01.html'),
        'utf-8',
      ),
      fs.readFile(path.join(outputDirectory, 'manifest.json'), 'utf-8'),
    ]);
    const manifest = JSON.parse(manifestText) as {
      targetType: string;
      target: {
        hasMocks: boolean;
        mockCount: number;
      };
      entries: {
        default: {
          path: string;
          label: string;
        };
        mocks: Array<{
          path: string;
          label: string;
        }>;
      };
    };

    expect(defaultHtml).toContain('window.default=true;');
    expect(firstMockHtml).toContain('data-canvas-preview-runtime');
    expect(firstMockHtml).toContain(
      'const canvasPreviewBaseUrl = "https://canvas.example.test";',
    );
    expect(firstMockHtml).toContain(
      'window.drupalSettings.canvasData.v0.jsonapiSettings.apiPrefix = "api";',
    );
    expect(manifest.targetType).toBe('component');
    expect(manifest.target.hasMocks).toBe(true);
    expect(manifest.target.mockCount).toBe(2);
    expect(manifest.entries).toEqual({
      default: {
        path: 'component-default.html',
        label: 'Default',
      },
      mocks: [
        {
          path: 'component-mock-01.html',
          label: 'Mock One',
        },
        {
          path: 'component-mock-02.html',
          label: 'Mock Two',
        },
      ],
    });

    const relocatedDirectory = path.join(projectRoot, '.canvas-preview/moved');
    await fs.rename(outputDirectory, relocatedDirectory);

    expect(
      await fs.readFile(
        path.resolve(relocatedDirectory, manifest.entries.default.path),
        'utf-8',
      ),
    ).toContain('window.default=true;');
    expect(
      await fs.readFile(
        path.resolve(relocatedDirectory, manifest.entries.mocks[0].path),
        'utf-8',
      ),
    ).toContain('data-canvas-preview-runtime');
  });

  it('exports page html and simplified manifest without mocks', async () => {
    const projectRoot = await makeTemporaryDirectory();
    const outputDir = '.canvas-preview/page';

    const payload = await buildPreviewArtifact(
      {
        mode: 'page',
        inputPath: 'pages/home.json',
        projectRoot,
        outDir: outputDir,
      },
      {
        buildPreviewPayload: async (options) =>
          makePreviewPayload(options.projectRoot, {
            request: {
              mode: 'page',
              inputPath: 'pages/home.json',
              projectRoot: options.projectRoot,
            },
            target: {
              id: 'home',
              name: 'Home',
              projectRelativePath: 'pages/home.json',
            },
          }),
      },
    );

    expect(payload.ok).toBe(true);
    expect(payload.summary).toEqual({
      generatedHtmlCount: 1,
      mockCount: 0,
    });

    const outputDirectory = path.resolve(projectRoot, outputDir);
    const files = await fs.readdir(outputDirectory);
    expect(files.sort()).toEqual(['manifest.json', 'page-default.html']);

    const manifestText = await fs.readFile(
      path.join(outputDirectory, 'manifest.json'),
      'utf-8',
    );
    const manifest = JSON.parse(manifestText) as {
      targetType: string;
      target: {
        hasMocks: boolean;
        mockCount: number;
      };
      entries: {
        default: { path: string; label: string };
      };
    };

    expect(manifest.targetType).toBe('page');
    expect(manifest.target.hasMocks).toBe(false);
    expect(manifest.target.mockCount).toBe(0);
    expect(manifest.entries).toEqual({
      default: {
        path: 'page-default.html',
        label: 'Default',
      },
    });
  });

  it('fails fast and keeps existing output untouched when mock bundle fails', async () => {
    const projectRoot = await makeTemporaryDirectory();
    const outputDir = '.canvas-preview/component';
    const outputDirectory = path.resolve(projectRoot, outputDir);

    await writeFile(path.join(outputDirectory, 'keep.txt'), 'keep');
    await writeFile(
      path.join(projectRoot, 'components/card/mocks.json'),
      JSON.stringify(
        {
          mocks: [{ name: 'Mock One', props: { title: 'One' } }],
        },
        null,
        2,
      ),
    );
    await writeFile(path.join(projectRoot, 'src/global.css'), 'body { }');

    const payload = await buildPreviewArtifact(
      {
        mode: 'component',
        inputPath: 'components/card/component.yml',
        projectRoot,
        outDir: outputDir,
      },
      {
        buildPreviewPayload: async (options) =>
          makePreviewPayload(options.projectRoot),
        discover: async () => makeDiscoveryResult(projectRoot),
        resolveConfig: () => makeCanvasConfig(),
        extractMetadata: async () => ({
          label: 'Card',
          exampleProps: {},
          requiredPropNames: [],
        }),
        bundleInteractivePreview: async () => {
          throw new Error('mock bundle failed');
        },
      },
    );

    expect(payload.ok).toBe(false);
    expect(payload.errors.map((error) => error.code)).toContain(
      'artifact_build_failed',
    );

    const filesAfterFailure = await fs.readdir(outputDirectory);
    expect(filesAfterFailure).toEqual(['keep.txt']);
    expect(
      await fs.readFile(path.join(outputDirectory, 'keep.txt'), 'utf-8'),
    ).toBe('keep');
  });

  it('surfaces the configured componentDir when mock export cannot resolve the target', async () => {
    const projectRoot = await makeTemporaryDirectory();

    const payload = await buildPreviewArtifact(
      {
        mode: 'component',
        inputPath: 'examples/components/card/component.yml',
        projectRoot,
        outDir: '.canvas-preview/component',
      },
      {
        buildPreviewPayload: async (options) =>
          makePreviewPayload(options.projectRoot, {
            request: {
              mode: 'component',
              inputPath: 'examples/components/card/component.yml',
              projectRoot: options.projectRoot,
            },
            target: {
              id: 'card-id',
              name: 'Card',
              projectRelativePath: 'examples/components/card/component.yml',
            },
          }),
        discover: async () => makeDiscoveryResult(projectRoot),
        resolveConfig: () => makeCanvasConfig(),
      },
    );

    expect(payload.ok).toBe(false);
    expect(payload.errors).toEqual([
      expect.objectContaining({
        code: 'artifact_build_failed',
        message: expect.stringContaining(
          'configured componentDir ("./components")',
        ),
      }),
    ]);
  });
});
