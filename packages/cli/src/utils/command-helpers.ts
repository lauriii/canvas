import { InvalidArgumentError } from 'commander';
import * as p from '@clack/prompts';

import {
  getConfig,
  getDefaultScope,
  parseBooleanSetting,
  setConfig,
  usesManagedDefaultScope,
} from '../config';

import type { CanvasSyncConfig } from '@drupal-canvas/discovery';

/**
 * Updates config with common CLI options
 */
interface SyncCommandOptions {
  includePages?: boolean;
  includeContentTemplates?: boolean;
  includeRegions?: boolean;
  pages?: boolean;
  contentTemplates?: boolean;
  regions?: boolean;
  sync?: Partial<CanvasSyncConfig>;
}

export function applySyncOptionAliasesAndWarnings(
  options: SyncCommandOptions,
): void {
  if (typeof options.includePages === 'boolean') {
    p.log.warn(
      options.includePages
        ? '--include-pages is deprecated because pages are included by default. Remove this flag.'
        : '--include-pages=false is deprecated and will be removed in a future release. Use --no-pages to exclude pages.',
    );
  }
  if (typeof options.includeContentTemplates === 'boolean') {
    p.log.warn(
      options.includeContentTemplates
        ? '--include-content-templates is deprecated because content templates are included by default. Remove this flag.'
        : '--include-content-templates=false is deprecated and will be removed in a future release. Use --no-content-templates to exclude content templates.',
    );
  }
  if (typeof options.includeRegions === 'boolean') {
    p.log.warn(
      options.includeRegions
        ? '--include-regions is deprecated because global regions are included by default. Remove this flag.'
        : '--include-regions=false is deprecated and will be removed in a future release. Use --no-regions to exclude global regions.',
    );
  }

  const syncOptions = options.sync ?? {};
  if (typeof options.includePages === 'boolean') {
    syncOptions.pages = options.includePages;
  }
  if (typeof options.includeContentTemplates === 'boolean') {
    syncOptions.contentTemplates = options.includeContentTemplates;
  }
  if (typeof options.includeRegions === 'boolean') {
    syncOptions.regions = options.includeRegions;
  }
  if (options.pages === false) {
    syncOptions.pages = false;
  }
  if (options.contentTemplates === false) {
    syncOptions.contentTemplates = false;
  }
  if (options.regions === false) {
    syncOptions.regions = false;
  }
  options.sync = syncOptions;
}

export function updateConfigFromOptions(options: {
  clientId?: string;
  clientSecret?: string;
  siteUrl?: string;
  dir?: string;
  scope?: string;
  sync?: Partial<CanvasSyncConfig>;
  includeBrandKit?: boolean;
  aliasBaseDir?: string;
  outputDir?: string;
}): void {
  if (options.clientId) setConfig({ clientId: options.clientId });
  if (options.clientSecret) setConfig({ clientSecret: options.clientSecret });
  if (options.siteUrl) setConfig({ siteUrl: options.siteUrl });
  if (options.dir) setConfig({ componentDir: options.dir });
  if (typeof options.sync?.pages === 'boolean') {
    setConfig({ includePages: options.sync.pages });
  }
  if (typeof options.sync?.contentTemplates === 'boolean') {
    setConfig({ includeContentTemplates: options.sync.contentTemplates });
  }
  if (typeof options.sync?.regions === 'boolean') {
    setConfig({ includeRegions: options.sync.regions });
  }
  if (typeof options.includeBrandKit === 'boolean') {
    setConfig({ includeBrandKit: options.includeBrandKit });
  }
  const currentConfig = getConfig();
  if (
    !options.scope &&
    !process.env.CANVAS_SCOPE &&
    usesManagedDefaultScope(currentConfig.scope)
  ) {
    setConfig({
      scope: getDefaultScope(
        currentConfig.includePages,
        currentConfig.includeBrandKit,
        currentConfig.includeContentTemplates,
        currentConfig.includeRegions,
      ),
    });
  }
  if (options.scope) setConfig({ scope: options.scope });
  if (options.aliasBaseDir) setConfig({ aliasBaseDir: options.aliasBaseDir });
  if (options.outputDir) setConfig({ outputDir: options.outputDir });
}

export function parseBooleanOption(value: string): boolean {
  const parsed = parseBooleanSetting(value);

  if (parsed === undefined) {
    throw new InvalidArgumentError(
      'Expected a boolean value: true, false, 1, 0, yes, or no.',
    );
  }

  return parsed;
}

/**
 * Helper to pluralize "component" based on count
 */
export function pluralizeComponent(count: number): string {
  return count === 1 ? 'component' : 'components';
}

export function pluralize(
  count: number,
  singular: string,
  plural?: string,
): string {
  return count === 1 ? singular : (plural ?? `${singular}s`);
}

/**
 * Warns about bare imports taken on trust because the site's map is unknown.
 *
 * These are not installed npm packages, so the CLI leaves them unbundled for
 * the browser to resolve against the site's import map. That is how Drupal
 * modules and themes contribute imports, but it is also what a mistyped package
 * name looks like. Without a pulled import map the two cannot be told apart, so
 * name them; once the project has pulled one, the build fails on the ones the
 * site does not resolve and there is nothing left to warn about.
 */
export function warnAboutSiteProvidedPackages(
  packages: string[],
  siteImportsVerified: boolean,
): void {
  if (packages.length === 0 || siteImportsVerified) {
    return;
  }
  p.log.warn(
    `Not bundled, expected in the site's import map: ${packages.join(', ')}. ` +
      'Run `canvas pull` to record the map so this can be checked, install the package if it should be bundled, ' +
      'or check for a typo.',
  );
}
