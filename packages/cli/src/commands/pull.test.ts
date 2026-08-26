import fs from 'fs/promises';
import os from 'os';
import path from 'path';
import yaml from 'js-yaml';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { setConfig } from '../config';
import {
  createAssetsPullTask,
  createComponentsPullTask,
  createFontsPullTask,
  createPagesPullTask,
} from './pull';

import type { ApiService } from '../services/api';
import type { Component } from '../types/Component';
import type { Page, PageListItem } from '../types/Page';

const mockComponent = (machineName: string): Component =>
  ({
    name: machineName,
    machineName,
    status: true,
    props: {},
    slots: {},
    sourceCodeJs: `export default function ${machineName}() {}`,
    sourceCodeCss: `.${machineName} { color: red; }`,
  }) as Component;

describe('Pull Command', () => {
  describe('createComponentsPullTask', () => {
    let tmpDir: string;

    beforeEach(async () => {
      tmpDir = await fs.mkdtemp(path.join(os.tmpdir(), 'pull-test-'));
    });

    afterEach(async () => {
      await fs.rm(tmpDir, { recursive: true, force: true });
    });

    function mockApiService(components: Record<string, Component>): ApiService {
      return {
        listComponents: vi.fn().mockResolvedValue(components),
      } as unknown as ApiService;
    }

    it('should return empty summary when no components', async () => {
      const api = mockApiService({});
      const task = createComponentsPullTask(api, tmpDir, false);

      const { summaryLines } = await task.prepare();
      expect(summaryLines).toEqual([]);
    });

    it('should show only new counts in summary when none exist locally', async () => {
      const api = mockApiService({ a: mockComponent('button') });
      const task = createComponentsPullTask(api, tmpDir, false);

      const { summaryLines } = await task.prepare();
      expect(summaryLines).toEqual(['Components: 1 pull (1 new)']);
    });

    it('should show both new and existing counts in summary', async () => {
      // Create an existing component on disk so discovery finds it.
      const buttonDir = path.join(tmpDir, 'button');
      await fs.mkdir(buttonDir, { recursive: true });
      await fs.writeFile(
        path.join(buttonDir, 'component.yml'),
        yaml.dump({ name: 'button', machineName: 'button', status: true }),
        'utf-8',
      );
      await fs.writeFile(
        path.join(buttonDir, 'index.jsx'),
        'export default function button() {}',
        'utf-8',
      );

      const api = mockApiService({
        a: mockComponent('button'),
        b: mockComponent('card'),
        c: mockComponent('hero'),
      });
      const task = createComponentsPullTask(api, tmpDir, false);

      const { summaryLines } = await task.prepare();
      expect(summaryLines).toEqual(['Components: 3 pull (2 new, 1 existing)']);
    });

    it('should include local-only components in summary when remote is empty', async () => {
      const orphanDir = path.join(tmpDir, 'stale');
      await fs.mkdir(orphanDir, { recursive: true });
      await fs.writeFile(
        path.join(orphanDir, 'component.yml'),
        yaml.dump({ name: 'stale', machineName: 'stale', status: true }),
        'utf-8',
      );
      await fs.writeFile(
        path.join(orphanDir, 'index.jsx'),
        'export default function stale() {}',
        'utf-8',
      );

      const api = mockApiService({});
      const task = createComponentsPullTask(api, tmpDir, false);

      const { summaryLines, localOnlyCount } = await task.prepare();
      expect(summaryLines).toEqual(['Components: 1 delete (local-only)']);
      expect(localOnlyCount).toBe(1);
    });

    it('should append local-only deletion line when remote has components too', async () => {
      const orphanDir = path.join(tmpDir, 'stale');
      await fs.mkdir(orphanDir, { recursive: true });
      await fs.writeFile(
        path.join(orphanDir, 'component.yml'),
        yaml.dump({ name: 'stale', machineName: 'stale', status: true }),
        'utf-8',
      );
      await fs.writeFile(
        path.join(orphanDir, 'index.jsx'),
        'export default function stale() {}',
        'utf-8',
      );

      const api = mockApiService({ a: mockComponent('button') });
      const task = createComponentsPullTask(api, tmpDir, false);

      const { summaryLines } = await task.prepare();
      expect(summaryLines).toEqual([
        'Components: 1 pull (1 new)',
        'Components: 1 delete (local-only)',
      ]);
    });

    it('should write new component files on execute', async () => {
      const api = mockApiService({ a: mockComponent('my-button') });
      const task = createComponentsPullTask(api, tmpDir, false);

      await task.prepare();
      const results = await task.execute();

      expect(results.title).toBe('Pulled components');
      expect(results.label).toBe('Component');
      expect(results.results).toHaveLength(1);
      expect(results.results[0].success).toBe(true);

      const componentDir = path.join(tmpDir, 'my-button');
      const files = await fs.readdir(componentDir);
      expect(files).toContain('component.yml');
      expect(files).toContain('index.tsx');
      expect(files).toContain('index.css');

      const ymlContent = await fs.readFile(
        path.join(componentDir, 'component.yml'),
        'utf-8',
      );
      const parsed = yaml.load(ymlContent) as Record<string, unknown>;
      expect(parsed).toHaveProperty('name', 'my-button');
      expect(parsed).toHaveProperty('machineName', 'my-button');
    });

    it('should use JSX for a new component when all local components use JavaScript', async () => {
      const existingDir = path.join(tmpDir, 'existing');
      await fs.mkdir(existingDir, { recursive: true });
      await fs.writeFile(
        path.join(existingDir, 'component.yml'),
        yaml.dump({ name: 'Existing', machineName: 'existing', status: true }),
        'utf-8',
      );
      await fs.writeFile(
        path.join(existingDir, 'index.jsx'),
        'export default function Existing() {}',
        'utf-8',
      );

      const api = mockApiService({
        a: {
          ...mockComponent('my-button'),
          sourceCodeJs: 'export default function MyButton() {}',
        },
      });
      const task = createComponentsPullTask(api, tmpDir, false);

      await task.prepare();
      await task.execute();

      await expect(
        fs.access(path.join(tmpDir, 'my-button', 'index.jsx')),
      ).resolves.toBeUndefined();
      await expect(
        fs.access(path.join(tmpDir, 'my-button', 'index.tsx')),
      ).rejects.toMatchObject({ code: 'ENOENT' });
    });

    it('should use TSX for a new component when a local component uses TypeScript', async () => {
      const existingDir = path.join(tmpDir, 'existing');
      await fs.mkdir(existingDir, { recursive: true });
      await fs.writeFile(
        path.join(existingDir, 'component.yml'),
        yaml.dump({ name: 'Existing', machineName: 'existing', status: true }),
        'utf-8',
      );
      await fs.writeFile(
        path.join(existingDir, 'index.tsx'),
        'export default function Existing() {}',
        'utf-8',
      );

      const api = mockApiService({ a: mockComponent('my-button') });
      const task = createComponentsPullTask(api, tmpDir, false);

      await task.prepare();
      await task.execute();

      await expect(
        fs.access(path.join(tmpDir, 'my-button', 'index.tsx')),
      ).resolves.toBeUndefined();
      await expect(
        fs.access(path.join(tmpDir, 'my-button', 'index.jsx')),
      ).rejects.toMatchObject({ code: 'ENOENT' });
    });

    it('should use TSX for a new component whose source requires TypeScript', async () => {
      const sourceCodeJs = [
        "import type { ComponentProps } from 'react';",
        'interface ButtonProps extends ComponentProps<"button"> {}',
        'export default function Button(props: ButtonProps) {',
        '  return <button {...props} />;',
        '}',
      ].join('\n');
      const component: Component = {
        ...mockComponent('my-button'),
        sourceCodeJs,
      };
      const api = mockApiService({ a: component });
      const task = createComponentsPullTask(api, tmpDir, false);

      await task.prepare();
      const results = await task.execute();

      expect(results.results).toHaveLength(1);
      expect(results.results[0].success).toBe(true);
      expect(
        await fs.readFile(path.join(tmpDir, 'my-button', 'index.tsx'), 'utf-8'),
      ).toBe(sourceCodeJs);
      await expect(
        fs.access(path.join(tmpDir, 'my-button', 'index.jsx')),
      ).rejects.toMatchObject({ code: 'ENOENT' });
    });

    it('should write entity field data dependencies to component metadata', async () => {
      const component: Component = {
        ...mockComponent('article-card'),
        dataDependencies: {
          entityFields: {
            article: ['entity:node:article.title.value'],
          },
        },
      };
      const api = mockApiService({ a: component });
      const task = createComponentsPullTask(api, tmpDir, false);

      await task.prepare();
      await task.execute();

      const ymlContent = await fs.readFile(
        path.join(tmpDir, 'article-card', 'component.yml'),
        'utf-8',
      );
      const parsed = yaml.load(ymlContent) as Record<string, unknown>;
      expect(parsed).toHaveProperty('dataDependencies', {
        entityFields: {
          article: ['entity:node:article.title.value'],
        },
      });
    });

    it('should update existing component files in-place', async () => {
      // Set up an existing component on disk.
      const componentDir = path.join(tmpDir, 'my-button');
      await fs.mkdir(componentDir, { recursive: true });

      const metadataPath = path.join(componentDir, 'component.yml');
      const jsEntryPath = path.join(componentDir, 'index.jsx');
      const cssEntryPath = path.join(componentDir, 'index.css');
      const extraFile = path.join(componentDir, 'helpers.ts');

      await fs.writeFile(
        metadataPath,
        yaml.dump({ name: 'Old', machineName: 'my-button', status: true }),
        'utf-8',
      );
      await fs.writeFile(jsEntryPath, 'old js', 'utf-8');
      await fs.writeFile(cssEntryPath, 'old css', 'utf-8');
      await fs.writeFile(extraFile, 'helper code', 'utf-8');

      const component: Component = {
        ...mockComponent('my-button'),
        name: 'My Button',
        sourceCodeJs: 'new js',
        sourceCodeCss: '.btn { color: blue; }',
      };

      const api = mockApiService({ a: component });
      const task = createComponentsPullTask(api, tmpDir, false);

      await task.prepare();
      const results = await task.execute();

      expect(results.results).toHaveLength(1);
      expect(results.results[0].success).toBe(true);

      // Extra file should be preserved.
      const files = await fs.readdir(componentDir);
      expect(files).toContain('helpers.ts');

      // Metadata should be updated.
      const ymlContent = await fs.readFile(metadataPath, 'utf-8');
      const parsed = yaml.load(ymlContent) as Record<string, unknown>;
      expect(parsed).toHaveProperty('name', 'My Button');

      // JS and CSS should be updated.
      expect(await fs.readFile(jsEntryPath, 'utf-8')).toBe('new js');
      expect(await fs.readFile(cssEntryPath, 'utf-8')).toBe(
        '.btn { color: blue; }',
      );
    });

    it('should migrate an existing JSX entry when pulled source requires TypeScript', async () => {
      const componentDir = path.join(tmpDir, 'my-button');
      await fs.mkdir(componentDir, { recursive: true });

      const metadataPath = path.join(componentDir, 'component.yml');
      const jsxEntryPath = path.join(componentDir, 'index.jsx');
      const tsxEntryPath = path.join(componentDir, 'index.tsx');

      await fs.writeFile(
        metadataPath,
        yaml.dump({ name: 'Old', machineName: 'my-button', status: true }),
        'utf-8',
      );
      await fs.writeFile(jsxEntryPath, 'export default () => <button />;');

      const sourceCodeJs = [
        "import type { ComponentProps } from 'react';",
        'interface ButtonProps extends ComponentProps<"button"> {}',
        'export default function Button(props: ButtonProps) {',
        '  return <button {...props} />;',
        '}',
      ].join('\n');
      const component: Component = {
        ...mockComponent('my-button'),
        sourceCodeJs,
      };

      const api = mockApiService({ a: component });
      const task = createComponentsPullTask(api, tmpDir, false);

      await task.prepare();
      const results = await task.execute();

      expect(results.results).toHaveLength(1);
      expect(results.results[0].success).toBe(true);
      expect(await fs.readFile(tsxEntryPath, 'utf-8')).toBe(sourceCodeJs);
      await expect(fs.access(jsxEntryPath)).rejects.toMatchObject({
        code: 'ENOENT',
      });
    });

    it('should create a TSX entry for an existing component with no local entry', async () => {
      const componentDir = path.join(tmpDir, 'my-button');
      await fs.mkdir(componentDir, { recursive: true });
      await fs.writeFile(
        path.join(componentDir, 'component.yml'),
        yaml.dump({ name: 'Old', machineName: 'my-button', status: true }),
        'utf-8',
      );

      const sourceCodeJs = [
        'type ButtonProps = { label: string };',
        'export default function Button({ label }: ButtonProps) {',
        '  return <button>{label}</button>;',
        '}',
      ].join('\n');
      const component: Component = {
        ...mockComponent('my-button'),
        sourceCodeJs,
      };
      const api = mockApiService({ a: component });
      const task = createComponentsPullTask(api, tmpDir, false);

      await task.prepare();
      const results = await task.execute();

      expect(results.results).toHaveLength(1);
      expect(results.results[0].success).toBe(true);
      expect(
        await fs.readFile(path.join(componentDir, 'index.tsx'), 'utf-8'),
      ).toBe(sourceCodeJs);
      await expect(
        fs.access(path.join(componentDir, 'index.jsx')),
      ).rejects.toMatchObject({ code: 'ENOENT' });
    });

    it('should create new CSS file when updating component that lacks local CSS', async () => {
      // Set up an existing component with no CSS file.
      const componentDir = path.join(tmpDir, 'my-button');
      await fs.mkdir(componentDir, { recursive: true });

      await fs.writeFile(
        path.join(componentDir, 'component.yml'),
        yaml.dump({ name: 'Old', machineName: 'my-button', status: true }),
        'utf-8',
      );
      await fs.writeFile(
        path.join(componentDir, 'index.jsx'),
        'old js',
        'utf-8',
      );

      const component: Component = {
        ...mockComponent('my-button'),
        name: 'My Button',
        sourceCodeJs: 'new js',
        sourceCodeCss: '.btn { color: blue; }',
      };

      const api = mockApiService({ a: component });
      const task = createComponentsPullTask(api, tmpDir, false);

      await task.prepare();
      const results = await task.execute();

      expect(results.results).toHaveLength(1);
      expect(results.results[0].success).toBe(true);

      // CSS file should be created even though it didn't exist before.
      const cssPath = path.join(componentDir, 'index.css');
      expect(await fs.readFile(cssPath, 'utf-8')).toBe('.btn { color: blue; }');
    });

    it('should skip existing components with skipOverwrite', async () => {
      // Set up an existing component on disk.
      const componentDir = path.join(tmpDir, 'my-button');
      await fs.mkdir(componentDir, { recursive: true });

      const metadataPath = path.join(componentDir, 'component.yml');
      await fs.writeFile(
        metadataPath,
        yaml.dump({ name: 'Old', machineName: 'my-button', status: true }),
        'utf-8',
      );
      await fs.writeFile(
        path.join(componentDir, 'index.jsx'),
        'old js',
        'utf-8',
      );

      const api = mockApiService({ a: mockComponent('my-button') });
      const task = createComponentsPullTask(api, tmpDir, true);

      await task.prepare();
      const results = await task.execute();

      expect(results.results).toHaveLength(1);
      expect(results.results[0].success).toBe(true);
      expect(results.results[0].details?.[0].content).toContain('Skipped');

      // Metadata should NOT be updated.
      const ymlContent = await fs.readFile(metadataPath, 'utf-8');
      const parsed = yaml.load(ymlContent) as Record<string, unknown>;
      expect(parsed).toHaveProperty('name', 'Old');
    });

    it('should delete local-only directories when deleteLocalOnly is true', async () => {
      const orphanDir = path.join(tmpDir, 'gone');
      await fs.mkdir(orphanDir, { recursive: true });
      await fs.writeFile(
        path.join(orphanDir, 'component.yml'),
        yaml.dump({ name: 'gone', machineName: 'gone', status: true }),
        'utf-8',
      );
      await fs.writeFile(
        path.join(orphanDir, 'index.jsx'),
        'export default function gone() {}',
        'utf-8',
      );

      const api = mockApiService({});
      const task = createComponentsPullTask(api, tmpDir, false);

      await task.prepare();
      const results = await task.execute({ deleteLocalOnly: true });

      expect(results.results).toHaveLength(1);
      expect(results.results[0].success).toBe(true);
      expect(results.results[0].details?.[0].content).toBe('Deleted');
      await expect(fs.access(orphanDir)).rejects.toThrow();
    });
  });

  describe('createAssetsPullTask', () => {
    let tmpDir: string;
    let globalCssPath: string;

    beforeEach(async () => {
      tmpDir = await fs.mkdtemp(path.join(os.tmpdir(), 'pull-css-test-'));
      globalCssPath = path.join(tmpDir, 'global.css');
    });

    afterEach(async () => {
      await fs.rm(tmpDir, { recursive: true, force: true });
    });

    function mockApiService(
      css: string,
      packageJson?: string,
      assets?: unknown[],
      downloadFile?: ReturnType<typeof vi.fn>,
      bundledSources?: unknown[],
    ): ApiService {
      return {
        getGlobalAssetLibrary: vi.fn().mockResolvedValue({
          css: { original: css },
          packageJson,
          assets,
          bundledSources,
        }),
        downloadFile:
          downloadFile ??
          vi.fn().mockResolvedValue(Buffer.from([0x00, 0x01, 0x02])),
      } as unknown as ApiService;
    }

    it('should include global CSS in summary', async () => {
      const api = mockApiService('body {}');
      const task = createAssetsPullTask(api, globalCssPath, false, tmpDir);

      const { summaryLines } = await task.prepare();
      expect(summaryLines).toEqual(['Assets: global CSS pull']);
    });

    it('should return empty summary when no global CSS', async () => {
      const api = mockApiService('');
      const task = createAssetsPullTask(api, globalCssPath, false, tmpDir);

      const { summaryLines } = await task.prepare();
      expect(summaryLines).toEqual([]);
    });

    it('should return no asset results when no global CSS is planned', async () => {
      const api = mockApiService('');
      const task = createAssetsPullTask(api, globalCssPath, false, tmpDir);

      await task.prepare();
      const results = await task.execute();

      expect(results.title).toBe('Pulled assets');
      expect(results.label).toBe('Asset');
      expect(results.results).toEqual([]);
      await expect(fs.access(globalCssPath)).rejects.toThrow();
    });

    it('should write global.css file', async () => {
      const api = mockApiService('body { margin: 0; }');
      const task = createAssetsPullTask(api, globalCssPath, false, tmpDir);

      await task.prepare();
      const results = await task.execute();

      expect(results.title).toBe('Pulled assets');
      expect(results.label).toBe('Asset');
      expect(results.results).toHaveLength(1);
      expect(results.results[0].success).toBe(true);

      const cssContent = await fs.readFile(globalCssPath, 'utf-8');
      expect(cssContent).toBe('@import "tailwindcss";\nbody { margin: 0; }');
    });

    it('should prepend @import tailwindcss when remote CSS omits it', async () => {
      const api = mockApiService('@layer theme {\n  :root { --x: 1; }\n}');
      const task = createAssetsPullTask(api, globalCssPath, false, tmpDir);

      await task.prepare();
      await task.execute();

      const cssContent = await fs.readFile(globalCssPath, 'utf-8');
      expect(cssContent).toBe(
        '@import "tailwindcss";\n@layer theme {\n  :root { --x: 1; }\n}',
      );
    });

    it('should not duplicate @import when remote CSS already has tailwindcss entry', async () => {
      const remote =
        "@import 'tailwindcss';\n@layer base {\n  body { margin: 0; }\n}";
      const api = mockApiService(remote);
      const task = createAssetsPullTask(api, globalCssPath, false, tmpDir);

      await task.prepare();
      await task.execute();

      expect(await fs.readFile(globalCssPath, 'utf-8')).toBe(remote);
    });

    it('should not duplicate @import when remote uses double-quoted tailwindcss', async () => {
      const remote = '@import "tailwindcss";\n.foo { color: red; }';
      const api = mockApiService(remote);
      const task = createAssetsPullTask(api, globalCssPath, false, tmpDir);

      await task.prepare();
      await task.execute();

      expect(await fs.readFile(globalCssPath, 'utf-8')).toBe(remote);
    });

    it('should skip writing global.css with skipOverwrite when it already exists', async () => {
      await fs.writeFile(globalCssPath, 'old css', 'utf-8');

      const api = mockApiService('new css');
      const task = createAssetsPullTask(api, globalCssPath, true, tmpDir);

      await task.prepare();
      const results = await task.execute();

      expect(results.results).toHaveLength(1);
      expect(results.results[0].success).toBe(true);
      expect(results.results[0].details?.[0].content).toContain('Skipped');

      // File should NOT be updated.
      const cssContent = await fs.readFile(globalCssPath, 'utf-8');
      expect(cssContent).toBe('old css');
    });

    it('should write package.json to project root when present', async () => {
      const packageJson = '{\n  "name": "my-project"\n}\n';
      const api = mockApiService('body {}', packageJson);
      const task = createAssetsPullTask(api, globalCssPath, false, tmpDir);

      const { summaryLines } = await task.prepare();
      expect(summaryLines).toEqual(['Assets: global CSS, package.json pull']);

      const results = await task.execute();
      const packageJsonResult = results.results.find(
        (r) => r.itemName === 'package.json',
      );
      expect(packageJsonResult?.success).toBe(true);

      const written = await fs.readFile(
        path.join(tmpDir, 'package.json'),
        'utf-8',
      );
      expect(written).toBe(packageJson);
    });

    it('should write package.json even when no global CSS exists', async () => {
      const packageJson = '{ "name": "css-less" }';
      const api = mockApiService('', packageJson);
      const task = createAssetsPullTask(api, globalCssPath, false, tmpDir);

      const { summaryLines } = await task.prepare();
      expect(summaryLines).toEqual(['Assets: package.json pull']);

      const results = await task.execute();
      expect(results.results).toHaveLength(1);
      expect(results.results[0].itemName).toBe('package.json');
      expect(results.results[0].success).toBe(true);
      expect(
        await fs.readFile(path.join(tmpDir, 'package.json'), 'utf-8'),
      ).toBe(packageJson);
    });

    it('should overwrite an existing package.json by default', async () => {
      await fs.writeFile(
        path.join(tmpDir, 'package.json'),
        '{ "name": "old" }',
        'utf-8',
      );
      const api = mockApiService('', '{ "name": "new" }');
      const task = createAssetsPullTask(api, globalCssPath, false, tmpDir);

      await task.prepare();
      await task.execute();

      expect(
        await fs.readFile(path.join(tmpDir, 'package.json'), 'utf-8'),
      ).toBe('{ "name": "new" }');
    });

    it('should skip writing package.json with skipOverwrite when it already exists', async () => {
      await fs.writeFile(
        path.join(tmpDir, 'package.json'),
        '{ "name": "old" }',
        'utf-8',
      );
      const api = mockApiService('', '{ "name": "new" }');
      const task = createAssetsPullTask(api, globalCssPath, true, tmpDir);

      await task.prepare();
      const results = await task.execute();

      expect(results.results[0].itemName).toBe('package.json');
      expect(results.results[0].details?.[0].content).toContain('Skipped');
      expect(
        await fs.readFile(path.join(tmpDir, 'package.json'), 'utf-8'),
      ).toBe('{ "name": "old" }');
    });

    it('should summarize codebase files with a path', async () => {
      const api = mockApiService('', undefined, [
        {
          name: '@/lib/foo',
          uri: 'public://x',
          path: 'src/lib/foo.ts',
          source: 'export const x = 1;\n',
        },
        {
          name: '@/assets/p.webp',
          uri: 'public://p',
          path: 'src/assets/p.webp',
          url: 'http://h/p',
        },
        // Legacy/vendor entry without a path is ignored.
        { name: 'lodash', uri: 'public://l' },
      ]);
      const task = createAssetsPullTask(api, globalCssPath, false, tmpDir);

      const { summaryLines } = await task.prepare();
      expect(summaryLines).toEqual(['Assets: 2 local imports pull']);
    });

    it('should write a text module from source, not download it', async () => {
      const downloadFile = vi.fn();
      const source = 'export const cn = () => "";\n';
      const api = mockApiService(
        '',
        undefined,
        [
          {
            name: '@/lib/utils',
            uri: 'public://u',
            path: 'src/lib/utils.ts',
            source,
          },
        ],
        downloadFile,
      );
      const task = createAssetsPullTask(api, globalCssPath, false, tmpDir);

      await task.prepare();
      const results = await task.execute();

      const result = results.results.find(
        (r) => r.itemName === 'src/lib/utils.ts',
      );
      expect(result?.success).toBe(true);
      expect(downloadFile).not.toHaveBeenCalled();
      expect(
        await fs.readFile(path.join(tmpDir, 'src/lib/utils.ts'), 'utf-8'),
      ).toBe(source);
    });

    it('should download a binary asset and write the bytes', async () => {
      const bytes = Buffer.from([0x89, 0x50, 0x4e, 0x47]);
      const downloadFile = vi.fn().mockResolvedValue(bytes);
      const api = mockApiService(
        '',
        undefined,
        [
          {
            name: '@/assets/p.png',
            uri: 'public://p',
            path: 'src/assets/p.png',
            url: 'http://h/p.png',
          },
        ],
        downloadFile,
      );
      const task = createAssetsPullTask(api, globalCssPath, false, tmpDir);

      await task.prepare();
      const results = await task.execute();

      const result = results.results.find(
        (r) => r.itemName === 'src/assets/p.png',
      );
      expect(result?.success).toBe(true);
      expect(downloadFile).toHaveBeenCalledWith('http://h/p.png');
      expect(await fs.readFile(path.join(tmpDir, 'src/assets/p.png'))).toEqual(
        bytes,
      );
    });

    it('should skip an existing flexible file with skipOverwrite', async () => {
      await fs.mkdir(path.join(tmpDir, 'src/lib'), { recursive: true });
      await fs.writeFile(path.join(tmpDir, 'src/lib/utils.ts'), 'old', 'utf-8');
      const api = mockApiService('', undefined, [
        {
          name: '@/lib/utils',
          uri: 'public://u',
          path: 'src/lib/utils.ts',
          source: 'new',
        },
      ]);
      const task = createAssetsPullTask(api, globalCssPath, true, tmpDir);

      await task.prepare();
      const results = await task.execute();

      const result = results.results.find(
        (r) => r.itemName === 'src/lib/utils.ts',
      );
      expect(result?.details?.[0].content).toContain('Skipped');
      expect(
        await fs.readFile(path.join(tmpDir, 'src/lib/utils.ts'), 'utf-8'),
      ).toBe('old');
    });

    it('should reject a flexible file that escapes the project root', async () => {
      const api = mockApiService('', undefined, [
        {
          name: '@/evil',
          uri: 'public://e',
          path: '../escape.ts',
          source: 'x',
        },
      ]);
      const task = createAssetsPullTask(api, globalCssPath, false, tmpDir);

      await task.prepare();
      const results = await task.execute();

      const result = results.results.find((r) => r.itemName === '../escape.ts');
      expect(result?.success).toBe(false);
      expect(result?.details?.[0].content).toContain(
        'outside the project root',
      );
    });

    it('should reject asset paths redirected outside through a symlink', async () => {
      const outsideDir = await fs.mkdtemp(
        path.join(os.tmpdir(), 'pull-assets-outside-test-'),
      );
      try {
        await fs.symlink(outsideDir, path.join(tmpDir, 'linked'));
        const api = mockApiService(
          '',
          undefined,
          [
            {
              name: '@/linked/asset.ts',
              uri: 'public://asset',
              path: 'linked/asset.ts',
              source: 'asset',
            },
          ],
          undefined,
          [
            {
              path: 'linked/helper.ts',
              source: 'helper',
            },
          ],
        );
        const task = createAssetsPullTask(api, globalCssPath, false, tmpDir);

        await task.prepare();
        const results = await task.execute();

        expect(results.results).toHaveLength(2);
        expect(results.results.every((result) => !result.success)).toBe(true);
        for (const result of results.results) {
          expect(result.details?.[0].content).toContain(
            'outside the project root through a symbolic link',
          );
        }
        await expect(
          fs.access(path.join(outsideDir, 'asset.ts')),
        ).rejects.toThrow();
        await expect(
          fs.access(path.join(outsideDir, 'helper.ts')),
        ).rejects.toThrow();
      } finally {
        await fs.rm(outsideDir, { recursive: true, force: true });
      }
    });

    it('should complete cleanly when no flexible files exist server-side', async () => {
      const api = mockApiService('', undefined, []);
      const task = createAssetsPullTask(api, globalCssPath, false, tmpDir);

      const { summaryLines } = await task.prepare();
      expect(summaryLines).toEqual([]);

      const results = await task.execute();
      expect(results.results).toEqual([]);
    });
  });

  describe('createPagesPullTask', () => {
    let tmpDir: string;

    beforeEach(async () => {
      tmpDir = await fs.mkdtemp(path.join(os.tmpdir(), 'pull-pages-test-'));
    });

    afterEach(async () => {
      await fs.rm(tmpDir, { recursive: true, force: true });
    });

    const mockPageListItem = (
      id: number,
      uuid: string,
      title: string,
      pagePath: string,
    ): PageListItem => ({
      id,
      uuid,
      title,
      status: true,
      path: pagePath,
      internalPath: `/page/${id}`,
      autoSaveLabel: null,
      autoSavePath: null,
      links: {},
      description: '',
    });

    const mockPage = (
      id: number,
      uuid: string,
      title: string,
      pagePath: string,
      components: Page['components'] = [],
    ): Page => ({
      ...mockPageListItem(id, uuid, title, pagePath),
      pageVariant: null,
      components,
    });

    function mockApiService(
      pages: Record<string, PageListItem>,
      pageDetails: Record<number, Page> = {},
    ): ApiService {
      return {
        listPages: vi.fn().mockResolvedValue(pages),
        getPage: vi.fn().mockImplementation((id: number) => {
          if (pageDetails[id]) return Promise.resolve(pageDetails[id]);
          return Promise.resolve({ ...pages[String(id)], components: [] });
        }),
      } as unknown as ApiService;
    }

    it('should return empty summary when no pages', async () => {
      const api = mockApiService({});
      const task = createPagesPullTask(api, tmpDir, false);

      const { summaryLines } = await task.prepare();
      expect(summaryLines).toEqual([]);
    });

    it('should show only new counts in summary when none exist locally', async () => {
      const api = mockApiService({
        '1': mockPageListItem(
          1,
          '27a539f5-2dd0-471a-a364-8fee7a024a73',
          'About',
          '/about',
        ),
      });
      const task = createPagesPullTask(api, tmpDir, false);

      const { summaryLines } = await task.prepare();
      expect(summaryLines).toEqual(['Pages: 1 pull (1 new)']);
    });

    it('should show both new and existing counts in summary', async () => {
      await fs.writeFile(
        path.join(tmpDir, 'about.json'),
        JSON.stringify({
          uuid: '27a539f5-2dd0-471a-a364-8fee7a024a73',
          title: 'About',
          elements: {},
        }),
        'utf-8',
      );

      const api = mockApiService({
        '1': mockPageListItem(
          1,
          '27a539f5-2dd0-471a-a364-8fee7a024a73',
          'About',
          '/about',
        ),
        '2': mockPageListItem(
          2,
          'f47ac10b-58cc-4372-a567-0e02b2c3d479',
          'Contact',
          '/contact',
        ),
      });
      const task = createPagesPullTask(api, tmpDir, false);

      const { summaryLines } = await task.prepare();
      expect(summaryLines).toEqual(['Pages: 2 pull (1 new, 1 existing)']);
    });

    it('should write new page files on execute', async () => {
      const detail = mockPage(
        1,
        '27a539f5-2dd0-471a-a364-8fee7a024a73',
        'About',
        '/about',
        [
          {
            uuid: 'hero-uuid',
            component_id: 'js.hero',
            component_version: 'v1',
            parent_uuid: null,
            slot: null,
            inputs: { heading: 'About Us' },
            inputs_resolved: { heading: 'About Us' },
            label: null,
          },
        ],
      );

      const api = mockApiService(
        {
          '1': mockPageListItem(
            1,
            '27a539f5-2dd0-471a-a364-8fee7a024a73',
            'About',
            '/about',
          ),
        },
        { 1: detail },
      );
      const task = createPagesPullTask(api, tmpDir, false);

      await task.prepare();
      const results = await task.execute();

      expect(results.title).toBe('Pulled pages');
      expect(results.label).toBe('Page');
      expect(results.results).toHaveLength(1);
      expect(results.results[0].success).toBe(true);

      // New pages use the path alias as the filename.
      const filePath = path.join(tmpDir, 'about.json');
      const content = JSON.parse(await fs.readFile(filePath, 'utf-8'));
      expect(content.title).toBe('About');
      expect(content.elements['hero-uuid']).toEqual({
        type: 'js.hero',
        props: { heading: 'About Us' },
      });
    });

    it('should write the root page to index.json', async () => {
      const detail = mockPage(
        1,
        '27a539f5-2dd0-471a-a364-8fee7a024a73',
        'Home',
        '/',
      );

      const api = mockApiService(
        {
          '1': mockPageListItem(
            1,
            '27a539f5-2dd0-471a-a364-8fee7a024a73',
            'Home',
            '/',
          ),
        },
        { 1: detail },
      );
      const task = createPagesPullTask(api, tmpDir, false);

      await task.prepare();
      const results = await task.execute();

      expect(results.results).toHaveLength(1);
      expect(results.results[0].success).toBe(true);

      const content = JSON.parse(
        await fs.readFile(path.join(tmpDir, 'index.json'), 'utf-8'),
      );
      expect(content.title).toBe('Home');
    });

    it('should skip pages with non-JS components', async () => {
      const detail = mockPage(
        1,
        '27a539f5-2dd0-471a-a364-8fee7a024a73',
        'About',
        '/about',
        [
          {
            uuid: 'hero-uuid',
            component_id: 'sdc.theme.hero',
            component_version: 'v1',
            parent_uuid: null,
            slot: null,
            inputs: { heading: 'About Us' },
            label: null,
          },
        ],
      );

      const api = mockApiService(
        {
          '1': mockPageListItem(
            1,
            '27a539f5-2dd0-471a-a364-8fee7a024a73',
            'About',
            '/about',
          ),
        },
        { 1: detail },
      );
      const task = createPagesPullTask(api, tmpDir, false);

      await task.prepare();
      const results = await task.execute();

      expect(results.results).toHaveLength(1);
      expect(results.results[0].success).toBe(false);
      expect(results.results[0].details?.[0].content).toContain(
        'unsupported components',
      );
      expect(results.results[0].details?.[0].content).toContain(
        'sdc.theme.hero',
      );

      // File should NOT be created.
      const files = await fs.readdir(tmpDir);
      expect(files).toHaveLength(0);
    });

    it('should update existing page files', async () => {
      await fs.writeFile(
        path.join(tmpDir, 'about.json'),
        JSON.stringify({
          uuid: '27a539f5-2dd0-471a-a364-8fee7a024a73',
          title: 'Old About',
          elements: {},
        }),
        'utf-8',
      );

      const detail = mockPage(
        1,
        '27a539f5-2dd0-471a-a364-8fee7a024a73',
        'About',
        '/about',
      );
      const api = mockApiService(
        {
          '1': mockPageListItem(
            1,
            '27a539f5-2dd0-471a-a364-8fee7a024a73',
            'About',
            '/about',
          ),
        },
        { 1: detail },
      );
      const task = createPagesPullTask(api, tmpDir, false);

      await task.prepare();
      const results = await task.execute();

      expect(results.results).toHaveLength(1);
      expect(results.results[0].success).toBe(true);

      const content = JSON.parse(
        await fs.readFile(path.join(tmpDir, 'about.json'), 'utf-8'),
      );
      expect(content.title).toBe('About');
    });

    it('should skip existing pages with skipOverwrite', async () => {
      await fs.writeFile(
        path.join(tmpDir, 'about.json'),
        JSON.stringify({
          uuid: '27a539f5-2dd0-471a-a364-8fee7a024a73',
          title: 'Old About',
          elements: {},
        }),
        'utf-8',
      );

      const api = mockApiService({
        '1': mockPageListItem(
          1,
          '27a539f5-2dd0-471a-a364-8fee7a024a73',
          'About',
          '/about',
        ),
      });
      const task = createPagesPullTask(api, tmpDir, true);

      await task.prepare();
      const results = await task.execute();

      expect(results.results).toHaveLength(1);
      expect(results.results[0].success).toBe(true);
      expect(results.results[0].details?.[0].content).toContain('Skipped');

      // File should NOT be updated.
      const content = JSON.parse(
        await fs.readFile(path.join(tmpDir, 'about.json'), 'utf-8'),
      );
      expect(content.title).toBe('Old About');
    });

    it('should match existing UUID-less pages by filename with skipOverwrite', async () => {
      await fs.writeFile(
        path.join(tmpDir, 'about.json'),
        JSON.stringify({
          title: 'Local About',
          elements: {},
        }),
        'utf-8',
      );

      const api = mockApiService({
        '1': mockPageListItem(
          1,
          '27a539f5-2dd0-471a-a364-8fee7a024a73',
          'About',
          '/about',
        ),
      });
      const task = createPagesPullTask(api, tmpDir, true);

      const { summaryLines } = await task.prepare();
      expect(summaryLines).toEqual(['Pages: 1 pull (1 existing)']);

      const results = await task.execute();

      expect(results.results).toHaveLength(1);
      expect(results.results[0].success).toBe(true);
      expect(results.results[0].details?.[0].content).toContain('Skipped');
      expect(api.getPage).not.toHaveBeenCalled();

      const content = JSON.parse(
        await fs.readFile(path.join(tmpDir, 'about.json'), 'utf-8'),
      );
      expect(content.title).toBe('Local About');
    });

    it('should match an existing UUID-less root page by index filename with skipOverwrite', async () => {
      await fs.writeFile(
        path.join(tmpDir, 'index.json'),
        JSON.stringify({
          title: 'Local Home',
          elements: {},
        }),
        'utf-8',
      );

      const api = mockApiService({
        '1': mockPageListItem(
          1,
          '27a539f5-2dd0-471a-a364-8fee7a024a73',
          'Home',
          '/',
        ),
      });
      const task = createPagesPullTask(api, tmpDir, true);

      const { summaryLines } = await task.prepare();
      expect(summaryLines).toEqual(['Pages: 1 pull (1 existing)']);

      const results = await task.execute();

      expect(results.results).toHaveLength(1);
      expect(results.results[0].success).toBe(true);
      expect(results.results[0].details?.[0].content).toContain('Skipped');
      expect(api.getPage).not.toHaveBeenCalled();

      const content = JSON.parse(
        await fs.readFile(path.join(tmpDir, 'index.json'), 'utf-8'),
      );
      expect(content.title).toBe('Local Home');
    });
  });

  describe('createFontsPullTask', () => {
    let tmpDir: string;

    beforeEach(async () => {
      tmpDir = await fs.mkdtemp(path.join(os.tmpdir(), 'pull-fonts-test-'));
      setConfig({ fonts: undefined });
    });

    afterEach(async () => {
      await fs.rm(tmpDir, { recursive: true, force: true });
    });

    function mockApiService(
      fonts: Array<{
        family: string;
        weight: string;
        style: string;
        url?: string;
      }>,
    ): ApiService {
      return {
        getBrandKit: vi.fn().mockResolvedValue({
          id: 'global',
          fonts: fonts.map((f, i) => ({
            id: `id-${i}`,
            family: f.family,
            uri: `public://canvas/font-${i}.woff2`,
            format: 'woff2',
            weight: f.weight,
            style: f.style,
            url: f.url ?? `/sites/default/files/font-${i}.woff2`,
          })),
        }),
        downloadFile: vi.fn().mockResolvedValue(Buffer.from([0x00, 0x01])),
      } as unknown as ApiService;
    }

    it('should include font variants in summary', async () => {
      const api = mockApiService([
        { family: 'Inter', weight: '400', style: 'normal' },
      ]);
      const task = createFontsPullTask(api, tmpDir);

      const { summaryLines } = await task.prepare();
      expect(summaryLines).toEqual(['brand kit: 1 font variant pull (1 new)']);
    });

    it('should return empty summary when no fonts on brand kit', async () => {
      const api = mockApiService([]);
      const task = createFontsPullTask(api, tmpDir);

      const { summaryLines } = await task.prepare();
      expect(summaryLines).toEqual([]);
    });

    it('should download fonts and update canvas.brand-kit.json on execute', async () => {
      const api = mockApiService([
        { family: 'My Font', weight: '400', style: 'normal' },
      ]);
      const task = createFontsPullTask(api, tmpDir);

      await task.prepare();
      const results = await task.execute();

      expect(results.title).toBe('Pulled brand kit');
      expect(results.label).toBe('Font variant');
      expect(results.results.length).toBeGreaterThanOrEqual(1);
      expect(results.results[0].success).toBe(true);
      expect(results.results[0].itemName).toContain('My Font');

      const configPath = path.join(tmpDir, 'canvas.brand-kit.json');
      const raw = await fs.readFile(configPath, 'utf-8');
      const config = JSON.parse(raw) as {
        fonts: { families: { name: string; src: string }[] };
      };
      expect(config.fonts.families).toHaveLength(1);
      expect(config.fonts.families[0].name).toBe('My Font');
      expect(config.fonts.families[0].src).toContain('fonts/');

      const fontsDir = path.join(tmpDir, 'fonts');
      const files = await fs.readdir(fontsDir);
      expect(files.length).toBe(1);
    });
  });
});
