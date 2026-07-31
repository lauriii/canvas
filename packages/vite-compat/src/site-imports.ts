import { promises as fs } from 'node:fs';
import path from 'node:path';

/**
 * Project state recording which bare specifiers the site resolves in the
 * browser, so builds do not have to reach the site to know.
 *
 * Written by `canvas pull` from the global asset library's `siteImports`, which
 * is the site's effective import map: what Canvas ships, what the CLI pushed,
 * and what modules and themes contribute through hook_canvas_importmap_alter().
 * Commit it so CI can validate imports offline.
 */
export const SITE_IMPORTS_FILE = 'canvas-site-imports.json';

export interface SiteImportsFile {
  imports: Record<string, string>;
}

export async function writeSiteImports(
  projectRoot: string,
  imports: Record<string, string>,
): Promise<void> {
  const contents: SiteImportsFile = { imports };
  await fs.writeFile(
    path.join(projectRoot, SITE_IMPORTS_FILE),
    `${JSON.stringify(contents, null, 2)}\n`,
    'utf-8',
  );
}

/**
 * Reads the pulled site imports, or null when the project has not pulled them.
 *
 * A null result means "unknown", not "empty": callers must not treat it as the
 * site providing nothing.
 */
export async function readSiteImports(
  projectRoot: string,
): Promise<Record<string, string> | null> {
  try {
    const raw = await fs.readFile(
      path.join(projectRoot, SITE_IMPORTS_FILE),
      'utf-8',
    );
    const parsed = JSON.parse(raw) as Partial<SiteImportsFile>;
    if (!parsed.imports || typeof parsed.imports !== 'object') {
      return null;
    }
    return parsed.imports;
  } catch {
    return null;
  }
}

/**
 * Resolves a specifier against an import map, or null if it does not resolve.
 *
 * Follows the import map spec: a key either matches exactly, or ends in a slash
 * and prefixes the specifier, in which case the rest of the specifier is
 * appended to the mapped prefix. Longer prefixes win over shorter ones.
 *
 * @see https://developer.mozilla.org/en-US/docs/Web/HTML/Element/script/type/importmap
 */
export function resolveFromImportMap(
  specifier: string,
  imports: Record<string, string>,
): string | null {
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
  imports: Record<string, string>,
): boolean {
  return resolveFromImportMap(specifier, imports) !== null;
}
