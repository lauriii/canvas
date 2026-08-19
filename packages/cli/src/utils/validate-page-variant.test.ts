import fs from 'fs/promises';
import os from 'os';
import path from 'path';
import yaml from 'js-yaml';
import { describe, expect, it } from 'vitest';

import { validatePageTemplates } from './validate-page-variant';

import type {
  ComponentMetadata,
  DiscoveredPageTemplate,
  DiscoveryResult,
} from '@drupal-canvas/discovery';

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

function makeDiscoveryResult(
  tmpDir: string,
  pageTemplates: DiscoveredPageTemplate[],
  components: DiscoveryResult['components'] = [],
): DiscoveryResult {
  return {
    componentRoot: tmpDir,
    projectRoot: tmpDir,
    components,
    pages: [],
    contentTemplates: [],
    pageTemplates,
    warnings: [],
    stats: { scannedFiles: pageTemplates.length, ignoredFiles: 0 },
  };
}

async function writePageTemplate(
  tmpDir: string,
  fileName: string,
  spec: unknown,
) {
  const pageTemplatePath = path.join(tmpDir, fileName);
  await fs.writeFile(pageTemplatePath, JSON.stringify(spec), 'utf-8');
  return pageTemplatePath;
}

function discovered(id: string, filePath: string): DiscoveredPageTemplate {
  return {
    id,
    label: id,
    status: true,
    isDefault: false,
    path: filePath,
    relativePath: `page-templates/${id}.json`,
  };
}

