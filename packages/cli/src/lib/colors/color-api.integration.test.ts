import { randomUUID } from 'node:crypto';
import fs from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import { afterAll, beforeAll, describe, expect, it } from 'vitest';
import { discoverCanvasProject } from '@drupal-canvas/discovery';
import { execDrush } from '@drupal-canvas/test-utils';

import {
  createBrandKitPullTask,
  createPagesPullTask,
} from '../../commands/pull';
import { setConfig } from '../../config';
import { createApiService } from '../../services/api';
import { preparePages, pushPages } from '../../utils/prepare-pages-push';
import { installTestSite, tearDownTestSite } from '../../utils/testing';
import {
  planColorPull,
  readBrandKitColorsFile,
  writeBrandKitColorsConfig,
} from './color-pull';
import { pushColors } from './color-push';

import type { BrandKitColorsFileMap } from '@drupal-canvas/discovery';
import type { BrandKitColorEntry } from '../../types/Component';

const isConfigured =
  process.env.DRUPAL_TEST_BASE_URL !== undefined &&
  process.env.DRUPAL_TEST_DB_URL !== undefined &&
  process.env.TEST_CANVAS_CLIENT_ID !== undefined &&
  process.env.TEST_CANVAS_CLIENT_SECRET !== undefined;

function slugFromPath(pagePath: string): string {
  const slug = pagePath.replace(/^\/+|\/+$/g, '').replace(/\//g, '-');
  return slug || 'index';
}

function colorMapFromRemote(
  colors: BrandKitColorEntry[],
): BrandKitColorsFileMap {
  const map = Object.create(null) as BrandKitColorsFileMap;
  for (const color of colors) {
    map[color.cssVariable.slice(2)] = {
      value: color.value,
      name: color.name,
      displayFormat: color.displayFormat ?? null,
    };
  }
  return map;
}

function getAuthoredColorString(value: unknown): string | undefined {
  if (typeof value === 'string') {
    return value;
  }
  if (
    value &&
    typeof value === 'object' &&
    'value' in value &&
    typeof (value as { value?: unknown }).value === 'string'
  ) {
    return (value as { value: string }).value;
  }
  return undefined;
}

async function writeColorComponentMetadata(
  componentRoot: string,
): Promise<void> {
  const componentDir = path.join(
    componentRoot,
    'canvas_test_code_components_color_three_colors',
  );
  await fs.mkdir(componentDir, { recursive: true });
  await fs.writeFile(
    path.join(componentDir, 'index.tsx'),
    'export default function Component() { return null; }\n',
    'utf-8',
  );
  await fs.writeFile(
    path.join(componentDir, 'component.yml'),
    [
      'name: Three Colors',
      'machineName: canvas_test_code_components_color_three_colors',
      'status: true',
      'required: []',
      'props:',
      '  properties:',
      '    brandPastel:',
      '      title: Brand Pastel',
      '      type: string',
      '      $ref: json-schema-definitions://canvas.module/color',
      '    free:',
      '      title: Free',
      '      type: string',
      '      $ref: json-schema-definitions://canvas.module/color',
      'slots: {}',
      'dataDependencies: {}',
    ].join('\n'),
    'utf-8',
  );
}

describe.runIf(isConfigured)('brand kit colors integration', () => {
  let dbPrefix: string;

  beforeAll(
    async () => {
      const installData = await installTestSite();
      dbPrefix = installData.db_prefix;

      setConfig({
        siteUrl: process.env.DRUPAL_TEST_BASE_URL || '',
        clientId: process.env.TEST_CANVAS_CLIENT_ID || '',
        clientSecret: process.env.TEST_CANVAS_CLIENT_SECRET || '',
        scope:
          'canvas:asset_library canvas:js_component canvas:page:create canvas:page:read canvas:page:edit canvas:brand_kit',
        userAgent: installData.user_agent,
      });

      const drushInstall = {
        userAgent: installData.user_agent,
        url: process.env.DRUPAL_TEST_BASE_URL || '',
      };
      await execDrush(
        'pm:enable canvas_test_code_components_color',
        drushInstall,
      );
    },
    60 * 60 * 1000,
  );

  afterAll(
    async () => {
      if (dbPrefix) {
        await tearDownTestSite(dbPrefix);
      }
    },
    60 * 60 * 1000,
  );

  it('returns seeded brand kit colors with expected fields', async () => {
    const api = await createApiService();
    const brandKit = await api.getBrandKit();
    const colors = brandKit.colors ?? [];

    expect(colors).toHaveLength(7);
    expect(colors).toEqual(
      expect.arrayContaining([
        expect.objectContaining({
          name: 'Brand Red',
          cssVariable: '--brand-red',
          value: expect.objectContaining({
            colorSpace: 'srgb',
            hex: '#cc0000',
          }),
        }),
        expect.objectContaining({
          name: 'Brand Green',
          cssVariable: '--brand-green',
          value: expect.objectContaining({ colorSpace: 'hsl' }),
        }),
        expect.objectContaining({
          name: 'Brand Blue',
          cssVariable: '--brand-blue',
          value: expect.objectContaining({ colorSpace: 'srgb', alpha: 0.9 }),
        }),
      ]),
    );
  });

  it('creates then deletes a color through the API', async () => {
    const api = await createApiService();
    const suffix = randomUUID().slice(0, 8);
    const cssVariable = `--integration-color-${suffix}`;
    const name = `Integration Color ${suffix}`;

    const created = await api.createColor({
      name,
      cssVariable,
      value: {
        colorSpace: 'srgb',
        components: [0.25, 0.5, 0.75],
        alpha: null,
        hex: '#4080bf',
      },
      displayFormat: 'hex',
    });

    let colors = (await api.getBrandKit()).colors ?? [];
    expect(colors).toEqual(
      expect.arrayContaining([
        expect.objectContaining({ id: created.id, name, cssVariable }),
      ]),
    );

    await api.deleteColor(created.id);

    colors = (await api.getBrandKit()).colors ?? [];
    expect(colors.some((color) => color.id === created.id)).toBe(false);
  });

  it('pulls colors to disk in authored string form', async () => {
    const api = await createApiService();
    const remoteColors = (await api.getBrandKit()).colors ?? [];
    const tmpDir = await fs.mkdtemp(
      path.join(os.tmpdir(), 'canvas-colors-pull-'),
    );

    try {
      const plan = planColorPull(remoteColors, {}, {});
      await writeBrandKitColorsConfig(tmpDir, plan.colors);
      const pulledColors = await readBrandKitColorsFile(tmpDir);

      expect(pulledColors).toBeDefined();
      const brandRed = pulledColors?.['brand-red'];
      const brandGreen = pulledColors?.['brand-green'];
      const brandBlue = pulledColors?.['brand-blue'];

      expect(brandRed).toBe('#cc0000');

      const greenValue = getAuthoredColorString(brandGreen);
      expect(greenValue).toBeDefined();
      expect(greenValue).toMatch(/^hsl\(/i);

      const blueValue = getAuthoredColorString(brandBlue);
      expect(blueValue).toBeDefined();
      expect(blueValue).toMatch(/^(rgba?\(|rgb\(|#[0-9a-f]{8}$)/i);
    } finally {
      await fs.rm(tmpDir, { recursive: true, force: true });
    }
  });

  it('pushes a new local color to the server', async () => {
    const api = await createApiService();
    const suffix = randomUUID().slice(0, 8);
    const key = `integration-new-${suffix}`;
    const cssVariable = `--${key}`;
    const tmpDir = await fs.mkdtemp(
      path.join(os.tmpdir(), 'canvas-colors-push-'),
    );

    try {
      await fs.writeFile(
        path.join(tmpDir, 'canvas.brand-kit.json'),
        `${JSON.stringify({ colors: { [key]: '#123abc' } }, null, 2)}\n`,
        'utf-8',
      );

      const localColors = await readBrandKitColorsFile(tmpDir);
      const result = await pushColors(localColors, api, {});
      expect(result?.created).toBe(1);

      const remote = (await api.getBrandKit()).colors ?? [];
      const created = remote.find((color) => color.cssVariable === cssVariable);
      expect(created).toBeDefined();
      expect(created?.name).toBe(`Integration New ${suffix}`);
      expect(created?.displayFormat).toBe('hex');
      expect(created?.value).toEqual(
        expect.objectContaining({
          colorSpace: 'srgb',
          hex: '#123abc',
        }),
      );

      if (created) {
        await api.deleteColor(created.id);
      }
    } finally {
      await fs.rm(tmpDir, { recursive: true, force: true });
    }
  });

  it('updates an existing seeded color and restores it', async () => {
    const api = await createApiService();
    const before = (await api.getBrandKit()).colors ?? [];
    const target = before.find((color) => color.cssVariable === '--brand-red');
    expect(target).toBeDefined();
    if (!target) return;

    try {
      await pushColors({ 'brand-red': '#cd0000' }, api, {});

      const after = (await api.getBrandKit()).colors ?? [];
      const updated = after.find((color) => color.id === target.id);
      expect(updated?.value).toEqual(
        expect.objectContaining({
          colorSpace: 'srgb',
          hex: '#cd0000',
        }),
      );
    } finally {
      await api.updateColor(target.id, {
        value: target.value,
        displayFormat: target.displayFormat ?? null,
      });
    }
  });

  it('round-trips pull to disk, edit, push, and verifies the server update', async () => {
    const api = await createApiService();
    const remoteColors = (await api.getBrandKit()).colors ?? [];
    const target = remoteColors.find(
      (color) => color.cssVariable === '--brand-green',
    );
    expect(target).toBeDefined();
    if (!target) return;

    const tmpDir = await fs.mkdtemp(
      path.join(os.tmpdir(), 'canvas-colors-loop-'),
    );
    try {
      const pullPlan = planColorPull(remoteColors, {}, {});
      await writeBrandKitColorsConfig(tmpDir, pullPlan.colors);

      const configPath = path.join(tmpDir, 'canvas.brand-kit.json');
      const parsed = JSON.parse(await fs.readFile(configPath, 'utf-8')) as {
        colors: Record<string, unknown>;
      };
      parsed.colors['brand-green'] = 'hsl(142 100% 29%)';
      await fs.writeFile(
        configPath,
        `${JSON.stringify(parsed, null, 2)}\n`,
        'utf-8',
      );

      const editedColors = await readBrandKitColorsFile(tmpDir);
      await pushColors(editedColors, api, {});

      const after = (await api.getBrandKit()).colors ?? [];
      const updated = after.find((color) => color.id === target.id);
      expect(updated).toBeDefined();
      expect(updated?.value).toEqual(
        expect.objectContaining({
          colorSpace: 'hsl',
          components: [142, 100, 29],
        }),
      );
    } finally {
      await api.updateColor(target.id, {
        value: target.value,
        displayFormat: target.displayFormat ?? null,
      });
      await fs.rm(tmpDir, { recursive: true, force: true });
    }
  });

  it('round-trips page color refs between UUID and cssVarKey', async () => {
    const api = await createApiService();
    const brandKit = await api.getBrandKit();
    const colors = brandKit.colors ?? [];
    const pastel = colors.find(
      (color) => color.cssVariable === '--baguette-legs',
    );
    const replacement = colors.find(
      (color) => color.cssVariable === '--brand-red',
    );
    expect(pastel).toBeDefined();
    expect(replacement).toBeDefined();
    if (!pastel || !replacement) return;

    const componentVersions = await api.listComponentVersions();
    const componentVersion =
      componentVersions.get(
        'js.canvas_test_code_components_color_three_colors',
      ) ?? '';
    const pagePath = `/int-color-${randomUUID().slice(0, 8)}`;
    const nodeUuid = randomUUID();

    const createdPage = await api.createPage({
      title: `Color Round Trip ${randomUUID().slice(0, 8)}`,
      description: '',
      pageVariant: null,
      status: false,
      path: pagePath,
      components: [
        {
          uuid: nodeUuid,
          component_id: 'js.canvas_test_code_components_color_three_colors',
          component_version: componentVersion,
          parent_uuid: null,
          slot: null,
          label: null,
          inputs: {
            brandPastel: `canvas-color:${pastel.id}`,
          },
        },
      ],
    });

    const tmpDir = await fs.mkdtemp(
      path.join(os.tmpdir(), 'canvas-page-colors-'),
    );
    const pagesDir = path.join(tmpDir, 'pages');
    const componentRoot = path.join(tmpDir, 'components');
    await fs.mkdir(pagesDir, { recursive: true });
    await writeColorComponentMetadata(componentRoot);

    try {
      const pullTask = createPagesPullTask(api, pagesDir, false, componentRoot);
      await pullTask.prepare();
      await pullTask.execute();

      const pulledPath = path.join(pagesDir, `${slugFromPath(pagePath)}.json`);
      const pulled = JSON.parse(await fs.readFile(pulledPath, 'utf-8')) as {
        elements: Record<string, { props?: Record<string, unknown> }>;
      };

      expect(pulled.elements[nodeUuid]?.props?.brandPastel).toBe(
        'canvas-color:baguette-legs',
      );

      pulled.elements[nodeUuid]!.props!.brandPastel = 'canvas-color:brand-red';
      await fs.writeFile(
        pulledPath,
        `${JSON.stringify(pulled, null, 2)}\n`,
        'utf-8',
      );

      const discovery = await discoverCanvasProject({
        pagesRoot: pagesDir,
        componentRoot,
      });
      const discoveredPage = discovery.pages.find(
        (page) => page.path === pulledPath,
      );
      expect(discoveredPage).toBeDefined();
      if (!discoveredPage) return;

      const prepared = await preparePages(
        [discoveredPage],
        componentVersions,
        discovery,
        colors,
      );
      expect(prepared.failed).toHaveLength(0);

      const remotePages = await api.listPages();
      const remoteByUuid = new Map(
        Object.values(remotePages).map((page) => [page.uuid, page]),
      );
      const pushResults = await pushPages(
        prepared.valid,
        remoteByUuid,
        api,
        true,
      );
      expect(pushResults).toEqual([
        expect.objectContaining({
          success: true,
          pageTitle: createdPage.title,
        }),
      ]);

      const updatedPage = await api.getPage(createdPage.id);
      const updatedNode = updatedPage.components.find(
        (node) => node.uuid === nodeUuid,
      );
      expect(updatedNode?.inputs).toEqual(
        expect.objectContaining({
          brandPastel: `canvas-color:${replacement.id}`,
        }),
      );
    } finally {
      await fs.rm(tmpDir, { recursive: true, force: true });
    }
  });

  it('covers the end-to-end pull task path for brand kit colors', async () => {
    const api = await createApiService();
    const tmpDir = await fs.mkdtemp(
      path.join(os.tmpdir(), 'canvas-brand-kit-task-'),
    );

    try {
      const task = createBrandKitPullTask(
        api,
        tmpDir,
        false,
        { colors: [] },
        { folders: [] },
      );
      const prepared = await task.prepare();
      expect(prepared.summaryLines.length).toBeGreaterThan(0);

      await task.execute();

      const pulledColors = await readBrandKitColorsFile(tmpDir);
      expect(pulledColors?.['brand-red']).toBe('#cc0000');
      expect(typeof pulledColors?.['brand-blue']).toMatch(/string|object/);
    } finally {
      await fs.rm(tmpDir, { recursive: true, force: true });
    }
  });

  it('pushes an edited full pulled map and then restores it', async () => {
    const api = await createApiService();
    const before = (await api.getBrandKit()).colors ?? [];
    const edited = colorMapFromRemote(before);
    edited['brand-red'] = '#d10000';

    try {
      await pushColors(edited, api, {});
      const after = (await api.getBrandKit()).colors ?? [];
      const updated = after.find(
        (color) => color.cssVariable === '--brand-red',
      );
      expect(updated?.value).toEqual(
        expect.objectContaining({ hex: '#d10000' }),
      );
    } finally {
      await pushColors(colorMapFromRemote(before), api, {});
    }
  });
});
