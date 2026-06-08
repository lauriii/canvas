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
 * Magic string constant for "all components" selector
 */
export const ALL_COMPONENTS_SELECTOR = '_allComponents';

/**
 * Validates that --all and --components options are not used together
 */
export function validateComponentOptions(options: {
  components?: string;
  all?: boolean;
}): void {
  if (options.components && options.all) {
    throw new Error(
      'Cannot use --all and --components options together. Please use either:\n   • --components to specify specific components, or\n   • --all to process everything.',
    );
  }
}

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
  all?: boolean;
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
  if (options.all) setConfig({ all: options.all });
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
