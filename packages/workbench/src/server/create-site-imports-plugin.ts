import { resolveFromImportMap } from '@drupal-canvas/vite-compat';

import type { Plugin } from 'vite';

/**
 * Serves bare specifiers that only the Drupal site can resolve.
 *
 * A module can add entries to the Canvas import map through
 * hook_canvas_importmap_alter(), and a component may import them. Those are not
 * npm packages, so nothing in the project resolves them; on the site the
 * browser resolves them against its import map. `canvas pull` records that map,
 * and this plugin uses it to point the preview at the site's copy.
 *
 * Runs as a `post` plugin so it only sees specifiers nothing else resolved:
 * `react` and the other specifiers Canvas provides stay on Workbench's own
 * copies, which is what keeps a single React runtime in the preview.
 */
export function createSiteImportsPlugin(
  siteImports: Record<string, string>,
): Plugin {
  return {
    name: 'canvas-workbench-site-imports',
    enforce: 'post',
    resolveId(source) {
      if (
        source.startsWith('.') ||
        source.startsWith('/') ||
        source.startsWith('\0')
      ) {
        return null;
      }

      const url = resolveFromImportMap(source, siteImports);
      if (url === null) {
        return null;
      }

      // Root-relative URLs mean the browser requests them from the Workbench
      // dev server, which proxies them to the site. Going direct to the site
      // would be a cross-origin module fetch that Drupal sends no CORS headers
      // for. An absolute URL is left as-is: it is somebody else's host, so
      // there is nothing to proxy it through.
      return { id: url, external: true };
    },
  };
}

/**
 * Path prefixes the dev server must proxy for the recorded imports to load.
 *
 * Derived from the recorded URLs rather than hardcoded, because where a module
 * or theme serves its JavaScript from depends on how the site is installed.
 */
export function getSiteImportProxyPrefixes(
  siteImports: Record<string, string>,
): string[] {
  const prefixes = new Set<string>();
  for (const url of Object.values(siteImports)) {
    // Only root-relative URLs are served by the site this project pulled from.
    if (!url.startsWith('/')) {
      continue;
    }
    const [firstSegment] = url.slice(1).split('/');
    if (firstSegment) {
      prefixes.add(`/${firstSegment}/`);
    }
  }
  return [...prefixes].sort();
}
