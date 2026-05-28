import fs from 'fs/promises';
import os from 'os';
import path from 'path';
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
      regions: [],
      warnings: [],
      stats: { scannedFiles: 1, ignoredFiles: 0 },
    };

    try {
      await expect(validatePages(discoveryResult)).resolves.toEqual({
        results: [{ itemName: 'home', success: true }],
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
      regions: [],
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
            itemName: 'home',
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
      regions: [],
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
        results: [{ itemName: 'new-page', success: true }],
      });
    } finally {
      await fs.rm(tmpDir, { recursive: true, force: true });
    }
  });
});
