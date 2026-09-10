import fs from 'fs/promises';
import path from 'path';

import type { IconLibraryEntry } from '../../config.js';

/** Directory in the project root that holds local icon libraries and packs. */
export const ICONS_DIR = 'icons';

/** Icon library ids are Drupal config machine names. */
export const ICON_LIBRARY_ID_PATTERN = /^[a-z0-9_]+$/;

/** Icon filenames become icon ids (minus .svg), so keep them restricted. */
export const ICON_FILENAME_PATTERN = /^[a-zA-Z0-9._-]+\.svg$/;

/** A validated local icon library, ready to push. */
export interface ValidatedIconLibrary {
  id: string;
  label: string;
  description?: string;
  template?: string;
  /** Directory the SVG files are read from. */
  filesDir: string;
  /** Sorted icon filenames relative to `filesDir`. */
  svgFiles: string[];
}

/**
 * Client-side SVG safety pre-checks that mirror the server sanitizer for fast
 * feedback: scripts, event handler attributes, javascript: URLs, DOCTYPE
 * declarations, and external href/src references. The server remains
 * authoritative; these are best-effort early errors.
 * Returns human-readable issue descriptions (empty array means no issues).
 */
export function validateSvgSafety(content: string): string[] {
  const issues: string[] = [];

  if (/<script/i.test(content)) {
    issues.push('contains a <script> element');
  }
  if (/\son[a-z]+\s*=/i.test(content)) {
    issues.push('contains an event handler attribute (on*)');
  }
  if (/javascript:/i.test(content)) {
    issues.push('contains a "javascript:" URL');
  }
  if (/<!DOCTYPE/i.test(content)) {
    issues.push('contains a DOCTYPE declaration');
  }

  // Reject href/src attributes with absolute or protocol-relative URLs.
  // Fragment references (#id) and relative paths are allowed.
  const urlAttributePattern =
    /\s(?:xlink:)?(?:href|src)\s*=\s*(?:"([^"]*)"|'([^']*)'|([^\s>'"]+))/gi;
  for (const match of content.matchAll(urlAttributePattern)) {
    const value = (match[1] ?? match[2] ?? match[3] ?? '').trim();
    if (/^\/\//.test(value) || /^[a-z][a-z0-9+.-]*:/i.test(value)) {
      issues.push(`references an external URL ("${value}")`);
    }
  }

  return issues;
}

/**
 * Validates one icon library entry before push: id, entry fields, the source
 * directory, icon filename rules, and SVG safety pre-checks. Throws with all
 * errors listed so the user can fix the library in one go.
 *
 * Entries come from `canvas.brand-kit.json` (`icons.libraries`, mirroring
 * `fonts.families`) and must declare at least a human-readable `label`.
 */
export async function validateIconLibraryEntry(
  entry: IconLibraryEntry,
  projectRoot: string,
): Promise<ValidatedIconLibrary> {
  const id = entry.id;
  const errors: string[] = [];

  if (typeof id !== 'string' || !ICON_LIBRARY_ID_PATTERN.test(id)) {
    errors.push(
      `Invalid library id "${id}". Ids may only contain lowercase letters, digits, and underscores.`,
    );
  }
  // A human-readable label is required, mirroring a font family's "name".
  const label = typeof entry.label === 'string' ? entry.label.trim() : '';
  if (label === '') {
    errors.push('missing or empty "label".');
  }
  if (
    entry.description !== undefined &&
    typeof entry.description !== 'string'
  ) {
    errors.push('"description" must be a string.');
  }
  if (entry.template !== undefined && typeof entry.template !== 'string') {
    errors.push('"template" must be a string.');
  }
  if (entry.source !== undefined && typeof entry.source !== 'string') {
    errors.push('"source" must be a string.');
  }

  const relativeDir = entry.source ?? path.join(ICONS_DIR, id);
  const filesDir = path.resolve(projectRoot, relativeDir);
  const sourceExists = await fs
    .stat(filesDir)
    .then((stat) => stat.isDirectory())
    .catch(() => false);
  if (!sourceExists) {
    errors.push(`The library directory does not exist: ${relativeDir}`);
    throw new Error(
      `Icon library "${id}" validation failed:\n${errors.join('\n')}`,
    );
  }

  const svgFiles: string[] = [];
  const directoryEntries = await fs.readdir(filesDir, { withFileTypes: true });
  const fileNames = directoryEntries
    .filter((dirent) => dirent.isFile())
    .map((dirent) => dirent.name)
    .sort((a, b) => a.localeCompare(b));
  for (const name of fileNames) {
    // Only files intended as icons are validated; other files are ignored.
    if (!/\.svg$/i.test(name)) {
      continue;
    }
    if (!ICON_FILENAME_PATTERN.test(name)) {
      errors.push(
        `${name}: invalid icon filename. Filenames may only contain letters, digits, dots, underscores, and dashes, and must end in .svg.`,
      );
      continue;
    }
    const content = await fs.readFile(path.join(filesDir, name), 'utf-8');
    for (const issue of validateSvgSafety(content)) {
      errors.push(`${name}: ${issue}.`);
    }
    svgFiles.push(name);
  }

  if (svgFiles.length === 0) {
    errors.push(
      'The library contains no SVG icons. Add at least one .svg file (the filename, minus .svg, becomes the icon id).',
    );
  }

  if (errors.length > 0) {
    throw new Error(
      `Icon library "${id}" validation failed:\n${errors.join('\n')}`,
    );
  }

  return {
    id,
    label,
    ...(entry.description !== undefined && { description: entry.description }),
    ...(entry.template !== undefined && { template: entry.template }),
    filesDir,
    svgFiles,
  };
}
