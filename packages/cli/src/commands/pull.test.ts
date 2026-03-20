import fs from 'fs/promises';
import os from 'os';
import path from 'path';
import yaml from 'js-yaml';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { createAssetsPullTask, createComponentsPullTask } from './pull';

import type { ApiService } from '../services/api';
import type { Component } from '../types/Component';

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
      expect(summaryLines).toEqual(['- 1 component (1 new)']);
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
      expect(summaryLines).toEqual(['- 3 components (2 new, 1 existing)']);
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
      expect(summaryLines).toEqual(['- 1 component to delete (local-only)']);
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
        '- 1 component (1 new)',
        '- 1 component to delete (local-only)',
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

    function mockApiService(css: string): ApiService {
      return {
        getGlobalAssetLibrary: vi
          .fn()
          .mockResolvedValue({ css: { original: css } }),
      } as unknown as ApiService;
    }

    it('should include global CSS in summary', async () => {
      const api = mockApiService('body {}');
      const task = createAssetsPullTask(api, globalCssPath, false);

      const { summaryLines } = await task.prepare();
      expect(summaryLines).toEqual(['- global CSS']);
    });

    it('should return empty summary when no global CSS', async () => {
      const api = mockApiService('');
      const task = createAssetsPullTask(api, globalCssPath, false);

      const { summaryLines } = await task.prepare();
      expect(summaryLines).toEqual([]);
    });

    it('should write global.css file', async () => {
      const api = mockApiService('body { margin: 0; }');
      const task = createAssetsPullTask(api, globalCssPath, false);

      await task.prepare();
      const results = await task.execute();

      expect(results.title).toBe('Pulled assets');
      expect(results.label).toBe('Asset');
      expect(results.results).toHaveLength(1);
      expect(results.results[0].success).toBe(true);

      const cssContent = await fs.readFile(globalCssPath, 'utf-8');
      expect(cssContent).toBe('body { margin: 0; }');
    });

    it('should skip writing global.css with skipOverwrite when it already exists', async () => {
      await fs.writeFile(globalCssPath, 'old css', 'utf-8');

      const api = mockApiService('new css');
      const task = createAssetsPullTask(api, globalCssPath, true);

      await task.prepare();
      const results = await task.execute();

      expect(results.results).toHaveLength(1);
      expect(results.results[0].success).toBe(true);
      expect(results.results[0].details?.[0].content).toContain('Skipped');

      // File should NOT be updated.
      const cssContent = await fs.readFile(globalCssPath, 'utf-8');
      expect(cssContent).toBe('old css');
    });
  });
});
