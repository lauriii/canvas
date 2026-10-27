import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

// The core Canvas Headless SDK package and prefix for its framework adapters.
const HEADLESS_SDK_PACKAGE = '@drupal-canvas/headless';

/**
 * Checks whether a package name is the Canvas Headless SDK or one of its
 * framework adapters, e.g. `@drupal-canvas/headless-next`.
 *
 * @param packageName - The npm package name.
 * @returns True for `@drupal-canvas/headless` and `@drupal-canvas/headless-*`.
 */
export function isHeadlessSdkPackage(packageName: string): boolean {
  return (
    packageName === HEADLESS_SDK_PACKAGE ||
    packageName.startsWith(`${HEADLESS_SDK_PACKAGE}-`)
  );
}

/**
 * Detects whether the Canvas Headless SDK is installed in a project.
 *
 * Reads the project's package.json and checks whether the core SDK package or
 * one of its framework adapters is declared as a dependency or devDependency.
 * When it is, `canvas push` treats every component as external (metadata only),
 * because the headless app renders them rather than Drupal.
 *
 * @param projectRoot - Absolute path to the project root.
 * @returns True when the core SDK package is declared, false otherwise.
 */
export function detectHeadlessSdk(projectRoot: string): boolean {
  try {
    const packageJsonPath = resolve(projectRoot, 'package.json');
    const packageJson = JSON.parse(readFileSync(packageJsonPath, 'utf-8'));
    const dependencyNames = [
      ...Object.keys(packageJson?.dependencies ?? {}),
      ...Object.keys(packageJson?.devDependencies ?? {}),
    ];
    return dependencyNames.some(isHeadlessSdkPackage);
  } catch {
    // No package.json, or it is unreadable/invalid: not a headless project.
    return false;
  }
}
