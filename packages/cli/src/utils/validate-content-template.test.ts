import fs from 'fs/promises';
import os from 'os';
import path from 'path';
import yaml from 'js-yaml';
import { describe, expect, it } from 'vitest';

import {
  buildContentTemplateValidationContext,
  validateContentTemplateElements,
  validateContentTemplates,
} from './validate-content-template';

import type {
  ComponentMetadata,
  DiscoveryResult,
} from '@drupal-canvas/discovery';
import type { AuthoredSpecElementMap } from 'drupal-canvas/json-render-utils';

const heroMetadata: ComponentMetadata = {
  name: 'Hero',
  machineName: 'hero',
  status: true,
  props: { properties: { title: { title: 'Title', type: 'string' } } },
  required: ['title'],
  slots: {},
};

async function writeComponentMetadataFiles(
  rootDir: string,
  components: ComponentMetadata[],
): Promise<DiscoveryResult['components']> {
  return Promise.all(
    components.map(async (m) => {
      const componentDir = path.join(rootDir, m.machineName);
      await fs.mkdir(componentDir, { recursive: true });
      const metadataPath = path.join(componentDir, 'component.yml');
      await fs.writeFile(metadataPath, yaml.dump(m), 'utf-8');
      return {
        id: m.machineName,
        kind: 'named' as const,
        name: m.name,
        machineName: m.machineName,
        status: m.status,
        directory: componentDir,
        relativeDirectory: m.machineName,
        projectRelativeDirectory: m.machineName,
        metadataPath,
        jsEntryPath: null,
        cssEntryPath: null,
      };
    }),
  );
}

async function makeDiscovery(
  templatePath: string,
  slug: string,
  components: ComponentMetadata[] = [],
): Promise<DiscoveryResult> {
  const rootDir = path.dirname(templatePath);
  return {
    componentRoot: rootDir,
    projectRoot: rootDir,
    components: await writeComponentMetadataFiles(rootDir, components),
    pages: [],
    contentTemplates: [
      {
        name: slug,
        slug,
        label: null,
        entityTypeId: 'node',
        bundle: 'article',
        viewMode: 'full',
        path: templatePath,
        relativePath: `content-templates/${slug}.json`,
      },
    ],
    regions: [],
    warnings: [],
    stats: { scannedFiles: 1, ignoredFiles: 0 },
  };
}

describe('validateContentTemplateElements', () => {
  it('reports unknown component types', () => {
    const elements: AuthoredSpecElementMap = {
      el1: { type: 'js.does-not-exist' },
    };
    const context = buildContentTemplateValidationContext([]);
    const details = validateContentTemplateElements(elements, context);
    expect(details).toHaveLength(1);
    expect(details[0].content).toContain('Unknown component');
  });

  it('reports disabled components', () => {
    const elements: AuthoredSpecElementMap = {
      el1: { type: 'js.hero' },
    };
    const context = buildContentTemplateValidationContext([
      { ...heroMetadata, status: false },
    ]);
    const details = validateContentTemplateElements(elements, context);
    expect(details).toHaveLength(1);
    expect(details[0].content).toContain('disabled');
  });

  it('reports dangling slot references', () => {
    const elements: AuthoredSpecElementMap = {
      el1: { type: 'js.hero', slots: { children: ['ghost'] } },
    };
    const context = buildContentTemplateValidationContext([heroMetadata]);
    const details = validateContentTemplateElements(elements, context);
    expect(details).toHaveLength(1);
    expect(details[0].content).toContain('Unknown element "ghost"');
  });

  it('passes a valid element map', () => {
    const elements: AuthoredSpecElementMap = {
      el1: {
        type: 'js.hero',
        props: {
          title: { sourceType: 'entity-field', expression: 'X' },
        },
      },
    };
    const context = buildContentTemplateValidationContext([heroMetadata]);
    expect(validateContentTemplateElements(elements, context)).toEqual([]);
  });
});

describe('validateContentTemplates', () => {
  async function withTempTemplate<T>(
    spec: unknown,
    components: ComponentMetadata[],
    fn: (discovery: DiscoveryResult) => Promise<T>,
  ): Promise<T> {
    const tmpDir = await fs.mkdtemp(path.join(os.tmpdir(), 'canvas-ct-'));
    const templatePath = path.join(tmpDir, 'article.json');
    await fs.writeFile(templatePath, JSON.stringify(spec), 'utf-8');
    try {
      return await fn(await makeDiscovery(templatePath, 'article', components));
    } finally {
      await fs.rm(tmpDir, { recursive: true, force: true });
    }
  }

  it('accepts a well-formed template referencing a known component', async () => {
    await withTempTemplate(
      {
        label: 'Article — Full',
        entityType: 'node',
        bundle: 'article',
        viewMode: 'full',
        elements: {
          hero: {
            type: 'js.hero',
            props: {
              title: { sourceType: 'entity-field', expression: 'X' },
            },
          },
        },
      },
      [heroMetadata],
      async (discovery) => {
        const { results } = await validateContentTemplates(discovery);
        expect(results).toEqual([{ itemName: 'article', success: true }]);
      },
    );
  });

  it('rejects a template missing required top-level metadata', async () => {
    await withTempTemplate(
      {
        // Missing label.
        entityType: 'node',
        bundle: 'article',
        viewMode: 'full',
        elements: { hero: { type: 'js.hero' } },
      },
      [heroMetadata],
      async (discovery) => {
        const { results } = await validateContentTemplates(discovery);
        expect(results[0].success).toBe(false);
        expect(
          results[0].details?.some((d) => d.content.includes('label')),
        ).toBe(true);
      },
    );
  });

  it('rejects a template with a malformed sourceType prop', async () => {
    await withTempTemplate(
      {
        label: 'Article',
        entityType: 'node',
        bundle: 'article',
        viewMode: 'full',
        elements: {
          hero: {
            type: 'js.hero',
            // sourceType must be a non-empty string; an empty string is a
            // common typo and used to slip through. The schema fix routes
            // it to the propSource branch where minLength: 1 fails it.
            props: { title: { sourceType: '' } },
          },
        },
      },
      [heroMetadata],
      async (discovery) => {
        const { results } = await validateContentTemplates(discovery);
        expect(results[0].success).toBe(false);
      },
    );
  });
});
