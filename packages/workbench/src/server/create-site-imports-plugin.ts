import { resolveFromImportMap } from '@drupal-canvas/vite-compat';

import type { ImportMap } from '@drupal-canvas/vite-compat';
import type { Plugin } from 'vite';

/**
 * Resolves bare specifiers that only the Drupal site can resolve.
 *
 * A module can add entries to the Canvas import map through
 * hook_canvas_importmap_alter(), and a component may import them. Those are not
 * npm packages, so nothing in the project resolves them.
 *
 * The site and the in-browser code editor's preview both hand the import map to
 * the browser and let it resolve these natively. Workbench cannot: Vite's dev
 * server owns module resolution and rewrites every bare import, including ones
 * left external, to a `/@id/` URL that no import map gets a say in. So the
 * mapping is applied here instead, from the same recorded map.
 *
 * @see \Drupal\canvas\Render\ImportMapResponseAttachmentsProcessor
 * @see ui/src/features/code-editor/Preview.tsx
 */
export function createSiteImportsPlugin(importMap: ImportMap): Plugin {
  return {
    name: 'canvas-workbench-site-imports',
    // Runs after every other resolver, so it only sees specifiers nothing else
    // resolved. React and the rest of what Canvas provides have local copies
    // and never reach this, which is what keeps the preview on a single React
    // runtime.
    enforce: 'post',
    resolveId(source) {
      if (
        source.startsWith('.') ||
        source.startsWith('/') ||
        source.startsWith('\0')
      ) {
        return null;
      }

      const url = resolveFromImportMap(source, importMap);
      if (url === null) {
        return null;
      }

      // Mapped URLs are root-relative, so the browser requests them from the
      // Workbench dev server, which proxies them to the site. Going direct
      // would be a cross-origin module fetch that Drupal sends no CORS headers
      // for. An absolute URL is left alone: it is somebody else's host, so
      // there is nothing to proxy it through.
      return { id: url, external: true };
    },
  };
}

/**
 * Path prefixes the dev server must proxy for the mapped modules to load.
 *
 * Derived from the mapped URLs rather than hardcoded, because where a module or
 * theme serves its JavaScript from depends on how the site is installed.
 */
export function getSiteImportProxyPrefixes(importMap: ImportMap): string[] {
  const prefixes = new Set<string>();
  const urls = [
    ...Object.values(importMap.imports),
    ...Object.values(importMap.scopes ?? {}).flatMap((scope) =>
      Object.values(scope),
    ),
  ];
  for (const url of urls) {
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
