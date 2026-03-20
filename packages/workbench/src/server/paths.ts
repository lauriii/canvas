import { createRequire } from 'node:module';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { resolveCanvasConfig } from '@drupal-canvas/discovery';

export interface WorkbenchPathsOptions {
  moduleUrl: string;
  clientRootRelativePath?: string;
}

export interface WorkbenchPaths {
  appHtmlPath: string;
  allowedFsRoots: string[];
  clientRoot: string;
  componentDiscoveryRoot: string;
  hostProjectRoot: string;
  packageRoot: string;
  pagesDiscoveryRoot: string;
  runningInsideWorkbenchPackage: boolean;
  watchRoots: string[];
  workbenchSourceRoot: string;
}

export function resolveWorkbenchPaths(
  options: WorkbenchPathsOptions,
): WorkbenchPaths {
  const modulePath = fileURLToPath(options.moduleUrl);
  const moduleDir = path.dirname(modulePath);
  const packageRoot = path.resolve(moduleDir, '../..');
  const clientRoot = path.resolve(
    packageRoot,
    options.clientRootRelativePath ?? '.',
  );
  const workbenchSourceRoot = path.resolve(clientRoot, '..');
  const hostProjectRoot = process.cwd();
  const runningInsideWorkbenchPackage =
    path.resolve(hostProjectRoot) === path.resolve(packageRoot);
  const require = createRequire(options.moduleUrl);
  const geistPackageRoot = path.dirname(
    require.resolve('@fontsource-variable/geist/package.json'),
  );
  const geistMonoPackageRoot = path.dirname(
    require.resolve('@fontsource-variable/geist-mono/package.json'),
  );
  const canvasConfig = resolveCanvasConfig({ hostRoot: hostProjectRoot });
  const componentDiscoveryRoot = path.resolve(
    hostProjectRoot,
    canvasConfig.componentDir,
  );
  const pagesDiscoveryRoot = path.resolve(
    hostProjectRoot,
    canvasConfig.pagesDir,
  );
  const watchRoots = [...new Set([componentDiscoveryRoot, pagesDiscoveryRoot])];

  return {
    appHtmlPath: path.resolve(clientRoot, 'index.html'),
    allowedFsRoots: [
      hostProjectRoot,
      packageRoot,
      clientRoot,
      geistPackageRoot,
      geistMonoPackageRoot,
      ...watchRoots,
    ],
    clientRoot,
    componentDiscoveryRoot,
    hostProjectRoot,
    packageRoot,
    pagesDiscoveryRoot,
    runningInsideWorkbenchPackage,
    watchRoots,
    workbenchSourceRoot,
  };
}
