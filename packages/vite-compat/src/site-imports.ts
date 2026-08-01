import { promises as fs } from 'node:fs';
import path from 'node:path';

/**
 * The site's import map, recorded in the project by `canvas pull`.
 *
 * A plain import map document, so the browser can consume it as-is through a
 * `<script type="importmap">` tag and no tool has to learn a Canvas-specific
 * format. It records what the site resolves for code components: what Canvas
 * ships, what the CLI pushed, and what modules and themes contribute through
 * hook_canvas_importmap_alter(). Commit it so CI can validate imports offline.
 *
 * @see https://developer.mozilla.org/en-US/docs/Web/HTML/Element/script/type/importmap
 */
export const SITE_IMPORT_MAP_FILE = 'canvas-importmap.json';

export interface ImportMap {
  imports: Record<string, string>;
  scopes?: Record<string, Record<string, string>>;
}

export async function writeSiteImportMap(
  projectRoot: string,
  importMap: ImportMap,
): Promise<void> {
  await fs.writeFile(
    path.join(projectRoot, SITE_IMPORT_MAP_FILE),
    `${JSON.stringify(importMap, null, 2)}\n`,
    'utf-8',
  );
}

/**
 * Reads the recorded import map, or null when the project has not pulled one.
 *
 * A null result means "unknown", not "empty": callers must not treat it as the
 * site resolving nothing.
 */
export async function readSiteImportMap(
  projectRoot: string,
): Promise<ImportMap | null> {
  try {
    const raw = await fs.readFile(
      path.join(projectRoot, SITE_IMPORT_MAP_FILE),
      'utf-8',
    );
    const parsed = JSON.parse(raw) as Partial<ImportMap>;
    if (!parsed.imports || typeof parsed.imports !== 'object') {
      return null;
    }
    return { imports: parsed.imports, scopes: parsed.scopes };
  } catch {
    return null;
  }
}

/**
 * Resolves a bare specifier against an import map's top-level imports.
 *
 * Follows the import map spec for bare specifiers: a key either matches
 * exactly, or ends in a slash and prefixes the specifier, in which case the
 * rest of the specifier is appended to the mapped prefix and the longest
 * matching prefix wins. Scopes are not consulted, because there is no importer
 * URL to match them against.
 */
export function resolveFromImportMap(
  specifier: string,
  importMap: ImportMap,
): string | null {
  const { imports } = importMap;
  if (Object.hasOwn(imports, specifier)) {
    return imports[specifier];
  }

  const prefix = Object.keys(imports)
    .filter((key) => key.endsWith('/') && specifier.startsWith(key))
    .sort((a, b) => b.length - a.length)[0];

  return prefix === undefined
    ? null
    : imports[prefix] + specifier.slice(prefix.length);
}

/**
 * Whether an import map resolves a specifier.
 */
export function isResolvedByImportMap(
  specifier: string,
  importMap: ImportMap,
): boolean {
  return resolveFromImportMap(specifier, importMap) !== null;
}
