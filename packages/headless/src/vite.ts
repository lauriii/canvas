/**
 * @file
 * Vite integration for the generated Canvas component implementation registry.
 * Node-only: component discovery reads the application filesystem.
 */

import path from 'node:path';

import {
  BRAND_KIT_STYLESHEET_PATH,
  buildComponentRegistryModule,
  resolveComponentRegistrySourcePaths,
  writeBrandKitStylesheet,
} from './component-registry';

import type { ComponentRegistrySourcePaths } from './component-registry';

/** Virtual module containing every discovered component implementation. */
export const CANVAS_COMPONENTS_MODULE_ID =
  'virtual:@drupal-canvas/headless/components';

/** Virtual module resolving to the app's configured global CSS entrypoint. */
export const CANVAS_GLOBAL_CSS_MODULE_ID =
  'virtual:@drupal-canvas/headless/global.css';

/**
 * Module id of the brand kit stylesheet generated from the app's
 * canvas.brand-kit.json (fonts, then colors), written to
 * `.canvas/brand-kit.css`. Import it next to the app's global CSS, as a side
 * effect or with `?url`.
 */
export const CANVAS_BRAND_KIT_CSS_MODULE_ID =
  'virtual:@drupal-canvas/headless/brand-kit.css';

const RESOLVED_CANVAS_COMPONENTS_MODULE_ID = `\0${CANVAS_COMPONENTS_MODULE_ID}`;

type RegistryWatchEvent = 'add' | 'addDir' | 'change' | 'unlink' | 'unlinkDir';

const REGISTRY_WATCH_EVENTS = new Set<RegistryWatchEvent>([
  'add',
  'addDir',
  'change',
  'unlink',
  'unlinkDir',
]);

export interface CanvasComponentRegistryPluginOptions {
  /**
   * Application root containing canvas.config.json. Defaults to Vite's root.
   */
  projectRoot?: string;
}

/**
 * The Vite hooks used by the registry plugin.
 *
 * This structural contract avoids coupling framework adapters to the exact
 * Vite version that resolves the headless package's types.
 */
export interface CanvasComponentRegistryVitePlugin {
  name: string;
  enforce: 'pre';
  configResolved(config: { root: string }): void;
  buildStart(): Promise<void>;
  configureServer(server: ComponentRegistryViteDevServer): void;
  resolveId(id: string): string | undefined;
  load(id: string): Promise<string | undefined>;
}

interface ComponentRegistryViteDevServer {
  watcher: {
    add(paths: string[]): unknown;
    on(
      event: 'all',
      listener: (event: string, filePath: string) => void,
    ): unknown;
    off(
      event: 'all',
      listener: (event: string, filePath: string) => void,
    ): unknown;
  };
  moduleGraph: { invalidateAll(): void };
  environments: Record<string, { moduleGraph: { invalidateAll(): void } }>;
  ws: { send(payload: { type: 'full-reload' }): void };
  httpServer?: {
    once(event: 'close', listener: () => void): unknown;
  } | null;
}

/**
 * Provides the generated component registry to Vite-based framework adapters.
 */
export function canvasComponentRegistry(
  options: CanvasComponentRegistryPluginOptions = {},
): CanvasComponentRegistryVitePlugin {
  let projectRoot = path.resolve(options.projectRoot ?? process.cwd());

  return {
    name: '@drupal-canvas/headless:component-registry',
    enforce: 'pre',
    configResolved(config) {
      projectRoot = path.resolve(options.projectRoot ?? config.root);
    },
    async buildStart() {
      await writeBrandKitStylesheetFor(projectRoot);
    },
    configureServer(server) {
      configureComponentRegistryWatcher(server, () => projectRoot);
    },
    resolveId(id) {
      if (id === CANVAS_COMPONENTS_MODULE_ID) {
        return RESOLVED_CANVAS_COMPONENTS_MODULE_ID;
      }
      if (id === CANVAS_GLOBAL_CSS_MODULE_ID) {
        return resolveComponentRegistrySourcePaths({ projectRoot })
          .globalCssPath;
      }
      const [file, query] = id.split('?');
      if (file === CANVAS_BRAND_KIT_CSS_MODULE_ID) {
        const stylesheetPath = path.join(
          projectRoot,
          BRAND_KIT_STYLESHEET_PATH,
        );
        return query === undefined
          ? stylesheetPath
          : `${stylesheetPath}?${query}`;
      }
      return undefined;
    },
    async load(id) {
      return id === RESOLVED_CANVAS_COMPONENTS_MODULE_ID
        ? buildComponentRegistryModule({ projectRoot })
        : undefined;
    },
  };
}

