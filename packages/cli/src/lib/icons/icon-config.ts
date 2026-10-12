import fs from 'fs/promises';
import path from 'path';

import { BRAND_KIT_CONFIG_FILENAME } from '../../config.js';

import type { IconLibraryEntry, IconsConfig } from '../../config.js';

/**
 * Reads the `icons` section of canvas.brand-kit.json, mirroring how fonts
 * read their `fonts` section. Returns undefined when the file or the `icons`
 * key is missing — the presence of the key is meaningful: a declared library
 * list is authoritative, so push removes remote canvas-managed libraries
 * that are no longer listed (the same replace semantics fonts use).
 */
export async function readBrandKitIconsConfig(
  projectRoot: string,
): Promise<IconsConfig | undefined> {
  const configPath = path.resolve(projectRoot, BRAND_KIT_CONFIG_FILENAME);
  let raw: string;
  try {
    raw = await fs.readFile(configPath, 'utf-8');
  } catch (err) {
    // Brand kit config is optional.
    if (
      err &&
      typeof err === 'object' &&
      'code' in err &&
      err.code === 'ENOENT'
    ) {
      return undefined;
    }
    throw err;
  }
  let parsed: { icons?: IconsConfig };
  try {
    parsed = JSON.parse(raw) as { icons?: IconsConfig };
  } catch (err) {
    const message = err instanceof Error ? err.message : String(err);
    throw new Error(`Invalid JSON in ${BRAND_KIT_CONFIG_FILENAME}: ${message}`);
  }
  const icons = parsed?.icons;
  if (icons && typeof icons === 'object' && Array.isArray(icons.libraries)) {
    return icons;
  }
  return undefined;
}

/**
 * Merges newly pulled icon libraries into canvas.brand-kit.json, preserving
 * other top-level keys and existing library entries (matched by id) —
 * mirroring how fonts merge pulled families into the same file.
 */
export async function updateBrandKitIconsConfig(
  projectRoot: string,
  newLibraries: IconLibraryEntry[],
): Promise<void> {
  if (newLibraries.length === 0) return;

  const configPath = path.resolve(projectRoot, BRAND_KIT_CONFIG_FILENAME);
  let fileContent: Record<string, unknown> = {};
  try {
    const raw = await fs.readFile(configPath, 'utf-8');
    const parsed = JSON.parse(raw);
    if (parsed && typeof parsed === 'object' && !Array.isArray(parsed)) {
      fileContent = parsed as Record<string, unknown>;
    }
  } catch {
    // File missing or invalid; start with empty object.
  }

  const existingIcons = (fileContent.icons as IconsConfig | undefined) ?? {
    libraries: [],
  };
  const existingLibraries = Array.isArray(existingIcons.libraries)
    ? existingIcons.libraries
    : [];
  const existingIds = new Set(existingLibraries.map((library) => library.id));
  const mergedLibraries = [
    ...existingLibraries,
    ...newLibraries.filter((library) => !existingIds.has(library.id)),
  ];
  const nextIcons: IconsConfig = {
    ...existingIcons,
    libraries: mergedLibraries,
  };

  await fs.writeFile(
    configPath,
    `${JSON.stringify({ ...fileContent, icons: nextIcons }, null, 2)}\n`,
    'utf-8',
  );
}
