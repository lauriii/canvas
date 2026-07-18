import fs from 'fs/promises';
import path from 'path';

/** Directory in the project root that holds local icon libraries and packs. */
export const ICONS_DIR = 'icons';

/** Icon library ids are Drupal config machine names. */
export const ICON_LIBRARY_ID_PATTERN = /^[a-z0-9_]+$/;

/** Icon filenames become icon ids (minus .svg), so keep them restricted. */
export const ICON_FILENAME_PATTERN = /^[a-zA-Z0-9._-]+\.svg$/;

/** Local manifest.json shape for a canvas-managed icon library. */
export interface IconLibraryManifest {
  id: string;
  label: string;
  description?: string;
  template?: string;
}

/** A validated local icon library, ready to push. */
export interface ValidatedIconLibrary {
  id: string;
  dir: string;
  manifest: IconLibraryManifest;
  /** Sorted icon filenames relative to the library directory. */
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

function isRecord(value: unknown): value is Record<string, unknown> {
  return Boolean(value) && typeof value === 'object' && !Array.isArray(value);
}

/**
 * Validates a local icon library directory before push: library id (from the
 * directory name), manifest.json shape, icon filename rules, and SVG safety
 * pre-checks. Throws with all errors listed so the user can fix the library
 * in one go.
 */
export async function validateIconLibraryDir(
  libraryDir: string,
): Promise<ValidatedIconLibrary> {
  const id = path.basename(libraryDir);
  const errors: string[] = [];

  if (!ICON_LIBRARY_ID_PATTERN.test(id)) {
    errors.push(
      `Invalid library id "${id}". Ids may only contain lowercase letters, digits, and underscores.`,
    );
  }

  let manifest: IconLibraryManifest | undefined;
  const manifestPath = path.join(libraryDir, 'manifest.json');
  let manifestRaw: string | null = null;
  try {
    manifestRaw = await fs.readFile(manifestPath, 'utf-8');
  } catch {
    errors.push('Missing manifest.json.');
  }

  if (manifestRaw !== null) {
    let parsed: unknown;
    try {
      parsed = JSON.parse(manifestRaw);
    } catch (err) {
      errors.push(
        `Invalid JSON in manifest.json: ${err instanceof Error ? err.message : String(err)}`,
      );
    }
    if (parsed !== undefined) {
      if (!isRecord(parsed)) {
        errors.push('manifest.json must contain a JSON object.');
      } else {
        if (typeof parsed.id !== 'string' || parsed.id !== id) {
          errors.push(
            `manifest.json "id" must match the directory name "${id}".`,
          );
        }
        if (typeof parsed.label !== 'string' || parsed.label.trim() === '') {
          errors.push('manifest.json is missing a non-empty "label".');
        }
        if (
          parsed.description !== undefined &&
          typeof parsed.description !== 'string'
        ) {
          errors.push('manifest.json "description" must be a string.');
        }
        if (
          parsed.template !== undefined &&
          typeof parsed.template !== 'string'
        ) {
          errors.push('manifest.json "template" must be a string.');
        }
        if (errors.length === 0) {
          manifest = {
            id,
            label: (parsed.label as string).trim(),
          };
          if (parsed.description !== undefined) {
            manifest.description = parsed.description as string;
          }
          if (parsed.template !== undefined) {
            manifest.template = parsed.template as string;
          }
        }
      }
    }
  }

  const svgFiles: string[] = [];
  const entries = await fs.readdir(libraryDir, { withFileTypes: true });
  const fileNames = entries
    .filter((entry) => entry.isFile())
    .map((entry) => entry.name)
    .sort((a, b) => a.localeCompare(b));
  for (const name of fileNames) {
    if (name === 'manifest.json') {
      continue;
    }
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
    const content = await fs.readFile(path.join(libraryDir, name), 'utf-8');
    for (const issue of validateSvgSafety(content)) {
      errors.push(`${name}: ${issue}.`);
    }
    svgFiles.push(name);
  }

  if (errors.length > 0) {
    throw new Error(
      `Icon library "${id}" validation failed:\n${errors.join('\n')}`,
    );
  }

  return { id, dir: libraryDir, manifest: manifest!, svgFiles };
}
