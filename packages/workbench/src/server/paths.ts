import { createRequire } from 'node:module';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { loadEnv } from 'vite';
import { resolveCanvasConfig } from '@drupal-canvas/discovery';
import { validateCanvasImportRoots } from '@drupal-canvas/vite-compat';

export interface WorkbenchPathsOptions {
  moduleUrl: string;
  clientRootRelativePath?: string;
}

export interface WorkbenchPaths {
  appHtmlPath: string;
  allowedFsRoots: string[];
  clientRoot: string;
  componentDiscoveryRoot: string;
  contentTemplatesDiscoveryRoot: string;
  siteUrl: string | null;
  hostProjectRoot: string;
  packageRoot: string;
  pagesDiscoveryRoot: string;
  regionsDiscoveryRoot: string;
  /** Absolute path to the user's optional layout component, or null if absent. */
  layoutPath: string;
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
  const canvasConfigWarnings: string[] = [];
  const canvasConfig = resolveCanvasConfig({
    hostRoot: hostProjectRoot,
    onWarning: (warning) => canvasConfigWarnings.push(warning.message),
  });
  for (const warning of canvasConfigWarnings) {
    console.warn(`[workbench] ${warning}`);
  }
  validateCanvasImportRoots({
    hostRoot: hostProjectRoot,
    aliasBaseDir: canvasConfig.aliasBaseDir,
    componentDir: canvasConfig.componentDir,
  });
  const componentDiscoveryRoot = path.resolve(
    hostProjectRoot,
    canvasConfig.componentDir,
  );
  const pagesDiscoveryRoot = path.resolve(
    hostProjectRoot,
    canvasConfig.pagesDir,
  );
  const contentTemplatesDiscoveryRoot = path.resolve(
    hostProjectRoot,
    canvasConfig.contentTemplatesDir,
  );
  const regionsDiscoveryRoot = path.resolve(
    hostProjectRoot,
    canvasConfig.regionsDir,
  );
  const layoutPath = path.resolve(hostProjectRoot, canvasConfig.layoutPath);
  const watchRoots = [
    ...new Set([
      componentDiscoveryRoot,
      pagesDiscoveryRoot,
      contentTemplatesDiscoveryRoot,
      regionsDiscoveryRoot,
    ]),
  ];

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
    contentTemplatesDiscoveryRoot,
    siteUrl:
      loadEnv(
        'development',
        hostProjectRoot,
        'CANVAS_',
      ).CANVAS_SITE_URL?.trim() || null,
    hostProjectRoot,
    packageRoot,
    pagesDiscoveryRoot,
    regionsDiscoveryRoot,
    layoutPath,
    runningInsideWorkbenchPackage,
    watchRoots,
    workbenchSourceRoot,
  };
}
