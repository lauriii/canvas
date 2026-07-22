import crypto from 'crypto';
import fs from 'fs/promises';
import os from 'os';
import path from 'path';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import {
  buildIconPushPlannedResults,
  discoverIconLibraries,
  planIconLibraryDeletions,
  pushIcons,
} from './icon-push';

import type { IconsConfig } from '../../config';
import type { ApiService } from '../../services/api';
import type { IconLibrary } from '../../types/IconLibrary';

const STAR_SVG =
  '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M12 2l3 7h7l-5.5 4.5L18 21l-6-4-6 4 1.5-7.5L2 9h7z"/></svg>';
const HEART_SVG =
  '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M12 21l-8-8a5 5 0 017-7l1 1 1-1a5 5 0 017 7z"/></svg>';

const sha256 = (content: string): string =>
  crypto.createHash('sha256').update(content).digest('hex');

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
      deleteIconLibrary: vi.fn().mockResolvedValue(undefined),
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

  async function writeBrandKitIcons(icons: IconsConfig): Promise<void> {
    await fs.writeFile(
      path.join(tmpDir, 'canvas.brand-kit.json'),
      JSON.stringify({ icons }, null, 2),
      'utf-8',
    );
  }

  async function writeSvgDir(
    relativeDir: string,
    files: Record<string, string>,
  ): Promise<void> {
    const dir = path.join(tmpDir, relativeDir);
    await fs.mkdir(dir, { recursive: true });
    for (const [name, content] of Object.entries(files)) {
      await fs.writeFile(path.join(dir, name), content, 'utf-8');
    }
  }

  describe('discoverIconLibraries', () => {
    it('returns an empty non-authoritative result with no config or dirs', async () => {
      expect(await discoverIconLibraries(tmpDir)).toEqual({
        libraries: [],
        authoritative: false,
      });
    });

    it('returns only declared entries and ignores bare directories', async () => {
      await writeBrandKitIcons({
        libraries: [
          {
            id: 'lucide',
            label: 'Lucide',
            source: 'node_modules/lucide-static/icons',
          },
          { id: 'my_icons', label: 'My icons' },
        ],
      });
      // A bare directory of SVGs without a declaration is never discovered.
      await writeSvgDir('icons/plain_icons', { 'star.svg': STAR_SVG });

      const result = await discoverIconLibraries(tmpDir);

      expect(result.authoritative).toBe(true);
      expect(result.libraries.map((library) => library.id)).toEqual([
        'lucide',
        'my_icons',
      ]);
    });

    it('is not authoritative and discovers nothing without an icons key', async () => {
      // A bare directory alone is not a declared library.
      await writeSvgDir('icons/plain_icons', { 'star.svg': STAR_SVG });

      const result = await discoverIconLibraries(tmpDir);

      expect(result.authoritative).toBe(false);
      expect(result.libraries).toEqual([]);
    });
  });

  describe('planIconLibraryDeletions', () => {
    it('plans deletions only when the local set is authoritative', () => {
      const remote = {
        keep: remoteLibrary({ id: 'keep' }),
        drop: remoteLibrary({ id: 'drop' }),
      };
      expect(planIconLibraryDeletions(remote, ['keep'], true)).toEqual([
        'drop',
      ]);
      expect(planIconLibraryDeletions(remote, ['keep'], false)).toEqual([]);
    });
  });

  describe('pushIcons', () => {
    it('returns an empty result and makes no network calls when there are no libraries', async () => {
      const result = await pushIcons(api, tmpDir);

      expect(result.outcomes).toEqual([]);
      expect(api.getIconLibraries).not.toHaveBeenCalled();
      expect(api.uploadIconAsset).not.toHaveBeenCalled();
    });

    it('creates a new declared library with uploaded assets', async () => {
      await writeBrandKitIcons({
        libraries: [
          { id: 'my_icons', label: 'My icons', description: 'A set' },
        ],
      });
      await writeSvgDir('icons/my_icons', {
        'star.svg': STAR_SVG,
        'heart.svg': HEART_SVG,
      });

      // Serial uploads keep the mock upload counter deterministic.
      const result = await pushIcons(api, tmpDir, { concurrency: 1 });

      expect(result.created).toBe(1);
      expect(result.failed).toBe(0);
      expect(result.outcomes).toEqual([
        {
          id: 'my_icons',
          operation: 'create',
          success: true,
          uploadedCount: 2,
          skippedCount: 0,
          errors: [],
        },
      ]);
      // The asset upload route resolves the entity, so the library must be
      // created (without assets) before any SVG is uploaded, and the assets
      // list arrives in the final update.
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
      expect(api.updateIconLibrary).toHaveBeenCalledWith('my_icons', {
        label: 'My icons',
        description: 'A set',
        template: null,
        assets: [
          {
            name: 'heart.svg',
            uri: 'public://canvas/icons/upload-1.svg',
            hash: sha256(HEART_SVG),
          },
          {
            name: 'star.svg',
            uri: 'public://canvas/icons/upload-2.svg',
            hash: sha256(STAR_SVG),
          },
        ],
      });
    });

    it('pushes only declared libraries and ignores bare directories', async () => {
      await writeBrandKitIcons({
        libraries: [
          { id: 'plain_icons', label: 'Plain icons', source: 'vendor/icons' },
        ],
      });
      await writeSvgDir('vendor/icons', { 'star.svg': STAR_SVG });
      // An undeclared bare directory is never pushed.
      await writeSvgDir('icons/undeclared', { 'heart.svg': HEART_SVG });

      const result = await pushIcons(api, tmpDir);

      expect(result.created).toBe(1);
      expect(api.createIconLibrary).toHaveBeenCalledTimes(1);
      expect(api.createIconLibrary).toHaveBeenCalledWith(
        expect.objectContaining({ id: 'plain_icons', label: 'Plain icons' }),
      );
    });

    it('reports unchanged and uploads nothing when nothing differs', async () => {
      await writeBrandKitIcons({
        libraries: [{ id: 'my_icons', label: 'My icons' }],
      });
      await writeSvgDir('icons/my_icons', { 'star.svg': STAR_SVG });
      vi.mocked(api.getIconLibraries).mockResolvedValue({
        my_icons: remoteLibrary({
          assets: [
            {
              name: 'star.svg',
              uri: 'public://canvas/icons/upload-1.svg',
              url: '/sites/default/files/canvas/icons/upload-1.svg',
              hash: sha256(STAR_SVG),
            },
          ],
        }),
      });

      const result = await pushIcons(api, tmpDir);

      expect(result.unchanged).toBe(1);
      expect(result.deleted).toBe(0);
      // Matching hashes mean no bytes leave the machine.
      expect(api.uploadIconAsset).not.toHaveBeenCalled();
      expect(api.updateIconLibrary).not.toHaveBeenCalled();
      expect(api.deleteIconLibrary).not.toHaveBeenCalled();
    });

    it('uploads only changed files on an incremental push', async () => {
      await writeBrandKitIcons({
        libraries: [{ id: 'my_icons', label: 'My icons' }],
      });
      await writeSvgDir('icons/my_icons', {
        'star.svg': STAR_SVG,
        'heart.svg': HEART_SVG,
      });
      vi.mocked(api.getIconLibraries).mockResolvedValue({
        my_icons: remoteLibrary({
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
              hash: sha256('<svg>old heart</svg>'),
            },
          ],
        }),
      });

      const progress: Array<[string, number, number]> = [];
      const result = await pushIcons(api, tmpDir, {
        onProgress: (id, done, total) => progress.push([id, done, total]),
      });

      expect(result.outcomes).toEqual([
        {
          id: 'my_icons',
          operation: 'update',
          success: true,
          uploadedCount: 1,
          skippedCount: 1,
          errors: [],
        },
      ]);
      // Only the changed heart.svg is uploaded; star.svg reuses the remote
      // entry.
      expect(api.uploadIconAsset).toHaveBeenCalledTimes(1);
      expect(api.uploadIconAsset).toHaveBeenCalledWith(
        'my_icons',
        'heart.svg',
        expect.any(Buffer),
      );
      expect(api.updateIconLibrary).toHaveBeenCalledWith(
        'my_icons',
        expect.objectContaining({
          assets: [
            {
              name: 'heart.svg',
              uri: 'public://canvas/icons/upload-1.svg',
              hash: sha256(HEART_SVG),
            },
            {
              name: 'star.svg',
              uri: 'public://canvas/icons/star.svg',
              hash: sha256(STAR_SVG),
            },
          ],
        }),
      );
      // Progress covers every file, uploaded or skipped.
      expect(progress).toHaveLength(2);
      expect(progress[progress.length - 1]).toEqual(['my_icons', 2, 2]);
    });

    it('deletes remote libraries missing from an authoritative local set', async () => {
      await writeBrandKitIcons({
        libraries: [{ id: 'my_icons', label: 'My icons' }],
      });
      await writeSvgDir('icons/my_icons', { 'star.svg': STAR_SVG });
      vi.mocked(api.getIconLibraries).mockResolvedValue({
        my_icons: remoteLibrary({
          assets: [
            {
              name: 'star.svg',
              uri: 'public://canvas/icons/upload-1.svg',
              url: '/sites/default/files/canvas/icons/upload-1.svg',
              hash: sha256(STAR_SVG),
            },
          ],
        }),
        obsolete: remoteLibrary({ id: 'obsolete', label: 'Obsolete' }),
      });

      const result = await pushIcons(api, tmpDir);

      expect(result.deleted).toBe(1);
      expect(api.deleteIconLibrary).toHaveBeenCalledWith('obsolete');
      expect(result.outcomes).toContainEqual({
        id: 'obsolete',
        operation: 'delete',
        success: true,
        errors: [],
      });
    });

    it('deletes all remote libraries when the declared list is empty', async () => {
      await writeBrandKitIcons({ libraries: [] });
      vi.mocked(api.getIconLibraries).mockResolvedValue({
        obsolete: remoteLibrary({ id: 'obsolete', label: 'Obsolete' }),
      });

      const result = await pushIcons(api, tmpDir);

      expect(result.deleted).toBe(1);
      expect(api.deleteIconLibrary).toHaveBeenCalledWith('obsolete');
    });

    it('never deletes without an icons key in the config file', async () => {
      await writeSvgDir('icons/plain_icons', { 'star.svg': STAR_SVG });
      vi.mocked(api.getIconLibraries).mockResolvedValue({
        other: remoteLibrary({ id: 'other', label: 'Other' }),
      });

      const result = await pushIcons(api, tmpDir);

      expect(result.deleted).toBe(0);
      expect(api.deleteIconLibrary).not.toHaveBeenCalled();
    });

    it('surfaces server sanitization errors with the file path and continues with other libraries', async () => {
      await writeBrandKitIcons({
        libraries: [
          { id: 'bad_icons', label: 'Bad icons' },
          { id: 'good_icons', label: 'Good icons' },
        ],
      });
      await writeSvgDir('icons/bad_icons', { 'sneaky.svg': STAR_SVG });
      await writeSvgDir('icons/good_icons', { 'star.svg': STAR_SVG });
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

    it('fails validation for an unsafe local SVG without pushing it', async () => {
      await writeBrandKitIcons({
        libraries: [{ id: 'my_icons', label: 'My icons' }],
      });
      await writeSvgDir('icons/my_icons', {
        'evil.svg': '<svg><script>alert(1)</script></svg>',
      });

      const result = await pushIcons(api, tmpDir);

      expect(result.failed).toBe(1);
      expect(result.outcomes[0].success).toBe(false);
      expect(result.outcomes[0].errors[0]).toContain(
        'evil.svg: contains a <script> element',
      );
      // The unsafe content never reaches the server.
      expect(api.uploadIconAsset).not.toHaveBeenCalled();
      expect(api.createIconLibrary).not.toHaveBeenCalled();
      expect(api.updateIconLibrary).not.toHaveBeenCalled();
    });

    it('fails only the library whose create request fails', async () => {
      await writeBrandKitIcons({
        libraries: [{ id: 'my_icons', label: 'My icons' }],
      });
      await writeSvgDir('icons/my_icons', { 'star.svg': STAR_SVG });
      vi.mocked(api.createIconLibrary).mockRejectedValue(
        new Error('Validation failed on the server.'),
      );

      const result = await pushIcons(api, tmpDir);

      expect(result.failed).toBe(1);
      expect(result.outcomes[0].success).toBe(false);
      expect(result.outcomes[0].errors).toEqual([
        'Validation failed on the server.',
      ]);
      expect(api.updateIconLibrary).not.toHaveBeenCalled();
    });
  });

  describe('buildIconPushPlannedResults', () => {
    it('labels creates, updates, and deletions', () => {
      const results = buildIconPushPlannedResults(
        ['existing', 'fresh'],
        {
          existing: remoteLibrary({ id: 'existing' }),
          obsolete: remoteLibrary({ id: 'obsolete' }),
        },
        { create: 'Create', update: 'Update', delete: 'Delete' },
        true,
      );

      expect(results).toEqual([
        {
          itemName: 'existing',
          itemType: 'Icon library',
          success: true,
          details: [{ content: 'Update' }],
        },
        {
          itemName: 'fresh',
          itemType: 'Icon library',
          success: true,
          details: [{ content: 'Create' }],
        },
        {
          itemName: 'obsolete',
          itemType: 'Icon library',
          success: true,
          details: [{ content: 'Delete' }],
        },
      ]);
    });
  });
});
