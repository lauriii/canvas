import crypto from 'crypto';
import fs from 'fs/promises';
import os from 'os';
import path from 'path';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { pullIcons } from './icon-pull';
import { pushIcons } from './icon-push';

import type { ApiService } from '../../services/api';
import type { IconLibrary, IconPack } from '../../types/IconLibrary';

const STAR_SVG =
  '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M12 2l3 7h7l-5.5 4.5L18 21l-6-4-6 4 1.5-7.5L2 9h7z"/></svg>';
const HEART_SVG =
  '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M12 21l-8-8a5 5 0 017-7l1 1 1-1a5 5 0 017 7z"/></svg>';

const SVG_BY_URL: Record<string, string> = {
  '/sites/default/files/canvas/icons/star.svg': STAR_SVG,
  '/sites/default/files/canvas/icons/heart.svg': HEART_SVG,
};

const sha256 = (content: string): string =>
  crypto.createHash('sha256').update(content).digest('hex');

function managedLibrary(overrides: Partial<IconLibrary> = {}): IconLibrary {
  return {
    id: 'my_icons',
    label: 'My icons',
    description: 'A managed set',
    template: null,
    assets: [
      {
        name: 'star.svg',
        uri: 'public://canvas/icons/star.svg',
        url: '/sites/default/files/canvas/icons/star.svg',
        hash: sha256(STAR_SVG),
      },
      {
        name: 'heart.svg',
        uri: 'public://canvas/icons/heart.svg',
        url: '/sites/default/files/canvas/icons/heart.svg',
        hash: sha256(HEART_SVG),
      },
    ],
    ...overrides,
  };
}

function modulePack(overrides: Partial<IconPack> = {}): IconPack {
  return {
    id: 'lucide',
    label: 'Lucide',
    description: 'Module-provided icons',
    iconCount: 1500,
    icons: [{ id: 'lucide:star', name: 'star', label: 'Star', svg: STAR_SVG }],
    ...overrides,
  };
}

function mockApiService(options: {
  libraries: Record<string, IconLibrary>;
  packs: Record<string, IconPack>;
}): ApiService {
  return {
    getIconLibraries: vi.fn().mockResolvedValue(options.libraries),
    getIconPacks: vi.fn().mockResolvedValue(options.packs),
    downloadFile: vi
      .fn()
      .mockImplementation((url: string) =>
        Promise.resolve(Buffer.from(SVG_BY_URL[url] ?? '', 'utf-8')),
      ),
  } as unknown as ApiService;
}

