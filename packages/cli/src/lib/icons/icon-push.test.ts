import fs from 'fs/promises';
import os from 'os';
import path from 'path';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import {
  buildIconPushPlannedResults,
  discoverIconLibraryDirs,
  pushIcons,
} from './icon-push';

import type { ApiService } from '../../services/api';
import type { IconLibrary } from '../../types/IconLibrary';

const STAR_SVG =
  '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M12 2l3 7h7l-5.5 4.5L18 21l-6-4-6 4 1.5-7.5L2 9h7z"/></svg>';
const HEART_SVG =
  '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M12 21l-8-8a5 5 0 017-7l1 1 1-1a5 5 0 017 7z"/></svg>';

function remoteLibrary(overrides: Partial<IconLibrary> = {}): IconLibrary {
  return {
    id: 'my_icons',
    label: 'My icons',
    description: null,
    template: null,
    assets: null,
    ...overrides,
  };
}

describe('icon-push', () => {
  let tmpDir: string;
  let api: ApiService;

  beforeEach(async () => {
    tmpDir = await fs.mkdtemp(path.join(os.tmpdir(), 'icon-push-test-'));
    let uploadCount = 0;
    api = {
      getIconLibraries: vi.fn().mockResolvedValue({}),
      createIconLibrary: vi.fn().mockResolvedValue({}),
      updateIconLibrary: vi.fn().mockResolvedValue({}),
      uploadIconAsset: vi.fn().mockImplementation(() => {
        uploadCount++;
        return Promise.resolve({
          uri: `public://canvas/icons/upload-${uploadCount}.svg`,
          fid: uploadCount,
          url: `/sites/default/files/canvas/icons/upload-${uploadCount}.svg`,
        });
      }),
    } as unknown as ApiService;
  });

  afterEach(async () => {
    await fs.rm(tmpDir, { recursive: true, force: true });
  });

  async function writeLibrary(
    id: string,
    manifest: Record<string, unknown>,
    files: Record<string, string>,
  ): Promise<void> {
    const dir = path.join(tmpDir, 'icons', id);
    await fs.mkdir(dir, { recursive: true });
    await fs.writeFile(
      path.join(dir, 'manifest.json'),
      JSON.stringify(manifest, null, 2),
      'utf-8',
    );
    for (const [name, content] of Object.entries(files)) {
      await fs.writeFile(path.join(dir, name), content, 'utf-8');
    }
  }

  describe('discoverIconLibraryDirs', () => {
    it('returns an empty list when the icons directory is missing', async () => {
      expect(await discoverIconLibraryDirs(tmpDir)).toEqual([]);
    });

    it('skips directories without a manifest.json (module pack.json dirs)', async () => {
      await writeLibrary(
        'my_icons',
        { id: 'my_icons', label: 'My icons' },
        { 'star.svg': STAR_SVG },
      );
      const packDir = path.join(tmpDir, 'icons', 'module_pack');
      await fs.mkdir(packDir, { recursive: true });
      await fs.writeFile(
        path.join(packDir, 'pack.json'),
        JSON.stringify({ id: 'module_pack', managed: false }),
        'utf-8',
      );

      const discovered = await discoverIconLibraryDirs(tmpDir);
      expect(discovered.map((library) => library.id)).toEqual(['my_icons']);
    });
  });

  describe('pushIcons', () => {
    it('returns an empty result and makes no network calls when there are no libraries', async () => {
      const result = await pushIcons(api, tmpDir);

      expect(result.outcomes).toEqual([]);
      expect(api.getIconLibraries).not.toHaveBeenCalled();
      expect(api.uploadIconAsset).not.toHaveBeenCalled();
    });

    it('creates a new library with uploaded assets', async () => {
      await writeLibrary(
        'my_icons',
        { id: 'my_icons', label: 'My icons', description: 'A set' },
        { 'star.svg': STAR_SVG, 'heart.svg': HEART_SVG },
      );

      const result = await pushIcons(api, tmpDir);

      expect(result.created).toBe(1);
      expect(result.failed).toBe(0);
      expect(result.outcomes).toEqual([
        { id: 'my_icons', operation: 'create', success: true, errors: [] },
      ]);
      expect(api.uploadIconAsset).toHaveBeenCalledTimes(2);
      expect(api.uploadIconAsset).toHaveBeenCalledWith(
        'my_icons',
        'heart.svg',
        expect.any(Buffer),
      );
      expect(api.uploadIconAsset).toHaveBeenCalledWith(
        'my_icons',
        'star.svg',
        expect.any(Buffer),
      );
      // The asset upload route resolves the entity, so the library must be
      // created (without assets) before any SVG is uploaded, and the assets
      // list arrives in the final update.
      expect(api.createIconLibrary).toHaveBeenCalledTimes(1);
      expect(api.createIconLibrary).toHaveBeenCalledWith({
        id: 'my_icons',
        label: 'My icons',
        description: 'A set',
        assets: null,
      });
      expect(
        vi.mocked(api.createIconLibrary).mock.invocationCallOrder[0],
      ).toBeLessThan(
        vi.mocked(api.uploadIconAsset).mock.invocationCallOrder[0],
      );
      expect(api.updateIconLibrary).toHaveBeenCalledTimes(1);
      expect(api.updateIconLibrary).toHaveBeenCalledWith('my_icons', {
        label: 'My icons',
        description: 'A set',
        template: null,
        assets: [
          {
            name: 'heart.svg',
            uri: 'public://canvas/icons/upload-1.svg',
          },
          {
            name: 'star.svg',
            uri: 'public://canvas/icons/upload-2.svg',
          },
        ],
      });
    });

    it('updates an existing library when its content differs', async () => {
      await writeLibrary(
        'my_icons',
        { id: 'my_icons', label: 'New label' },
        { 'star.svg': STAR_SVG },
      );
      vi.mocked(api.getIconLibraries).mockResolvedValue({
        my_icons: remoteLibrary({
          label: 'Old label',
          assets: [
            {
              name: 'star.svg',
              uri: 'public://canvas/icons/upload-1.svg',
              url: '/sites/default/files/canvas/icons/upload-1.svg',
            },
          ],
        }),
      });

      const result = await pushIcons(api, tmpDir);

      expect(result.updated).toBe(1);
      expect(result.outcomes).toEqual([
        { id: 'my_icons', operation: 'update', success: true, errors: [] },
      ]);
      expect(api.createIconLibrary).not.toHaveBeenCalled();
      expect(api.updateIconLibrary).toHaveBeenCalledTimes(1);
      expect(api.updateIconLibrary).toHaveBeenCalledWith('my_icons', {
        label: 'New label',
        description: null,
        template: null,
        assets: [
          { name: 'star.svg', uri: 'public://canvas/icons/upload-1.svg' },
        ],
      });
    });

    it('reports unchanged and skips the update when nothing differs', async () => {
      await writeLibrary(
        'my_icons',
        { id: 'my_icons', label: 'My icons' },
        { 'star.svg': STAR_SVG },
      );
      vi.mocked(api.getIconLibraries).mockResolvedValue({
        my_icons: remoteLibrary({
          assets: [
            {
              name: 'star.svg',
              uri: 'public://canvas/icons/upload-1.svg',
              url: '/sites/default/files/canvas/icons/upload-1.svg',
            },
          ],
        }),
      });

      const result = await pushIcons(api, tmpDir);

      expect(result.unchanged).toBe(1);
      expect(result.outcomes).toEqual([
        { id: 'my_icons', operation: 'unchanged', success: true, errors: [] },
      ]);
      expect(api.uploadIconAsset).toHaveBeenCalledTimes(1);
      expect(api.createIconLibrary).not.toHaveBeenCalled();
      expect(api.updateIconLibrary).not.toHaveBeenCalled();
    });

    it('surfaces server sanitization errors with the file path and continues with other libraries', async () => {
      await writeLibrary(
        'bad_icons',
        { id: 'bad_icons', label: 'Bad icons' },
        { 'sneaky.svg': STAR_SVG },
      );
      await writeLibrary(
        'good_icons',
        { id: 'good_icons', label: 'Good icons' },
        { 'star.svg': STAR_SVG },
      );
      vi.mocked(api.uploadIconAsset).mockImplementation(
        (libraryId: string, filename: string) => {
          if (libraryId === 'bad_icons') {
            return Promise.reject(
              new Error(
                'The SVG file contains disallowed markup (script or event handlers).',
              ),
            );
          }
          return Promise.resolve({
            uri: `public://canvas/icons/${filename}`,
            fid: 1,
            url: `/sites/default/files/canvas/icons/${filename}`,
          });
        },
      );

      const result = await pushIcons(api, tmpDir);

      expect(result.failed).toBe(1);
      expect(result.created).toBe(1);
      const failedOutcome = result.outcomes.find((o) => o.id === 'bad_icons');
      expect(failedOutcome?.success).toBe(false);
      expect(failedOutcome?.errors).toEqual([
        `${path.join('icons', 'bad_icons', 'sneaky.svg')}: The SVG file contains disallowed markup (script or event handlers).`,
      ]);
      // Both new libraries are created up front (the upload route resolves
      // the entity), but the failed library never receives its assets list.
      expect(api.createIconLibrary).toHaveBeenCalledTimes(2);
      expect(api.updateIconLibrary).toHaveBeenCalledTimes(1);
      expect(api.updateIconLibrary).toHaveBeenCalledWith(
        'good_icons',
        expect.objectContaining({ label: 'Good icons' }),
      );
    });

    it('fails validation for an unsafe local SVG before any network call', async () => {
      await writeLibrary(
        'my_icons',
        { id: 'my_icons', label: 'My icons' },
        { 'evil.svg': '<svg><script>alert(1)</script></svg>' },
      );

      const result = await pushIcons(api, tmpDir);

      expect(result.failed).toBe(1);
      expect(result.outcomes[0].success).toBe(false);
      expect(result.outcomes[0].errors[0]).toContain(
        'evil.svg: contains a <script> element',
      );
      expect(api.getIconLibraries).not.toHaveBeenCalled();
      expect(api.uploadIconAsset).not.toHaveBeenCalled();
      expect(api.createIconLibrary).not.toHaveBeenCalled();
      expect(api.updateIconLibrary).not.toHaveBeenCalled();
    });

    it('fails only the library whose create request fails', async () => {
      await writeLibrary(
        'my_icons',
        { id: 'my_icons', label: 'My icons' },
        { 'star.svg': STAR_SVG },
      );
      vi.mocked(api.createIconLibrary).mockRejectedValue(
        new Error('Validation failed on the server.'),
      );

      const result = await pushIcons(api, tmpDir);

      expect(result.failed).toBe(1);
      expect(result.outcomes).toEqual([
        {
          id: 'my_icons',
          success: false,
          errors: ['Validation failed on the server.'],
        },
      ]);
    });
  });

  describe('buildIconPushPlannedResults', () => {
    it('plans create for new libraries and update for existing ones', () => {
      const results = buildIconPushPlannedResults(
        ['existing_lib', 'new_lib'],
        { existing_lib: remoteLibrary({ id: 'existing_lib' }) },
        { create: 'create', update: 'update' },
      );

      expect(results).toEqual([
        {
          itemName: 'existing_lib',
          itemType: 'Icon library',
          success: true,
          details: [{ content: 'update' }],
        },
        {
          itemName: 'new_lib',
          itemType: 'Icon library',
          success: true,
          details: [{ content: 'create' }],
        },
      ]);
    });
  });
});