describe('validatePageTemplates', () => {
  it('accepts a page template holding only the page content marker', async () => {
    const tmpDir = await fs.mkdtemp(
      path.join(os.tmpdir(), 'canvas-page-template-'),
    );
    try {
      const pageTemplatePath = await writePageTemplate(
        tmpDir,
        'marketing.json',
        {
          label: 'Marketing',
          elements: { content: { type: 'marker.page_content' } },
        },
      );
      const discoveryResult = makeDiscoveryResult(tmpDir, [
        discovered('marketing', pageTemplatePath),
      ]);

      await expect(validatePageTemplates(discoveryResult)).resolves.toEqual({
        results: [{ itemName: 'marketing', success: true }],
      });
    } finally {
      await fs.rm(tmpDir, { recursive: true, force: true });
    }
  });

  it('requires exactly one page content marker', async () => {
    const tmpDir = await fs.mkdtemp(
      path.join(os.tmpdir(), 'canvas-page-template-'),
    );
    try {
      const nonePath = await writePageTemplate(tmpDir, 'none.json', {
        label: 'No marker',
        elements: {},
      });
      const twicePath = await writePageTemplate(tmpDir, 'twice.json', {
        label: 'Two markers',
        elements: {
          content: { type: 'marker.page_content' },
          second: { type: 'marker.page_content' },
        },
      });
      const discoveryResult = makeDiscoveryResult(tmpDir, [
        discovered('none', nonePath),
        discovered('twice', twicePath),
      ]);

      const { results } = await validatePageTemplates(discoveryResult);
      expect(results.every((r) => !r.success)).toBe(true);
      expect(
        results[0].details?.some((d) =>
          d.content.includes('add an element with type "marker.page_content"'),
        ),
      ).toBe(true);
      expect(
        results[1].details?.some((d) => d.content.includes('found 2')),
      ).toBe(true);
    } finally {
      await fs.rm(tmpDir, { recursive: true, force: true });
    }
  });

  it('reports missing required fields', async () => {
    const tmpDir = await fs.mkdtemp(
      path.join(os.tmpdir(), 'canvas-page-template-'),
    );
    try {
      const pageTemplatePath = await writePageTemplate(
        tmpDir,
        'marketing.json',
        {},
      );
      const discoveryResult = makeDiscoveryResult(tmpDir, [
        discovered('marketing', pageTemplatePath),
      ]);

      const { results } = await validatePageTemplates(discoveryResult);
      expect(results).toHaveLength(1);
      expect(results[0].success).toBe(false);
      expect(results[0].details?.some((d) => d.content.includes('label'))).toBe(
        true,
      );
    } finally {
      await fs.rm(tmpDir, { recursive: true, force: true });
    }
  });

  it('reports a missing elements map', async () => {
    const tmpDir = await fs.mkdtemp(
      path.join(os.tmpdir(), 'canvas-page-template-'),
    );
    try {
      const pageTemplatePath = await writePageTemplate(
        tmpDir,
        'marketing.json',
        { label: 'Marketing' },
      );
      const discoveryResult = makeDiscoveryResult(tmpDir, [
        discovered('marketing', pageTemplatePath),
      ]);

      const { results } = await validatePageTemplates(discoveryResult);
      expect(results[0].success).toBe(false);
      expect(
        results[0].details?.some((d) => d.content.includes('elements')),
      ).toBe(true);
    } finally {
      await fs.rm(tmpDir, { recursive: true, force: true });
    }
  });

  it('rejects unexpected top-level keys', async () => {
    const tmpDir = await fs.mkdtemp(
      path.join(os.tmpdir(), 'canvas-page-template-'),
    );
    try {
      const pageTemplatePath = await writePageTemplate(
        tmpDir,
        'marketing.json',
        {
          label: 'Marketing',
          elements: { content: { type: 'marker.page_content' } },
          title: 'Not allowed on page templates',
        },
      );
      const discoveryResult = makeDiscoveryResult(tmpDir, [
        discovered('marketing', pageTemplatePath),
      ]);

      const { results } = await validatePageTemplates(discoveryResult);
      expect(results[0].success).toBe(false);
      expect(
        results[0].details?.some((d) => d.content.includes("'title'")),
      ).toBe(true);
    } finally {
      await fs.rm(tmpDir, { recursive: true, force: true });
    }
  });

  it('rejects multiple files claiming the site default', async () => {
    const tmpDir = await fs.mkdtemp(
      path.join(os.tmpdir(), 'canvas-page-template-'),
    );
    try {
      const marketingPath = await writePageTemplate(tmpDir, 'marketing.json', {
        label: 'Marketing',
        default: true,
        elements: { content: { type: 'marker.page_content' } },
      });
      const docsPath = await writePageTemplate(tmpDir, 'docs.json', {
        label: 'Docs',
        default: true,
        elements: { content: { type: 'marker.page_content' } },
      });
      const discoveryResult = makeDiscoveryResult(tmpDir, [
        discovered('marketing', marketingPath),
        discovered('docs', docsPath),
      ]);

      const { results } = await validatePageTemplates(discoveryResult);
      expect(results.every((r) => !r.success)).toBe(true);
      expect(
        results[0].details?.some((d) =>
          d.content.includes('Only one page template may set "default": true'),
        ),
      ).toBe(true);
    } finally {
      await fs.rm(tmpDir, { recursive: true, force: true });
    }
  });

  it('rejects a disabled page template claiming the site default', async () => {
    const tmpDir = await fs.mkdtemp(
      path.join(os.tmpdir(), 'canvas-page-template-'),
    );
    try {
      const pageTemplatePath = await writePageTemplate(
        tmpDir,
        'marketing.json',
        {
          label: 'Marketing',
          default: true,
          status: false,
          elements: { content: { type: 'marker.page_content' } },
        },
      );
      const discoveryResult = makeDiscoveryResult(tmpDir, [
        discovered('marketing', pageTemplatePath),
      ]);

      const { results } = await validatePageTemplates(discoveryResult);
      expect(results).toEqual([
        {
          itemName: 'marketing',
          success: false,
          details: [
            {
              heading: 'status',
              content:
                'A page template with "default": true must not set "status": false.',
            },
          ],
        },
      ]);
    } finally {
      await fs.rm(tmpDir, { recursive: true, force: true });
    }
  });

  it('rejects canvas:component-tree as an element key', async () => {
    const tmpDir = await fs.mkdtemp(
      path.join(os.tmpdir(), 'canvas-page-template-'),
    );
    try {
      const pageTemplatePath = await writePageTemplate(
        tmpDir,
        'marketing.json',
        {
          label: 'Marketing',
          elements: {
            'canvas:component-tree': { type: 'canvas:component-tree' },
          },
        },
      );
      const discoveryResult = makeDiscoveryResult(tmpDir, [
        discovered('marketing', pageTemplatePath),
      ]);

      const { results } = await validatePageTemplates(discoveryResult);
      expect(results[0].success).toBe(false);
    } finally {
      await fs.rm(tmpDir, { recursive: true, force: true });
    }
  });

  it('rejects elements referencing unreconciled external media URLs', async () => {
    const tmpDir = await fs.mkdtemp(
      path.join(os.tmpdir(), 'canvas-page-template-'),
    );
    try {
      const imageMetadata: ComponentMetadata = {
        name: 'Image',
        machineName: 'image',
        status: true,
        props: {
          properties: {
            image: {
              title: 'Image',
              type: 'object',
              $ref: 'json-schema-definitions://canvas.module/image',
            },
          },
        },
        required: [],
        slots: {},
      };
      const components = await writeComponentMetadataFiles(tmpDir, [
        imageMetadata,
      ]);
      const pageTemplatePath = await writePageTemplate(
        tmpDir,
        'marketing.json',
        {
          label: 'Marketing',
          elements: {
            banner: {
              type: 'js.image',
              props: { image: { src: 'https://example.com/photo.jpg' } },
            },
          },
        },
      );
      const discoveryResult = makeDiscoveryResult(
        tmpDir,
        [discovered('marketing', pageTemplatePath)],
        components,
      );

      const { results } = await validatePageTemplates(discoveryResult);
      expect(results[0].success).toBe(false);
      expect(
        results[0].details?.some((d) =>
          d.content.includes('Unreconciled external media URL'),
        ),
      ).toBe(true);
    } finally {
      await fs.rm(tmpDir, { recursive: true, force: true });
    }
  });

  it('reports invalid JSON with the file name as heading', async () => {
    const tmpDir = await fs.mkdtemp(
      path.join(os.tmpdir(), 'canvas-page-template-'),
    );
    try {
      const pageTemplatePath = path.join(tmpDir, 'marketing.json');
      await fs.writeFile(pageTemplatePath, '{ not valid json', 'utf-8');
      const discoveryResult = makeDiscoveryResult(tmpDir, [
        discovered('marketing', pageTemplatePath),
      ]);

      const { results } = await validatePageTemplates(discoveryResult);
      expect(results[0].success).toBe(false);
      expect(results[0].details?.[0].heading).toBe('marketing.json');
    } finally {
      await fs.rm(tmpDir, { recursive: true, force: true });
    }
  });
});
