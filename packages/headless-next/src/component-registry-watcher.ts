import { existsSync, statSync, watch } from 'node:fs';
import path from 'node:path';
import { resolveComponentRegistrySourcePaths } from '@drupal-canvas/headless/component-registry';

import { writeComponentRegistryModule } from './component-registry';

import type { FSWatcher } from 'node:fs';
import type { ComponentRegistrySourcePaths } from '@drupal-canvas/headless/component-registry';

interface RegistryWatcherState {
  directoryWatcher?: FSWatcher;
  timer?: ReturnType<typeof setTimeout>;
}

interface RegistryWatcherGlobal {
  __drupalCanvasHeadlessNextRegistryWatchers?: Map<
    string,
    RegistryWatcherState
  >;
}

const globalState = globalThis as typeof globalThis & RegistryWatcherGlobal;
const registryWatchers =
  (globalState.__drupalCanvasHeadlessNextRegistryWatchers ??= new Map());

/** Keeps Next's generated registry synchronized while the dev server runs. */
export function watchComponentRegistry(projectRoot: string): void {
  if (registryWatchers.has(projectRoot)) {
    return;
  }

  const state: RegistryWatcherState = {};
  registryWatchers.set(projectRoot, state);

  const subscribeToComponentRoot = () => {
    state.directoryWatcher?.close();
    const sourcePaths = resolveComponentRegistrySourcePaths({ projectRoot });
    const watchRoot = findNearestExistingDirectory(sourcePaths.componentRoot);
    const watchesComponentRoot = watchRoot === sourcePaths.componentRoot;

    state.directoryWatcher = watch(
      watchRoot,
      { recursive: watchesComponentRoot },
      (event, filename) => {
        const changedPath = filename
          ? path.resolve(watchRoot, filename.toString())
          : sourcePaths.componentRoot;
        const rootMayHaveAppeared =
          !watchesComponentRoot &&
          (changedPath === sourcePaths.componentRoot ||
            sourcePaths.componentRoot.startsWith(`${changedPath}${path.sep}`));
        if (
          !rootMayHaveAppeared &&
          !isRegistryStructureChange(
            event === 'rename' ? 'rename' : 'change',
            changedPath,
            sourcePaths,
          )
        ) {
          return;
        }
        // Remove stale imports immediately when an entry disappears; additions
        // stay briefly debounced so component.yml and its entry can land
        // together during a multi-file create or copy operation.
        scheduleRefresh(
          event === 'rename' && !existsSync(changedPath) ? 0 : 50,
        );
      },
    );
    state.directoryWatcher.on('error', (error) => {
      console.warn(
        `[canvas] Could not watch local components: ${error.message}`,
      );
    });
    state.directoryWatcher.unref();
  };

  const scheduleRefresh = (delay = 50) => {
    if (state.timer) {
      clearTimeout(state.timer);
    }
    state.timer = setTimeout(() => {
      state.timer = undefined;
      void writeComponentRegistryModule(projectRoot)
        .then(() => subscribeToComponentRoot())
        .catch((error: unknown) => {
          console.warn(
            `[canvas] Could not update the local component registry: ${error instanceof Error ? error.message : String(error)}`,
          );
        });
    }, delay);
    state.timer.unref();
  };

  subscribeToComponentRoot();

  const configWatcher = watch(projectRoot, (_event, filename) => {
    if (filename?.toString() === 'canvas.config.json') {
      scheduleRefresh();
    }
  });
  configWatcher.on('error', (error) => {
    console.warn(
      `[canvas] Could not watch canvas.config.json: ${error.message}`,
    );
  });
  configWatcher.unref();
}

function isRegistryStructureChange(
  event: 'change' | 'rename',
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

function findNearestExistingDirectory(directory: string): string {
  let candidate = directory;
  while (
    !existsSync(candidate) ||
    !statSync(candidate, { throwIfNoEntry: false })?.isDirectory()
  ) {
    const parent = path.dirname(candidate);
    if (parent === candidate) {
      return candidate;
    }
    candidate = parent;
  }
  return candidate;
}
