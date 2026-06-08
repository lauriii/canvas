import { promises as fs } from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { afterEach, describe, expect, it } from 'vitest';

import {
  buildPreviewPayload,
  buildPreviewRuntimeEntrySource,
  bundleInteractivePreview,
} from './preview-payload';

const temporaryDirectories: string[] = [];

async function makeTemporaryDirectory(): Promise<string> {
  const directory = await fs.mkdtemp(
    path.join(os.tmpdir(), 'canvas-workbench-preview-payload-'),
  );
  temporaryDirectories.push(directory);
  return directory;
}

async function writeFile(
  filePath: string,
  content: string | Buffer,
): Promise<void> {
  await fs.mkdir(path.dirname(filePath), { recursive: true });
  await fs.writeFile(filePath, content);
}

afterEach(async () => {
  await Promise.all(
    temporaryDirectories.map((directory) =>
      fs.rm(directory, { recursive: true, force: true }),
    ),
  );
  temporaryDirectories.length = 0;
});

describe('preview-payload', () => {
  it('reports invalid Canvas import root config before discovery', async () => {
    const root = await makeTemporaryDirectory();

    await writeFile(
      path.join(root, 'canvas.config.json'),
      JSON.stringify(
        {
          componentDir: 'components',
          pagesDir: 'pages',
          aliasBaseDir: 'src',
        },
        null,
        2,
      ),
    );

    const payload = await buildPreviewPayload({
      mode: 'component',
      inputPath: 'components/card/component.yml',
      projectRoot: root,
    });

    expect(payload.ok).toBe(false);
    expect(payload.errors).toEqual([
      expect.objectContaining({
        code: 'invalid_canvas_config',
        message:
          'Invalid Canvas config: componentDir "components" must be inside aliasBaseDir "src".',
      }),
    ]);
  });

  it('builds interactive component preview payload with inlined css html', async () => {
    const root = await makeTemporaryDirectory();

    await writeFile(
      path.join(root, 'canvas.config.json'),
      JSON.stringify(
        {
          componentDir: 'src/components',
          pagesDir: 'pages',
          aliasBaseDir: 'src',
          globalCssPath: 'src/global.css',
        },
        null,
        2,
      ),
    );

    await writeFile(
      path.join(root, 'src/components/card/component.yml'),
      [
        'name: Card',
        'props:',
        '  properties:',
        '    title:',
        '      type: string',
        '      examples:',
        '        - Hello world',
      ].join('\n'),
    );
    await writeFile(
      path.join(root, 'src/components/card/index.tsx'),
      'export default function Card() { return null; }',
    );
    await writeFile(
      path.join(root, 'src/components/card/index.css'),
      '.card { color: red; }',
    );
    await writeFile(path.join(root, 'src/global.css'), 'body { margin: 0; }');
    await writeFile(
      path.join(root, '.env'),
      [
        'CANVAS_SITE_URL=https://canvas.example.test',
        'CANVAS_JSONAPI_PREFIX=api',
      ].join('\n'),
    );

    let capturedRoot: string | null = null;
    let capturedCssEntryPaths: string[] = [];

    const payload = await buildPreviewPayload(
      {
        mode: 'component',
        inputPath: 'src/components/card/component.yml',
        projectRoot: root,
      },
      {
        bundleInteractivePreview: async (options) => {
          capturedRoot = options.spec.root;
          capturedCssEntryPaths = options.cssEntryPaths;
          return {
            js: 'console.log("interactive");',
            css: 'body{background:black;color:white;}',
          };
        },
      },
    );

    expect(payload.ok).toBe(true);
    expect(payload.renderMode).toBe('interactive');
    expect(payload.target?.projectRelativePath).toBe(
      'src/components/card/component.yml',
    );
    expect(payload.spec?.root).toBe('canvas-workbench-preview-root');
    expect(payload.css).toBe('body{background:black;color:white;}');
    expect(payload.iframeHtml).toContain(
      '<style data-canvas-preview-css>body{background:black;color:white;}</style>',
    );
    expect(payload.iframeHtml).toContain(
      '<script type="text/javascript" data-canvas-preview-runtime>',
    );
    expect(payload.iframeHtml).toContain(
      '<script type="text/javascript" data-canvas-preview-bootstrap>',
    );
    expect(payload.iframeHtml).toContain(
      'const canvasPreviewBaseUrl = "https://canvas.example.test";',
    );
    expect(payload.iframeHtml).toContain(
      'window.drupalSettings.canvasData.v0.baseUrl = canvasPreviewBaseUrl;',
    );
    expect(payload.iframeHtml).toContain(
      'window.drupalSettings.canvasData.v0.jsonapiSettings.apiPrefix = "api";',
    );
    expect(capturedRoot).toBe('canvas-workbench-preview-root');
    expect(capturedCssEntryPaths).toEqual(
      expect.arrayContaining([
        path.resolve(root, 'src/global.css'),
        path.resolve(root, 'src/components/card/index.css'),
      ]),
    );
  });

  it('warns and uses legacy global css fallback when the new default is missing', async () => {
    const root = await makeTemporaryDirectory();

    await writeFile(
      path.join(root, 'src/components/card/component.yml'),
      'name: Card\n',
    );
    await writeFile(
      path.join(root, 'src/components/card/index.tsx'),
      'export default function Card() { return null; }',
    );
    await writeFile(
      path.join(root, 'src/components/global.css'),
      'body { margin: 0; }',
    );

    let capturedCssEntryPaths: string[] = [];

    const payload = await buildPreviewPayload(
      {
        mode: 'component',
        inputPath: 'src/components/card/component.yml',
        projectRoot: root,
      },
      {
        bundleInteractivePreview: async (options) => {
          capturedCssEntryPaths = options.cssEntryPaths;
          return {
            js: 'console.log("interactive");',
            css: 'body{margin:0;}',
          };
        },
      },
    );

    expect(payload.ok).toBe(true);
    expect(payload.warnings).toEqual(
      expect.arrayContaining([
        expect.objectContaining({
          code: 'legacy_default_global_css_path',
          path: './src/components/global.css',
        }),
      ]),
    );
    expect(capturedCssEntryPaths).toContain(
      path.resolve(root, 'src/components/global.css'),
    );
  });

  it('builds interactive page preview payload', async () => {
    const root = await makeTemporaryDirectory();

    await writeFile(
      path.join(root, 'canvas.config.json'),
      JSON.stringify(
        {
          componentDir: 'src/components',
          pagesDir: 'pages',
          aliasBaseDir: 'src',
          globalCssPath: 'src/global.css',
        },
        null,
        2,
      ),
    );

    await writeFile(
      path.join(root, 'src/components/hero/component.yml'),
      'name: Hero\n',
    );
    await writeFile(
      path.join(root, 'src/components/hero/index.tsx'),
      'export default function Hero() { return null; }',
    );

    await writeFile(
      path.join(root, 'src/components/card/component.yml'),
      'name: Card\n',
    );
    await writeFile(
      path.join(root, 'src/components/card/index.tsx'),
      'export default function Card() { return null; }',
    );
    await writeFile(
      path.join(root, 'src/components/card/index.css'),
      '.card { padding: 1rem; }',
    );

    await writeFile(
      path.join(root, 'pages/home.json'),
      JSON.stringify(
        {
          title: 'Home',
          elements: {
            hero: {
              type: 'js.hero',
              props: {
                title: 'Hello',
              },
            },
            card: {
              type: 'js.card',
              props: {
                featured: true,
              },
            },
          },
        },
        null,
        2,
      ),
    );

    await writeFile(path.join(root, 'src/global.css'), 'body { margin: 0; }');

    let capturedRegistryNames: string[] = [];

    const payload = await buildPreviewPayload(
      {
        mode: 'page',
        inputPath: 'pages/home.json',
        projectRoot: root,
      },
      {
        bundleInteractivePreview: async (options) => {
          capturedRegistryNames = options.componentSources
            .map((source) => source.name)
            .sort();
          return {
            js: 'console.log("interactive-page");',
            css: '.page{display:block;}',
          };
        },
      },
    );

    expect(payload.ok).toBe(true);
    expect(payload.renderMode).toBe('interactive');
    expect(payload.target?.id).toBe('home');
    expect(payload.spec?.root).toBe('canvas:component-tree');
    expect(payload.css).toBe('.page{display:block;}');
    expect(payload.iframeHtml).toContain('.page{display:block;}');
    expect(capturedRegistryNames).toEqual(['card', 'hero']);
  });

  it('keeps interactive render mode and fails when interactive bundle throws', async () => {
    const root = await makeTemporaryDirectory();

    await writeFile(
      path.join(root, 'src/components/card/component.yml'),
      'name: Card\n',
    );
    await writeFile(
      path.join(root, 'src/components/card/index.tsx'),
      'export default function Card() { return null; }',
    );

    const payload = await buildPreviewPayload(
      {
        mode: 'component',
        inputPath: 'src/components/card/component.yml',
        projectRoot: root,
      },
      {
        bundleInteractivePreview: async () => {
          throw new Error('boom');
        },
      },
    );

    expect(payload.ok).toBe(false);
    expect(payload.renderMode).toBe('interactive');
    expect(payload.iframeHtml).toBeNull();
    expect(payload.errors[0]?.code).toBe('interactive_bundle_failed');
  });

  it('explains the configured componentDir when the target path is outside it', async () => {
    const root = await makeTemporaryDirectory();

    await writeFile(
      path.join(root, 'canvas.config.json'),
      JSON.stringify(
        {
          componentDir: 'src/components',
          pagesDir: 'pages',
        },
        null,
        2,
      ),
    );
    await writeFile(
      path.join(root, 'examples/components/card/component.yml'),
      'name: Card\n',
    );

    const payload = await buildPreviewPayload({
      mode: 'component',
      inputPath: 'examples/components/card/component.yml',
      projectRoot: root,
    });

    expect(payload.ok).toBe(false);
    expect(payload.errors).toEqual([
      expect.objectContaining({
        code: 'component_not_found',
        message: expect.stringContaining(
          'configured componentDir ("src/components")',
        ),
      }),
    ]);
    expect(payload.errors[0]?.message).toContain(
      'preview discovery, mocks, and @/ module resolution are scoped to the configured roots.',
    );
  });

  it('explains the configured pagesDir when the target path is outside it', async () => {
    const root = await makeTemporaryDirectory();

    await writeFile(
      path.join(root, 'canvas.config.json'),
      JSON.stringify(
        {
          componentDir: 'src/components',
          pagesDir: 'pages',
        },
        null,
        2,
      ),
    );
    await writeFile(
      path.join(root, 'examples/pages/home.json'),
      JSON.stringify(
        {
          title: 'Home',
          elements: {},
        },
        null,
        2,
      ),
    );

    const payload = await buildPreviewPayload({
      mode: 'page',
      inputPath: 'examples/pages/home.json',
      projectRoot: root,
    });

    expect(payload.ok).toBe(false);
    expect(payload.errors).toEqual([
      expect.objectContaining({
        code: 'page_not_found',
        message: expect.stringContaining('configured pagesDir ("pages")'),
      }),
    ]);
    expect(payload.errors[0]?.message).toContain(
      'components discovered under componentDir ("src/components")',
    );
  });

  it('builds runtime source that bootstraps React and drupalSettings defaults', () => {
    const source = buildPreviewRuntimeEntrySource({
      spec: {
        root: 'canvas-workbench-preview-root',
        elements: {},
      },
      componentSources: [
        {
          name: 'card',
          jsEntryPath: '/tmp/card.tsx',
        },
        {
          name: 'js.hero',
          jsEntryPath: '/tmp/hero.tsx',
        },
      ],
      cssEntryPaths: ['/tmp/global.css'],
    });

    expect(source).toContain('globalThis.React = React;');
    expect(source).toContain(
      'window.drupalSettings = window.drupalSettings ?? {};',
    );
    expect(source).toContain(
      'window.drupalSettings.canvasData.v0.baseUrl = window.location.origin;',
    );
    expect(source).toContain(
      '"card": typeof Component0 === \'function\' ? Component0 : () => null',
    );
    expect(source).toContain(
      '"js.card": typeof Component0 === \'function\' ? Component0 : () => null',
    );
    expect(source).toContain(
      '"js.hero": typeof Component1 === \'function\' ? Component1 : () => null',
    );
    expect(source).toContain(
      '"hero": typeof Component1 === \'function\' ? Component1 : () => null',
    );
  });

  it('inlines local font and image assets in the interactive bundle outputs', async () => {
    const root = await makeTemporaryDirectory();

    await writeFile(
      path.join(root, 'src/global.css'),
      [
        '@font-face {',
        "  font-family: 'DemoFont';",
        "  src: url('./fonts/demo.woff2') format('woff2');",
        '}',
        ":root { --demo-font: 'DemoFont'; }",
      ].join('\n'),
    );
    await writeFile(
      path.join(root, 'src/fonts/demo.woff2'),
      'not-a-real-font-but-valid-as-an-asset\n',
    );
    await writeFile(
      path.join(root, 'src/components/asset-card/logo.png'),
      Buffer.from(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO7ZsS8AAAAASUVORK5CYII=',
        'base64',
      ),
    );
    await writeFile(
      path.join(root, 'src/components/asset-card/index.tsx'),
      [
        "import logoUrl from './logo.png';",
        "import { label } from '@/lib/labels';",
        '',
        'export default function AssetCard() {',
        '  return (',
        "    <div style={{ fontFamily: 'DemoFont' }}>",
        '      <img alt="logo" src={logoUrl} />',
        '      <p>{label}</p>',
        '    </div>',
        '  );',
        '}',
      ].join('\n'),
    );
    await writeFile(
      path.join(root, 'src/lib/labels.ts'),
      "export const label = 'Asset card';",
    );

    const bundled = await bundleInteractivePreview({
      projectRoot: root,
      aliasBaseDir: 'src',
      spec: {
        root: 'canvas-workbench-preview-root',
        elements: {
          'canvas-workbench-preview-root': {
            type: 'asset-card',
            props: {},
          },
        },
      },
      componentSources: [
        {
          name: 'asset-card',
          jsEntryPath: path.join(root, 'src/components/asset-card/index.tsx'),
        },
      ],
      cssEntryPaths: [path.join(root, 'src/global.css')],
    });

    expect(bundled.css).toContain('data:font/woff2;base64');
    expect(bundled.js).toContain('data:image/png;base64');
    expect(bundled.js).toContain('Asset card');
  });

  it('bundles jsx components without requiring an explicit React import', async () => {
    const root = await makeTemporaryDirectory();

    await writeFile(
      path.join(root, 'src/components/hero/index.jsx'),
      [
        'export default function Hero({ headline }) {',
        '  return <section><h1>{headline}</h1></section>;',
        '}',
      ].join('\n'),
    );

    const bundled = await bundleInteractivePreview({
      projectRoot: root,
      aliasBaseDir: 'src',
      spec: {
        root: 'canvas-workbench-preview-root',
        elements: {
          'canvas-workbench-preview-root': {
            type: 'hero',
            props: {
              headline: 'Hello',
            },
          },
        },
      },
      componentSources: [
        {
          name: 'hero',
          jsEntryPath: path.join(root, 'src/components/hero/index.jsx'),
        },
      ],
      cssEntryPaths: [],
    });

    expect(bundled.js).toContain('jsxDEV');
    expect(bundled.js).not.toContain('React.createElement("section"');
  });
});
