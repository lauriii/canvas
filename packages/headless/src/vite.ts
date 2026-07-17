/**
 * @file
 * Vite integration for the generated Canvas component implementation registry.
 * Node-only: component discovery reads the application filesystem.
 */

import path from 'node:path';

import {
  buildComponentRegistryModule,
  resolveComponentRegistrySourcePaths,
} from './component-registry';

import type { ComponentRegistrySourcePaths } from './component-registry';

/** Virtual module containing every discovered component implementation. */
export const CANVAS_COMPONENTS_MODULE_ID =
  'virtual:@drupal-canvas/headless/components';

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
    configureServer(server) {
      configureComponentRegistryWatcher(server, () => projectRoot);
    },
    resolveId(id) {
      return id === CANVAS_COMPONENTS_MODULE_ID
        ? RESOLVED_CANVAS_COMPONENTS_MODULE_ID
        : undefined;
    },
    async load(id) {
      return id === RESOLVED_CANVAS_COMPONENTS_MODULE_ID
        ? buildComponentRegistryModule({ projectRoot })
        : undefined;
    },
  };
}

/** Invalidates the generated virtual registry after structural changes. */
function configureComponentRegistryWatcher(
  server: ComponentRegistryViteDevServer,
  getProjectRoot: () => string,
): void {
  let sourcePaths = resolveComponentRegistrySourcePaths({
    projectRoot: getProjectRoot(),
  });
  let timer: ReturnType<typeof setTimeout> | undefined;
  server.watcher.add([sourcePaths.configPath, sourcePaths.componentRoot]);

  const refresh = () => {
    server.moduleGraph.invalidateAll();
    for (const environment of Object.values(server.environments)) {
      environment.moduleGraph.invalidateAll();
    }
    server.ws.send({ type: 'full-reload' });
  };
  const onChange = (event: string, filePath: string) => {
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
      server.watcher.add([sourcePaths.configPath, sourcePaths.componentRoot]);
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
