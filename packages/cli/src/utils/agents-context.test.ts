import fs from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import { Command } from 'commander';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { getConfig, setConfig } from '../config';
import {
  formatAgentsContextProviders,
  pullAgentsContext,
} from './agents-context';

import type { ApiService } from '../services/api';

describe('pullAgentsContext', () => {
  let tmpDir: string;
  let originalComponentDir: string;

  beforeEach(async () => {
    tmpDir = await fs.mkdtemp(path.join(os.tmpdir(), 'agents-context-'));
    originalComponentDir = getConfig().componentDir;
  });

  afterEach(async () => {
    setConfig({ componentDir: originalComponentDir });
    await fs.rm(tmpDir, { recursive: true, force: true });
  });

  it('lists providers without content-templates aliases', () => {
    const command = new Command()
      .name('agents-context')
      .argument('[provider]', 'Context provider to execute')
      .option('--all', 'Execute all default context providers');
    const output = formatAgentsContextProviders(command);

    expect(output).toContain('Available context providers:');
    expect(output).toMatch(
      /content-templates\s+Writes prop source suggestions and entity view modes\./,
    );
    expect(output).toContain(
      'content-entity-reference-expressions (cer-expressions)',
    );
    expect(output).toContain('content-entity-reference-preview (cer-preview)');
    expect(output).toMatch(
      /Prints resolved content-entity-reference prop preview values for one component\.\n\s{10,}Usage: canvas agents-context cer-preview <component-id>/,
    );
    expect(output).not.toContain('aliases: cer-expressions');
    expect(output).not.toContain('aliases: prop-sources, view-modes');
  });

  it('requires a provider or explicit all-provider run', async () => {
    await expect(pullAgentsContext({} as ApiService, tmpDir)).rejects.toThrow(
      'Specify an agents context provider or use --all.',
    );
  });

  it('reports generated context file paths relative to the project root', async () => {
    const apiService = {
      listComponents: vi.fn(async () => ({})),
      listViewModes: vi.fn(async () => ({
        node: {
          article: {
            default: {
              label: 'Default',
              hasTemplate: false,
            },
          },
        },
      })),
    } as unknown as ApiService;
    const messages: string[] = [];

    await pullAgentsContext(apiService, tmpDir, {
      provider: 'content-templates',
      writeMessage: (message) => messages.push(message),
    });

    expect(messages).toEqual([
      'Saved prop source suggestions for content template component props to .agents/drupal-canvas/prop-sources.json.',
      'Saved content template entity view modes to .agents/drupal-canvas/view-modes.json.',
    ]);
    await expect(
      fs.stat(
        path.join(tmpDir, '.agents', 'drupal-canvas', 'prop-sources.json'),
      ),
    ).resolves.toBeTruthy();
    await expect(
      fs.stat(path.join(tmpDir, '.agents', 'drupal-canvas', 'view-modes.json')),
    ).resolves.toBeTruthy();
  });

  it('outputs resolved CER prop preview context for one component', async () => {
    const componentRoot = path.join(tmpDir, 'components');
    const componentDir = path.join(componentRoot, 'article-card');
    await fs.mkdir(componentDir, { recursive: true });
    await fs.writeFile(
      path.join(componentDir, 'index.tsx'),
      'export default {};',
    );
    await fs.writeFile(
      path.join(componentDir, 'component.yml'),
      [
        'name: Article card',
        'machineName: article-card',
        'props:',
        '  properties:',
        '    article:',
        '      title: Article',
        '      type: object',
        '      $ref: json-schema-definitions://canvas.module/content-entity-reference',
        'dataDependencies:',
        '  entityFields:',
        '    article:',
        '      - ℹ︎␜entity:node:article␝title␞␟value',
        '',
      ].join('\n'),
    );
    setConfig({ componentDir: componentRoot });

    const apiService = {
      fetchPreviewEntitySuggestions: vi.fn(async () => [
        { id: '2', label: 'Article title' },
      ]),
      previewContentEntityReferenceFields: vi.fn(async () => ({
        article: {
          __type: 'article',
          label: 'Article title',
        },
      })),
    } as unknown as ApiService;
    const messages: string[] = [];
    const outputs: unknown[] = [];

    await pullAgentsContext(apiService, tmpDir, {
      provider: 'cer-preview',
      providerArgs: ['js.article-card'],
      writeMessage: (message) => messages.push(message),
      writeOutput: (data) => outputs.push(data),
    });

    expect(messages).toEqual([]);
    expect(outputs).toEqual([
      {
        component: 'article-card',
        props: {
          article: {
            entityTypeId: 'node',
            bundle: 'article',
            entityFields: ['ℹ︎␜entity:node:article␝title␞␟value'],
            entityId: '2',
            entityLabel: 'Article title',
            value: {
              __type: 'article',
              label: 'Article title',
            },
          },
        },
      },
    ]);
    await expect(
      fs.stat(path.join(tmpDir, '.agents', 'drupal-canvas', '.gitignore')),
    ).resolves.toBeTruthy();
    expect('listComponents' in apiService).toBe(false);
    expect(apiService.fetchPreviewEntitySuggestions).toHaveBeenCalledWith(
      'node',
      'article',
    );
    expect(apiService.previewContentEntityReferenceFields).toHaveBeenCalledWith(
      'node',
      '2',
      {
        article: ['ℹ︎␜entity:node:article␝title␞␟value'],
      },
    );
  });

  it('reports CER props whose entity field target cannot be derived', async () => {
    const componentRoot = path.join(tmpDir, 'components');
    const componentDir = path.join(componentRoot, 'article-card');
    await fs.mkdir(componentDir, { recursive: true });
    await fs.writeFile(
      path.join(componentDir, 'index.tsx'),
      'export default {};',
    );
    await fs.writeFile(
      path.join(componentDir, 'component.yml'),
      [
        'name: Article card',
        'machineName: article-card',
        'props:',
        '  properties:',
        '    article:',
        '      title: Article',
        '      type: object',
        '      $ref: json-schema-definitions://canvas.module/content-entity-reference',
        'dataDependencies:',
        '  entityFields:',
        '    article:',
        '      - not-an-entity-field-expression',
        '',
      ].join('\n'),
    );
    setConfig({ componentDir: componentRoot });

    const apiService = {
      fetchPreviewEntitySuggestions: vi.fn(),
      previewContentEntityReferenceFields: vi.fn(),
    } as unknown as ApiService;
    const outputs: unknown[] = [];

    await pullAgentsContext(apiService, tmpDir, {
      provider: 'cer-preview',
      providerArgs: ['article-card'],
      writeMessage: () => {},
      writeOutput: (data) => outputs.push(data),
    });

    expect(outputs).toEqual([
      {
        component: 'article-card',
        props: {
          article: {
            entityFields: ['not-an-entity-field-expression'],
            error:
              'Unable to derive a bundled entity target from dataDependencies.entityFields.',
          },
        },
      },
    ]);
    expect(apiService.fetchPreviewEntitySuggestions).not.toHaveBeenCalled();
    expect(
      apiService.previewContentEntityReferenceFields,
    ).not.toHaveBeenCalled();
  });
});