describe('pullIcons', () => {
  let tmpDir: string;

  beforeEach(async () => {
    tmpDir = await fs.mkdtemp(path.join(os.tmpdir(), 'icon-pull-test-'));
  });

  afterEach(async () => {
    await fs.rm(tmpDir, { recursive: true, force: true });
  });

  it('declares libraries in canvas.brand-kit.json and writes svg assets', async () => {
    const api = mockApiService({
      libraries: { my_icons: managedLibrary() },
      packs: {
        // The managed library also appears in the icons listing.
        my_icons: modulePack({ id: 'my_icons', label: 'My icons' }),
      },
    });

    const result = await pullIcons(api, tmpDir);

    expect(result).toEqual({ libraries: 1, assets: 2, packs: 0, skipped: 0 });

    const brandKit = JSON.parse(
      await fs.readFile(path.join(tmpDir, 'canvas.brand-kit.json'), 'utf-8'),
    );
    expect(brandKit.icons).toEqual({
      libraries: [
        {
          id: 'my_icons',
          label: 'My icons',
          description: 'A managed set',
        },
      ],
    });

    expect(api.downloadFile).toHaveBeenCalledWith(
      '/sites/default/files/canvas/icons/star.svg',
    );
    expect(
      await fs.readFile(
        path.join(tmpDir, 'icons', 'my_icons', 'star.svg'),
        'utf-8',
      ),
    ).toBe(STAR_SVG);
    expect(
      await fs.readFile(
        path.join(tmpDir, 'icons', 'my_icons', 'heart.svg'),
        'utf-8',
      ),
    ).toBe(HEART_SVG);
  });

  it('omits null description and keeps template in the declared entry', async () => {
    const api = mockApiService({
      libraries: {
        my_icons: managedLibrary({
          description: null,
          template: '<span>{{ svg }}</span>',
          assets: [],
        }),
      },
      packs: {},
    });

    await pullIcons(api, tmpDir);

    const brandKit = JSON.parse(
      await fs.readFile(path.join(tmpDir, 'canvas.brand-kit.json'), 'utf-8'),
    );
    expect(brandKit.icons.libraries).toEqual([
      {
        id: 'my_icons',
        label: 'My icons',
        template: '<span>{{ svg }}</span>',
      },
    ]);
  });

  it('preserves existing brand kit keys and entries when merging', async () => {
    await fs.writeFile(
      path.join(tmpDir, 'canvas.brand-kit.json'),
      JSON.stringify({
        fonts: { families: [{ name: 'Inter', provider: 'google' }] },
        icons: { libraries: [{ id: 'my_icons', label: 'Customized label' }] },
      }),
      'utf-8',
    );
    const api = mockApiService({
      libraries: {
        my_icons: managedLibrary({ assets: [] }),
        fresh: managedLibrary({ id: 'fresh', label: 'Fresh', assets: [] }),
      },
      packs: {},
    });

    await pullIcons(api, tmpDir);

    const brandKit = JSON.parse(
      await fs.readFile(path.join(tmpDir, 'canvas.brand-kit.json'), 'utf-8'),
    );
    // Existing keys and entries stay untouched; only new libraries append.
    expect(brandKit.fonts).toEqual({
      families: [{ name: 'Inter', provider: 'google' }],
    });
    expect(brandKit.icons.libraries).toEqual([
      { id: 'my_icons', label: 'Customized label' },
      { id: 'fresh', label: 'Fresh', description: 'A managed set' },
    ]);
  });

  it('writes pack.json for module-provided packs', async () => {
    const api = mockApiService({
      libraries: { my_icons: managedLibrary({ assets: [] }) },
      packs: {
        my_icons: modulePack({ id: 'my_icons', label: 'My icons' }),
        lucide: modulePack(),
      },
    });

    const result = await pullIcons(api, tmpDir);

    expect(result).toEqual({ libraries: 1, assets: 0, packs: 1, skipped: 0 });

    const packInfo = JSON.parse(
      await fs.readFile(
        path.join(tmpDir, 'icons', 'lucide', 'pack.json'),
        'utf-8',
      ),
    );
    expect(packInfo).toEqual({
      id: 'lucide',
      label: 'Lucide',
      description: 'Module-provided icons',
      iconCount: 1500,
      managed: false,
    });
    // The managed library gets a manifest.json, not a pack.json.
    await expect(
      fs.access(path.join(tmpDir, 'icons', 'my_icons', 'pack.json')),
    ).rejects.toThrow();
  });

  it('skips existing icon files and pack.json with skipOverwrite', async () => {
    const api = mockApiService({
      libraries: { my_icons: managedLibrary() },
      packs: { lucide: modulePack() },
    });
    const localStar = path.join(tmpDir, 'icons', 'my_icons', 'star.svg');
    const localPackJson = path.join(tmpDir, 'icons', 'lucide', 'pack.json');
    await fs.mkdir(path.dirname(localStar), { recursive: true });
    await fs.writeFile(localStar, '<svg>local edit</svg>', 'utf-8');
    await fs.mkdir(path.dirname(localPackJson), { recursive: true });
    await fs.writeFile(localPackJson, '{"local": true}', 'utf-8');

    const result = await pullIcons(api, tmpDir, true);

    // Existing files stay untouched; missing files are still pulled.
    expect(result).toEqual({ libraries: 1, assets: 1, packs: 0, skipped: 2 });
    expect(await fs.readFile(localStar, 'utf-8')).toBe('<svg>local edit</svg>');
    expect(await fs.readFile(localPackJson, 'utf-8')).toBe('{"local": true}');
    expect(
      await fs.readFile(
        path.join(tmpDir, 'icons', 'my_icons', 'heart.svg'),
        'utf-8',
      ),
    ).toBe(HEART_SVG);
    // Skipped files are never downloaded.
    expect(api.downloadFile).not.toHaveBeenCalledWith(
      '/sites/default/files/canvas/icons/star.svg',
    );
  });

  it('overwrites existing icon files without skipOverwrite', async () => {
    const api = mockApiService({
      libraries: { my_icons: managedLibrary() },
      packs: {},
    });
    const localStar = path.join(tmpDir, 'icons', 'my_icons', 'star.svg');
    await fs.mkdir(path.dirname(localStar), { recursive: true });
    await fs.writeFile(localStar, '<svg>local edit</svg>', 'utf-8');

    const result = await pullIcons(api, tmpDir);

    expect(result).toEqual({ libraries: 1, assets: 2, packs: 0, skipped: 0 });
    expect(await fs.readFile(localStar, 'utf-8')).toBe(STAR_SVG);
  });

  it('rejects unsafe asset names from the server', async () => {
    const api = mockApiService({
      libraries: {
        my_icons: managedLibrary({
          assets: [
            {
              name: '../evil.svg',
              uri: 'public://canvas/icons/evil.svg',
              url: '/sites/default/files/canvas/icons/evil.svg',
            },
          ],
        }),
      },
      packs: {},
    });

    await expect(pullIcons(api, tmpDir)).rejects.toThrow(
      /Invalid icon asset name "\.\.\/evil\.svg"/,
    );
  });

  it('pulled layout round-trips: pushIcons reports unchanged and skips packs', async () => {
    const pullApi = mockApiService({
      libraries: { my_icons: managedLibrary() },
      packs: { lucide: modulePack() },
    });
    await pullIcons(pullApi, tmpDir);

    const pushApi = {
      getIconLibraries: vi
        .fn()
        .mockResolvedValue({ my_icons: managedLibrary() }),
      createIconLibrary: vi.fn(),
      updateIconLibrary: vi.fn(),
      // Uploads resolve to the same uris the remote library already has.
      uploadIconAsset: vi
        .fn()
        .mockImplementation((libraryId: string, filename: string) =>
          Promise.resolve({
            uri: `public://canvas/icons/${filename}`,
            fid: 1,
            url: `/sites/default/files/canvas/icons/${filename}`,
          }),
        ),
    } as unknown as ApiService;

    const result = await pushIcons(pushApi, tmpDir);

    expect(result.outcomes).toEqual([
      {
        id: 'my_icons',
        operation: 'unchanged',
        success: true,
        uploadedCount: 0,
        skippedCount: 2,
        errors: [],
      },
    ]);
    // Hash-aware delta: identical pulled content is never re-uploaded.
    expect(pushApi.uploadIconAsset).not.toHaveBeenCalled();
    // The module-provided pack directory (pack.json only) is not pushed.
    expect(pushApi.createIconLibrary).not.toHaveBeenCalled();
    expect(pushApi.updateIconLibrary).not.toHaveBeenCalled();
  });
});
