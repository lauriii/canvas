import fs from 'fs/promises';
import os from 'os';
import path from 'path';
import yaml from 'js-yaml';
import { describe, expect, it } from 'vitest';

import {
  buildElementsValidationContext,
  validateElements,
} from './validate-elements';
import { validatePages } from './validate-page';

import type {
  ComponentMetadata,
  DiscoveryResult,
} from '@drupal-canvas/discovery';
import type { AuthoredSpecElementMap } from 'drupal-canvas/json-render-utils';
import type { PageListItem } from '../types/Page';

async function writeComponentMetadataFiles(
  rootDir: string,
  components: ComponentMetadata[],
): Promise<DiscoveryResult['components']> {
  return Promise.all(
    components.map(async (metadata) => {
      const componentDir = path.join(rootDir, metadata.machineName);
      await fs.mkdir(componentDir, { recursive: true });
      const metadataPath = path.join(componentDir, 'component.yml');
      await fs.writeFile(metadataPath, yaml.dump(metadata), 'utf-8');
      return {
        id: metadata.machineName,
        kind: 'named' as const,
        name: metadata.name,
        machineName: metadata.machineName,
        status: metadata.status,
        directory: componentDir,
        relativeDirectory: metadata.machineName,
        projectRelativeDirectory: metadata.machineName,
        metadataPath,
        jsEntryPath: null,
        cssEntryPath: null,
      };
    }),
  );
}

describe('validateElements', () => {
  it('accepts omitted props for components without required props', () => {
    const metadata: ComponentMetadata[] = [
      {
        name: 'Spacer',
        machineName: 'spacer',
        status: true,
        props: { properties: {} },
        required: [],
        slots: {},
      },
    ];
    const elements: AuthoredSpecElementMap = {
      spacer: {
        type: 'js.spacer',
      },
    };

    expect(
      validateElements(elements, buildElementsValidationContext(metadata)),
    ).toEqual({ success: true });
  });

  it('rejects omitted props when required props are missing', () => {
    const metadata: ComponentMetadata[] = [
      {
        name: 'Heading',
        machineName: 'heading',
        status: true,
        props: {
          properties: {
            text: { title: 'Text', type: 'string' },
          },
        },
        required: ['text'],
        slots: {},
      },
    ];
    const elements: AuthoredSpecElementMap = {
      heading: {
        type: 'js.heading',
      },
    };

    const result = validateElements(
      elements,
      buildElementsValidationContext(metadata),
    );

    expect(result.success).toBe(false);
    expect(result.details?.[0].heading).toContain('text');
    expect(result.details?.[0].content).toContain('undefined');
  });

  it('reports an actionable error for prop shapes the drupal-canvas package does not bundle', () => {
    const metadata: ComponentMetadata[] = [
      {
        name: 'Attachment',
        machineName: 'attachment',
        status: true,
        props: {
          properties: {
            file: {
              title: 'File',
              type: 'object',
              $ref: 'json-schema-definitions://canvas.module/unbundled-shape',
            },
          },
        },
        required: [],
        slots: {},
      },
    ];

    expect(() => buildElementsValidationContext(metadata)).toThrow(
      'The installed drupal-canvas package does not support "unbundled-shape" props. ' +
        '"unbundled-shape" props require a newer drupal-canvas version. ' +
        'Run `npm install drupal-canvas@latest` in the project, then retry.',
    );
  });
});

