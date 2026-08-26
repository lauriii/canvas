import fs from 'fs/promises';
import path from 'path';

import { updateBrandKitIconsConfig } from './icon-config.js';
import { ICON_FILENAME_PATTERN, ICONS_DIR } from './icon-validate.js';

import type { IconLibraryEntry } from '../../config.js';
import type { ApiService } from '../../services/api.js';

/** Server-provided ids become local directory names, so keep them restricted. */
const SAFE_DIRECTORY_NAME_PATTERN = /^[a-zA-Z0-9._-]+$/;

function assertSafeDirectoryName(id: string, kind: string): void {
  if (!SAFE_DIRECTORY_NAME_PATTERN.test(id) || id === '.' || id === '..') {
    throw new Error(`Invalid ${kind} id "${id}" returned by the server.`);
  }
}

async function fileExists(filePath: string): Promise<boolean> {
  return fs
    .access(filePath)
    .then(() => true)
    .catch(() => false);
}

export interface PullIconsResult {
  libraries: number;
  assets: number;
  packs: number;
  /** Files left untouched because they already exist and --skip-overwrite was set. */
  skipped: number;
}

/**
 * Pull icons from the server into the local project, mirroring the fonts
 * workflow: canvas-managed libraries (from the config API) are declared in
 * canvas.brand-kit.json (`icons.libraries`) and their SVG assets are written
 * into icons/<id>/, ready to push again. Module-provided packs (in the icons
 * listing but not the config API) are written as informational
 * icons/<id>/pack.json files that push skips.
 *
 * With skipOverwrite, existing local SVG and pack.json files are left
 * untouched (the same semantics the other pull tasks use); missing files are
 * still written. Like the fonts pull, this never deletes local files: SVGs
 * that exist only locally stay in place, and an authoritative push uploads
 * them again unless they are removed by hand.
 */
export async function pullIcons(
  api: ApiService,
  projectRoot: string,
  skipOverwrite = false,
): Promise<PullIconsResult> {
  const [libraries, packs] = await Promise.all([
    api.getIconLibraries(),
    api.getIconPacks(),
  ]);

  const iconsDir = path.resolve(projectRoot, ICONS_DIR);
  let libraryCount = 0;
  let assetCount = 0;
  let packCount = 0;
  let skippedCount = 0;

  const pulledEntries: IconLibraryEntry[] = [];
  for (const library of Object.values(libraries)) {
    assertSafeDirectoryName(library.id, 'icon library');
    const libraryDir = path.join(iconsDir, library.id);
    await fs.mkdir(libraryDir, { recursive: true });

    const entry: IconLibraryEntry = {
      id: library.id,
      label: library.label,
    };
    if (library.description != null) {
      entry.description = library.description;
    }
    if (library.template != null) {
      entry.template = library.template;
    }
    pulledEntries.push(entry);

    for (const asset of library.assets ?? []) {
      if (!ICON_FILENAME_PATTERN.test(asset.name)) {
        throw new Error(
          `Invalid icon asset name "${asset.name}" in library "${library.id}".`,
        );
      }
      const assetPath = path.join(libraryDir, asset.name);
      if (skipOverwrite && (await fileExists(assetPath))) {
        skippedCount++;
        continue;
      }
      const buffer = await api.downloadFile(asset.url);
      await fs.writeFile(assetPath, buffer);
      assetCount++;
    }

    libraryCount++;
  }

  // Declare the pulled libraries in canvas.brand-kit.json (mirroring how
  // fonts merge pulled families), preserving existing entries.
  await updateBrandKitIconsConfig(projectRoot, pulledEntries);

  for (const [packId, pack] of Object.entries(packs)) {
    // Canvas-managed packs are already covered by their library directory.
    if (packId in libraries) {
      continue;
    }
    assertSafeDirectoryName(packId, 'icon pack');
    const packDir = path.join(iconsDir, packId);
    const packJsonPath = path.join(packDir, 'pack.json');
    if (skipOverwrite && (await fileExists(packJsonPath))) {
      skippedCount++;
      continue;
    }
    await fs.mkdir(packDir, { recursive: true });
    await fs.writeFile(
      packJsonPath,
      `${JSON.stringify(
        {
          id: pack.id,
          label: pack.label,
          description: pack.description,
          iconCount: pack.iconCount,
          managed: false,
        },
        null,
        2,
      )}\n`,
      'utf-8',
    );
    packCount++;
  }

  return {
    libraries: libraryCount,
    assets: assetCount,
    packs: packCount,
    skipped: skippedCount,
  };
}
