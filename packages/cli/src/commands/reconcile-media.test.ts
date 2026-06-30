import { promises as fs } from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { afterEach, describe, expect, it, vi } from 'vitest';

import {
  collectReconcileSpecFiles,
  downloadDataUrlMedia,
  hasReconcileMediaSyncEnabled,
  reconcileElementMapMedia,
} from './reconcile-media';

import type { ComponentMetadata } from '@drupal-canvas/discovery';
import type { AuthoredSpecElementMap } from 'drupal-canvas/json-render-utils';

const metadata: ComponentMetadata[] = [
  {
    name: 'hero',
    machineName: 'hero',
    status: true,
    required: [],
    slots: {},
    props: {
      properties: {
        image: {
          title: 'Image',
          type: 'object',
          $ref: 'json-schema-definitions://canvas.module/image',
        },
      },
    },
  },
];

async function makeTempDir(): Promise<string> {
  return fs.mkdtemp(path.join(os.tmpdir(), 'reconcile-media-test-'));
}

async function writeFile(filePath: string, content: string): Promise<void> {
  await fs.mkdir(path.dirname(filePath), { recursive: true });
  await fs.writeFile(filePath, content, 'utf-8');
}

const tempDirs: string[] = [];

afterEach(async () => {
  await Promise.all(
    tempDirs.map((dir) => fs.rm(dir, { recursive: true, force: true })),
  );
  tempDirs.length = 0;
});

describe('collectReconcileSpecFiles', () => {
  it('does not read specs when pages, content templates, and regions are disabled', async () => {
    const root = await makeTempDir();
    tempDirs.push(root);
    const pagePath = path.join(root, 'pages/home.json');
    const templatePath = path.join(root, 'content-templates/article.json');
    const regionPath = path.join(root, 'regions/header.json');

    await writeFile(pagePath, '{');
    await writeFile(templatePath, '{');
    await writeFile(regionPath, '{');

    const specFiles = await collectReconcileSpecFiles(
      {
        pages: [
          {
            name: 'home',
            slug: 'home',
            uuid: null,
            path: pagePath,
            relativePath: 'pages/home.json',
          },
        ],
        contentTemplates: [
          {
            name: 'article',
            slug: 'article',
            label: 'Article',
            entityTypeId: null,
            bundle: null,
            viewMode: null,
            path: templatePath,
            relativePath: 'content-templates/article.json',
          },
        ],
        regions: [
          {
            region: 'header',
            path: regionPath,
            relativePath: 'regions/header.json',
          },
        ],
      },
      {
        includePages: false,
        includeContentTemplates: false,
        includeRegions: false,
      },
    );

    expect(specFiles).toEqual([]);
  });

  it('collects enabled pages and content templates while skipping disabled regions', async () => {
    const root = await makeTempDir();
    tempDirs.push(root);
    const pagePath = path.join(root, 'pages/home.json');
    const templatePath = path.join(root, 'content-templates/article.json');
    const regionPath = path.join(root, 'regions/header.json');

    await writeFile(pagePath, JSON.stringify({ title: 'Home', elements: {} }));
    await writeFile(
      templatePath,
      JSON.stringify({ label: 'Article', elements: {} }),
    );
    await writeFile(regionPath, JSON.stringify({ elements: {} }));

    const specFiles = await collectReconcileSpecFiles(
      {
        pages: [
          {
            name: 'home',
            slug: 'home',
            uuid: null,
            path: pagePath,
            relativePath: 'pages/home.json',
          },
        ],
        contentTemplates: [
          {
            name: 'article',
            slug: 'article',
            label: 'Article',
            entityTypeId: null,
            bundle: null,
            viewMode: null,
            path: templatePath,
            relativePath: 'content-templates/article.json',
          },
        ],
        regions: [
          {
            region: 'header',
            path: regionPath,
            relativePath: 'regions/header.json',
          },
        ],
      },
      {
        includePages: true,
        includeContentTemplates: true,
        includeRegions: false,
      },
    );

    expect(specFiles.map((file) => file.label)).toEqual([
      'page "home"',
      'content template "article"',
    ]);
    expect(specFiles.map((file) => file.relativePath)).toEqual([
      'pages/home.json',
      'content-templates/article.json',
    ]);
  });

  it('does not read disabled page or content template specs', async () => {
    const root = await makeTempDir();
    tempDirs.push(root);
    const pagePath = path.join(root, 'pages/home.json');
    const templatePath = path.join(root, 'content-templates/article.json');
    const regionPath = path.join(root, 'regions/header.json');

    await writeFile(pagePath, '{');
    await writeFile(templatePath, '{');
    await writeFile(regionPath, JSON.stringify({ elements: {} }));

    const specFiles = await collectReconcileSpecFiles(
      {
        pages: [
          {
            name: 'home',
            slug: 'home',
            uuid: null,
            path: pagePath,
            relativePath: 'pages/home.json',
          },
        ],
        contentTemplates: [
          {
            name: 'article',
            slug: 'article',
            label: 'Article',
            entityTypeId: null,
            bundle: null,
            viewMode: null,
            path: templatePath,
            relativePath: 'content-templates/article.json',
          },
        ],
        regions: [
          {
            region: 'header',
            path: regionPath,
            relativePath: 'regions/header.json',
          },
        ],
      },
      {
        includePages: false,
        includeContentTemplates: false,
        includeRegions: true,
      },
    );

    expect(specFiles.map((file) => file.label)).toEqual([
      'global region "header"',
    ]);
    expect(specFiles[0].spec).toEqual({ elements: {} });
  });
});