describe('validatePages', () => {
  function mockPageListItem(
    id: number,
    uuid: string,
    title: string,
    pagePath: string,
  ): PageListItem {
    return {
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
    };
  }

  it('accepts page specs with no elements', async () => {
    const tmpDir = await fs.mkdtemp(path.join(os.tmpdir(), 'canvas-page-'));
    const pagePath = path.join(tmpDir, 'home.json');
    await fs.writeFile(
      pagePath,
      JSON.stringify({ title: 'Home', path: '/home', elements: {} }),
      'utf-8',
    );

    const discoveryResult: DiscoveryResult = {
      componentRoot: tmpDir,
      projectRoot: tmpDir,
      components: [],
      contentTemplates: [],
      pages: [
        {
          name: 'home',
          slug: 'home',
          uuid: null,
          path: pagePath,
          relativePath: 'pages/home.json',
        },
      ],
      pageTemplates: [],
      warnings: [],
      stats: { scannedFiles: 1, ignoredFiles: 0 },
    };

    try {
      await expect(validatePages(discoveryResult)).resolves.toEqual({
        results: [{ itemName: 'Home', success: true }],
      });
    } finally {
      await fs.rm(tmpDir, { recursive: true, force: true });
    }
  });

  it('rejects an unknown page template selection', async () => {
    const tmpDir = await fs.mkdtemp(path.join(os.tmpdir(), 'canvas-page-'));
    const pagePath = path.join(tmpDir, 'home.json');
    await fs.writeFile(
      pagePath,
      JSON.stringify({
        title: 'Home',
        path: '/home',
        pageVariant: 'missing',
        elements: {},
      }),
      'utf-8',
    );

    const discoveryResult: DiscoveryResult = {
      componentRoot: tmpDir,
      projectRoot: tmpDir,
      components: [],
      contentTemplates: [],
      pages: [
        {
          name: 'home',
          slug: 'home',
          uuid: null,
          path: pagePath,
          relativePath: 'pages/home.json',
        },
      ],
      pageTemplates: [],
      warnings: [],
      stats: { scannedFiles: 1, ignoredFiles: 0 },
    };

    try {
      await expect(
        validatePages(discoveryResult, {
          availablePageVariantIds: new Set(['default']),
        }),
      ).resolves.toEqual({
        results: [
          {
            itemName: 'Home (pages/home.json)',
            success: false,
            details: [
              {
                heading: 'pageVariant',
                content:
                  'Unknown page template "missing". Pull it from the site or add its file under page-templates.',
              },
            ],
          },
        ],
      });
    } finally {
      await fs.rm(tmpDir, { recursive: true, force: true });
    }
  });

  it('rejects path alias changes for existing remote pages', async () => {
    const tmpDir = await fs.mkdtemp(path.join(os.tmpdir(), 'canvas-page-'));
    const pagePath = path.join(tmpDir, 'home.json');
    const uuid = '27a539f5-2dd0-471a-a364-8fee7a024a73';
    await fs.writeFile(
      pagePath,
      JSON.stringify({
        uuid,
        title: 'Home',
        path: '/new-home',
        elements: {},
      }),
      'utf-8',
    );

    const discoveryResult: DiscoveryResult = {
      componentRoot: tmpDir,
      projectRoot: tmpDir,
      components: [],
      contentTemplates: [],
      pages: [
        {
          name: 'home',
          slug: 'home',
          uuid,
          path: pagePath,
          relativePath: 'pages/home.json',
        },
      ],
      pageTemplates: [],
      warnings: [],
      stats: { scannedFiles: 1, ignoredFiles: 0 },
    };

    try {
      await expect(
        validatePages(discoveryResult, {
          remotePageByUuid: new Map([
            [uuid, mockPageListItem(1, uuid, 'Home', '/home')],
          ]),
        }),
      ).resolves.toEqual({
        results: [
          {
            itemName: 'Home (pages/home.json)',
            success: false,
            details: [
              {
                heading: 'path',
                content:
                  'Path alias changes are not allowed for existing pages. Remote path is "/home"; local path is "/new-home".',
              },
            ],
          },
        ],
      });
    } finally {
      await fs.rm(tmpDir, { recursive: true, force: true });
    }
  });

  it('allows path aliases for new local pages', async () => {
    const tmpDir = await fs.mkdtemp(path.join(os.tmpdir(), 'canvas-page-'));
    const pagePath = path.join(tmpDir, 'new-page.json');
    await fs.writeFile(
      pagePath,
      JSON.stringify({
        title: 'New page',
        path: '/new-page',
        elements: {},
      }),
      'utf-8',
    );

    const discoveryResult: DiscoveryResult = {
      componentRoot: tmpDir,
      projectRoot: tmpDir,
      components: [],
      contentTemplates: [],
      pages: [
        {
          name: 'new-page',
          slug: 'new-page',
          uuid: null,
          path: pagePath,
          relativePath: 'pages/new-page.json',
        },
      ],
      pageTemplates: [],
      warnings: [],
      stats: { scannedFiles: 1, ignoredFiles: 0 },
    };

    try {
      await expect(
        validatePages(discoveryResult, {
          remotePageByUuid: new Map([
            [
              '27a539f5-2dd0-471a-a364-8fee7a024a73',
              mockPageListItem(
                1,
                '27a539f5-2dd0-471a-a364-8fee7a024a73',
                'Home',
                '/home',
              ),
            ],
          ]),
        }),
      ).resolves.toEqual({
        results: [{ itemName: 'New page', success: true }],
      });
    } finally {
      await fs.rm(tmpDir, { recursive: true, force: true });
    }
  });

  it('rejects elements referencing unreconciled external media URLs', async () => {
    const tmpDir = await fs.mkdtemp(path.join(os.tmpdir(), 'canvas-page-'));
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
      const pagePath = path.join(tmpDir, 'home.json');
      await fs.writeFile(
        pagePath,
        JSON.stringify({
          title: 'Home',
          path: '/home',
          elements: {
            banner: {
              type: 'js.image',
              props: { image: { src: 'https://example.com/photo.jpg' } },
            },
          },
        }),
        'utf-8',
      );

      const discoveryResult: DiscoveryResult = {
        componentRoot: tmpDir,
        projectRoot: tmpDir,
        components,
        contentTemplates: [],
        pages: [
          {
            name: 'home',
            slug: 'home',
            uuid: null,
            path: pagePath,
            relativePath: 'pages/home.json',
          },
        ],
        pageTemplates: [],
        warnings: [],
        stats: { scannedFiles: 2, ignoredFiles: 0 },
      };

      const { results } = await validatePages(discoveryResult);
      expect(results).toEqual([
        {
          itemName: 'Home (pages/home.json)',
          success: false,
          details: [
            {
              heading: 'elements.banner.props.image',
              content:
                'Unreconciled external media URL "https://example.com/photo.jpg". Run `canvas reconcile-media` to resolve.',
            },
          ],
        },
      ]);
    } finally {
      await fs.rm(tmpDir, { recursive: true, force: true });
    }
  });
});
