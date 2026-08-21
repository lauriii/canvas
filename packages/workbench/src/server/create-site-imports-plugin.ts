import { resolveFromImportMap } from '@drupal-canvas/vite-compat';

import type { ImportMap } from '@drupal-canvas/vite-compat';
import type { Plugin } from 'vite';

const VIRTUAL_PREFIX = '\0canvas-site-import:';

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
 * left external, to a `/@id/` URL that no import map gets a say in.
 *
 * So the module is fetched from the site and handed to Vite as a real module
 * instead. That matters beyond just loading it: these modules have imports of
 * their own — a hook that calls `useState` imports React — and going through
 * Vite means those resolve to the same copies the component uses. Serving the
 * file to the browser directly would leave its bare imports unresolvable, and
 * mapping them to the site's copies would put two Reacts in one preview.
 *
 * @see \Drupal\canvas\Render\ImportMapResponseAttachmentsProcessor
 * @see ui/src/features/code-editor/Preview.tsx
 */
export function createSiteImportsPlugin(
  importMap: ImportMap,
  siteUrl: string | undefined,
): Plugin {
  const sources = new Map<string, Promise<string>>();

  const fetchModule = (url: string): Promise<string> => {
    const cached = sources.get(url);
    if (cached) {
      return cached;
    }
    const pending = (async () => {
      const response = await fetch(url);
      if (!response.ok) {
        throw new Error(`${response.status} ${response.statusText} for ${url}`);
      }
      return response.text();
    })();
    sources.set(url, pending);
    return pending;
  };

  return {
    name: 'canvas-workbench-site-imports',
    // Runs after every other resolver, so it only sees specifiers nothing else
    // resolved. React and the rest of what Canvas provides have local copies
    // and never reach this, which is what keeps the preview on a single React
    // runtime.
    enforce: 'post',
    resolveId(source, importer) {
      // An import from inside a module already fetched from the site: resolve
      // it against that module's URL so multi-file modules work.
      if (importer?.startsWith(VIRTUAL_PREFIX) && !source.startsWith('\0')) {
        const importerUrl = importer.slice(VIRTUAL_PREFIX.length);
        if (source.startsWith('.') || source.startsWith('/')) {
          return VIRTUAL_PREFIX + new URL(source, importerUrl).href;
        }
      }

      if (
        source.startsWith('.') ||
        source.startsWith('/') ||
        source.startsWith('\0')
      ) {
        return null;
      }

      const mapped = resolveFromImportMap(source, importMap);
      if (mapped === null) {
        return null;
      }

      if (!siteUrl) {
        throw new Error(
          `Cannot preview an import of "${source}": it is provided by the Drupal site, ` +
            'so Workbench needs to fetch it. Set CANVAS_SITE_URL to the site this project was pulled from.',
        );
      }

      return VIRTUAL_PREFIX + new URL(mapped, siteUrl).href;
    },
    async load(id) {
      if (!id.startsWith(VIRTUAL_PREFIX)) {
        return null;
      }
      return fetchModule(id.slice(VIRTUAL_PREFIX.length));
    },
  };
}

/**
 * Path prefixes the dev server must proxy for site-provided assets to load.
 *
 * JavaScript goes through Vite, but a module's stylesheets and images are
 * requested by the browser against the mapped URLs, which are root-relative to
 * the site. Derived from those URLs rather than hardcoded, because where a
 * module or theme serves its files from depends on how the site is installed.
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