describe('hasReconcileMediaSyncEnabled', () => {
  it('returns false only when all reconciled resource types are disabled', () => {
    expect(
      hasReconcileMediaSyncEnabled({
        includePages: false,
        includeContentTemplates: false,
        includeRegions: false,
      }),
    ).toBe(false);
    expect(
      hasReconcileMediaSyncEnabled({
        includePages: true,
        includeContentTemplates: false,
        includeRegions: false,
      }),
    ).toBe(true);
    expect(
      hasReconcileMediaSyncEnabled({
        includePages: false,
        includeContentTemplates: true,
        includeRegions: false,
      }),
    ).toBe(true);
    expect(
      hasReconcileMediaSyncEnabled({
        includePages: false,
        includeContentTemplates: false,
        includeRegions: true,
      }),
    ).toBe(true);
  });
});

describe('reconcileElementMapMedia', () => {
  it('uploads external media, updates props, and writes provenance', async () => {
    const elements = {
      hero: {
        type: 'js.hero',
        props: {
          image: {
            src: 'https://example.com/hero.jpg',
            alt: 'Hero image',
            width: 1200,
            height: 800,
          },
        },
      },
    } as AuthoredSpecElementMap;
    const apiService = {
      uploadMedia: vi.fn().mockResolvedValue({
        id: 42,
        uuid: 'media-uuid',
        inputs_resolved: {
          src: '/sites/default/files/hero.jpg',
          alt: 'Hero image',
          width: 1200,
          height: 800,
        },
      }),
    };
    const downloadMedia = vi.fn().mockResolvedValue({
      buffer: Buffer.from('image-bytes'),
      filename: 'hero.jpg',
    });

    const result = await reconcileElementMapMedia(
      elements,
      metadata,
      apiService,
      downloadMedia,
    );

    expect(result).toEqual({
      reconciled: 1,
      successes: [
        {
          elementId: 'hero',
          propName: 'image',
          src: 'https://example.com/hero.jpg',
          mediaId: 42,
        },
      ],
      failures: [],
    });
    expect(downloadMedia).toHaveBeenCalledWith('https://example.com/hero.jpg');
    expect(apiService.uploadMedia).toHaveBeenCalledWith({
      mediaType: 'image',
      filename: 'hero.jpg',
      fileBuffer: Buffer.from('image-bytes'),
      data: {
        title: 'hero.jpg',
        alt: 'Hero image',
      },
    });
    expect(elements).toEqual({
      hero: {
        type: 'js.hero',
        props: {
          image: {
            src: '/sites/default/files/hero.jpg',
            alt: 'Hero image',
            width: 1200,
            height: 800,
          },
        },
        _provenance: {
          image: {
            target_id: 42,
            source_url: 'https://example.com/hero.jpg',
          },
        },
      },
    });
  });

  it('reports failures with source URL and error message', async () => {
    const elements = {
      hero: {
        type: 'js.hero',
        props: {
          image: {
            src: 'https://example.com/missing.jpg',
            alt: 'Missing',
          },
        },
      },
    } as AuthoredSpecElementMap;
    const apiService = { uploadMedia: vi.fn() };
    const downloadMedia = vi
      .fn()
      .mockRejectedValue(new Error('Request failed with status code 404'));

    const result = await reconcileElementMapMedia(
      elements,
      metadata,
      apiService,
      downloadMedia,
    );

    expect(result).toEqual({
      reconciled: 0,
      successes: [],
      failures: [
        {
          elementId: 'hero',
          propName: 'image',
          src: 'https://example.com/missing.jpg',
          error: 'Request failed with status code 404',
        },
      ],
    });
    // Element should remain unchanged on failure.
    expect(elements.hero.props).toEqual({
      image: { src: 'https://example.com/missing.jpg', alt: 'Missing' },
    });
    expect(elements.hero._provenance).toBeUndefined();
  });

  it('uploads duplicate URLs only once and applies to all elements', async () => {
    const sharedUrl = 'https://example.com/shared.jpg';
    const elements = {
      hero: {
        type: 'js.hero',
        props: {
          image: { src: sharedUrl, alt: 'Hero' },
        },
      },
      card: {
        type: 'js.hero',
        props: {
          image: { src: sharedUrl, alt: 'Card' },
        },
      },
    } as AuthoredSpecElementMap;
    const apiService = {
      uploadMedia: vi.fn().mockResolvedValue({
        id: 99,
        uuid: 'shared-uuid',
        inputs_resolved: {
          src: '/sites/default/files/shared.jpg',
          alt: 'Hero',
          width: 800,
          height: 600,
        },
      }),
    };
    const downloadMedia = vi.fn().mockResolvedValue({
      buffer: Buffer.from('image-bytes'),
      filename: 'shared.jpg',
    });

    const result = await reconcileElementMapMedia(
      elements,
      metadata,
      apiService,
      downloadMedia,
    );

    // Upload should be called only once despite two work items.
    expect(downloadMedia).toHaveBeenCalledTimes(1);
    expect(apiService.uploadMedia).toHaveBeenCalledTimes(1);

    expect(result.reconciled).toBe(2);
    expect(result.successes).toHaveLength(2);
    expect(result.failures).toHaveLength(0);

    // Both elements should reference the same media entity.
    expect(
      (elements.hero._provenance as Record<string, unknown>).image,
    ).toEqual({ target_id: 99, source_url: sharedUrl });
    expect(
      (elements.card._provenance as Record<string, unknown>).image,
    ).toEqual({ target_id: 99, source_url: sharedUrl });
  });

  it('reports failure for unsupported MIME types', async () => {
    const svgDataUrl = 'data:image/svg+xml;base64,PHN2Zz48L3N2Zz4=';
    const elements = {
      logo: {
        type: 'js.hero',
        props: {
          image: {
            src: svgDataUrl,
            alt: 'Logo',
          },
        },
      },
    } as AuthoredSpecElementMap;
    const apiService = { uploadMedia: vi.fn() };
    const downloadMedia = vi.fn().mockResolvedValue({
      buffer: Buffer.from('<svg></svg>'),
      filename: 'media.bin',
      mimeType: 'image/svg+xml',
    });

    const result = await reconcileElementMapMedia(
      elements,
      metadata,
      apiService,
      downloadMedia,
    );

    expect(result.reconciled).toBe(0);
    expect(result.failures).toHaveLength(1);
    expect(result.failures[0].error).toContain('Unsupported image type');
    expect(result.failures[0].error).toContain('image/svg+xml');
    expect(apiService.uploadMedia).not.toHaveBeenCalled();
  });

  it('allows supported MIME types through', async () => {
    const elements = {
      hero: {
        type: 'js.hero',
        props: {
          image: {
            src: 'https://example.com/photo.jpg',
            alt: 'Photo',
          },
        },
      },
    } as AuthoredSpecElementMap;
    const apiService = {
      uploadMedia: vi.fn().mockResolvedValue({
        id: 20,
        uuid: 'jpg-uuid',
        inputs_resolved: {
          src: '/sites/default/files/photo.jpg',
          alt: 'Photo',
          width: 800,
          height: 600,
        },
      }),
    };
    const downloadMedia = vi.fn().mockResolvedValue({
      buffer: Buffer.from('jpeg-bytes'),
      filename: 'photo.jpg',
      mimeType: 'image/jpeg',
    });

    const result = await reconcileElementMapMedia(
      elements,
      metadata,
      apiService,
      downloadMedia,
    );

    expect(result.reconciled).toBe(1);
    expect(apiService.uploadMedia).toHaveBeenCalled();
  });
});

describe('downloadDataUrlMedia', () => {
  it('parses a base64-encoded SVG data URL', () => {
    const svg = '<svg></svg>';
    const encoded = Buffer.from(svg).toString('base64');
    const result = downloadDataUrlMedia(`data:image/svg+xml;base64,${encoded}`);

    expect(result.buffer).toEqual(Buffer.from(svg));
    expect(result.filename).toBe('media.bin');
    expect(result.mimeType).toBe('image/svg+xml');
  });

  it('parses a base64-encoded PNG data URL', () => {
    const result = downloadDataUrlMedia('data:image/png;base64,abc123');

    expect(result.filename).toBe('media.png');
    expect(result.mimeType).toBe('image/png');
  });

  it('throws on invalid data URL', () => {
    expect(() => downloadDataUrlMedia('not-a-data-url')).toThrow(
      'Invalid data URL',
    );
  });
});
