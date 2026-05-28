import fs from 'fs/promises';
import os from 'os';
import path from 'path';
import yaml from 'js-yaml';
import { describe, expect, it } from 'vitest';

import { validateRegions } from './validate-region';

import type {
  ComponentMetadata,
  DiscoveredRegion,
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
  regions: DiscoveredRegion[],
  components: DiscoveryResult['components'] = [],
): DiscoveryResult {
  return {
    componentRoot: tmpDir,
    projectRoot: tmpDir,
    components,
    pages: [],
    contentTemplates: [],
    regions,
    warnings: [],
    stats: { scannedFiles: regions.length, ignoredFiles: 0 },
  };
}

async function writeRegion(tmpDir: string, fileName: string, spec: unknown) {
  const regionPath = path.join(tmpDir, fileName);
  await fs.writeFile(regionPath, JSON.stringify(spec), 'utf-8');
  return regionPath;
}

describe('validateRegions', () => {
  it('accepts region specs with no elements', async () => {
    const tmpDir = await fs.mkdtemp(path.join(os.tmpdir(), 'canvas-region-'));
    try {
      const regionPath = await writeRegion(tmpDir, 'header.json', {
        elements: {},
      });
      const discoveryResult = makeDiscoveryResult(tmpDir, [
        {
          region: 'header',
          path: regionPath,
          relativePath: 'regions/header.json',
        },
      ]);

      await expect(validateRegions(discoveryResult)).resolves.toEqual({
        results: [{ itemName: 'header', success: true }],
      });
    } finally {
      await fs.rm(tmpDir, { recursive: true, force: true });
    }
  });

  it('reports missing required fields', async () => {
    const tmpDir = await fs.mkdtemp(path.join(os.tmpdir(), 'canvas-region-'));
    try {
      const regionPath = await writeRegion(tmpDir, 'header.json', {});
      const discoveryResult = makeDiscoveryResult(tmpDir, [
        {
          region: 'header',
          path: regionPath,
          relativePath: 'regions/header.json',
        },
      ]);

      const { results } = await validateRegions(discoveryResult);
      expect(results).toHaveLength(1);
      expect(results[0].success).toBe(false);
      expect(
        results[0].details?.some((d) => d.content.includes('elements')),
      ).toBe(true);
    } finally {
      await fs.rm(tmpDir, { recursive: true, force: true });
    }
  });

  it('rejects unexpected top-level keys', async () => {
    const tmpDir = await fs.mkdtemp(path.join(os.tmpdir(), 'canvas-region-'));
    try {
      const regionPath = await writeRegion(tmpDir, 'header.json', {
        elements: {},
        title: 'Not allowed on regions',
      });
      const discoveryResult = makeDiscoveryResult(tmpDir, [
        {
          region: 'header',
          path: regionPath,
          relativePath: 'regions/header.json',
        },
      ]);

      const { results } = await validateRegions(discoveryResult);
      expect(results[0].success).toBe(false);
      expect(
        results[0].details?.some((d) => d.content.includes("'title'")),
      ).toBe(true);
    } finally {
      await fs.rm(tmpDir, { recursive: true, force: true });
    }
  });

  it('rejects canvas:component-tree as an element key', async () => {
    const tmpDir = await fs.mkdtemp(path.join(os.tmpdir(), 'canvas-region-'));
    try {
      const regionPath = await writeRegion(tmpDir, 'header.json', {
        elements: {
          'canvas:component-tree': { type: 'canvas:component-tree' },
        },
      });
      const discoveryResult = makeDiscoveryResult(tmpDir, [
        {
          region: 'header',
          path: regionPath,
          relativePath: 'regions/header.json',
        },
      ]);

      const { results } = await validateRegions(discoveryResult);
      expect(results[0].success).toBe(false);
    } finally {
      await fs.rm(tmpDir, { recursive: true, force: true });
    }
  });

  it('rejects elements referencing unreconciled external media URLs', async () => {
    const tmpDir = await fs.mkdtemp(path.join(os.tmpdir(), 'canvas-region-'));
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
      const regionPath = await writeRegion(tmpDir, 'header.json', {
        elements: {
          banner: {
            type: 'js.image',
            props: { image: { src: 'https://example.com/photo.jpg' } },
          },
        },
      });
      const discoveryResult = makeDiscoveryResult(
        tmpDir,
        [
          {
            region: 'header',
            path: regionPath,
            relativePath: 'regions/header.json',
          },
        ],
        components,
      );

      const { results } = await validateRegions(discoveryResult);
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
    const tmpDir = await fs.mkdtemp(path.join(os.tmpdir(), 'canvas-region-'));
    try {
      const regionPath = path.join(tmpDir, 'header.json');
      await fs.writeFile(regionPath, '{ not valid json', 'utf-8');
      const discoveryResult = makeDiscoveryResult(tmpDir, [
        {
          region: 'header',
          path: regionPath,
          relativePath: 'regions/header.json',
        },
      ]);

      const { results } = await validateRegions(discoveryResult);
      expect(results[0].success).toBe(false);
      expect(results[0].details?.[0].heading).toBe('header.json');
    } finally {
      await fs.rm(tmpDir, { recursive: true, force: true });
    }
  });
});
