import fs from 'fs/promises';
import os from 'os';
import path from 'path';
import { describe, expect, it } from 'vitest';

import {
  buildElementsValidationContext,
  validateElements,
  validatePages,
} from './validate-page';

import type {
  ComponentMetadata,
  DiscoveryResult,
} from '@drupal-canvas/discovery';
import type { AuthoredSpecElementMap } from 'drupal-canvas/json-render-utils';

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
      pages: [
        {
          name: 'home',
          slug: 'home',
          uuid: null,
          path: pagePath,
          relativePath: 'pages/home.json',
        },
      ],
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
});