/** Writes .canvas/brand-kit.css; Vite serves and hot-updates it as a file. */
function writeBrandKitStylesheetFor(projectRoot: string): Promise<void> {
  return writeBrandKitStylesheet({
    projectRoot,
    stylesheetPath: path.join(projectRoot, BRAND_KIT_STYLESHEET_PATH),
    onFontError: (family, error) =>
      console.warn(
        `[canvas] Could not resolve brand kit font "${family}": ${String(error)}`,
      ),
  });
}

/**
 * Invalidates the generated registry after structural changes, and rewrites
 * the brand kit stylesheet after canvas.brand-kit.json changes.
 */
function configureComponentRegistryWatcher(
  server: ComponentRegistryViteDevServer,
  getProjectRoot: () => string,
): void {
  let sourcePaths = resolveComponentRegistrySourcePaths({
    projectRoot: getProjectRoot(),
  });
  let timer: ReturnType<typeof setTimeout> | undefined;
  let brandKitWrite = Promise.resolve();
  server.watcher.add([
    sourcePaths.configPath,
    sourcePaths.brandKitPath,
    sourcePaths.componentRoot,
  ]);

  const refresh = () => {
    server.moduleGraph.invalidateAll();
    for (const environment of Object.values(server.environments)) {
      environment.moduleGraph.invalidateAll();
    }
    server.ws.send({ type: 'full-reload' });
  };
  const onChange = (event: string, filePath: string) => {
    if (path.resolve(filePath) === sourcePaths.brandKitPath) {
      // Serialized, so a slow provider font resolution for an earlier save
      // cannot overwrite a later one.
      brandKitWrite = brandKitWrite
        .then(() => writeBrandKitStylesheetFor(getProjectRoot()))
        .catch((error) =>
          console.warn(
            `[canvas] Could not update the brand kit stylesheet: ${String(error)}`,
          ),
        );
      return;
    }
    if (
      !REGISTRY_WATCH_EVENTS.has(event as RegistryWatchEvent) ||
      !isRegistryStructureChange(
        event as RegistryWatchEvent,
        filePath,
        sourcePaths,
      )
    ) {
      return;
    }
    if (path.resolve(filePath) === sourcePaths.configPath) {
      sourcePaths = resolveComponentRegistrySourcePaths({
        projectRoot: getProjectRoot(),
      });
      server.watcher.add([
        sourcePaths.configPath,
        sourcePaths.brandKitPath,
        sourcePaths.componentRoot,
      ]);
    }
    if (timer) {
      clearTimeout(timer);
    }
    timer = setTimeout(refresh, 50);
  };

  server.watcher.on('all', onChange);
  server.httpServer?.once('close', () => {
    if (timer) {
      clearTimeout(timer);
    }
    server.watcher.off('all', onChange);
  });
}

function isRegistryStructureChange(
  event: RegistryWatchEvent,
  filePath: string,
  sourcePaths: ComponentRegistrySourcePaths,
): boolean {
  const absolutePath = path.resolve(filePath);
  if (absolutePath === sourcePaths.configPath) {
    return true;
  }

  const relativePath = path.relative(sourcePaths.componentRoot, absolutePath);
  if (relativePath === '') {
    return event !== 'change';
  }
  if (
    relativePath === '..' ||
    relativePath.startsWith(`..${path.sep}`) ||
    path.isAbsolute(relativePath)
  ) {
    return false;
  }

  return event !== 'change' || absolutePath.endsWith('.yml');
}

export default canvasComponentRegistry;
