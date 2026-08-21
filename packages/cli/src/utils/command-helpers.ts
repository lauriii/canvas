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
  pages?: boolean;
  contentTemplates?: boolean;
  pageTemplates?: boolean;
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
  const syncOptions = options.sync ?? {};
  if (typeof options.includePages === 'boolean') {
    syncOptions.pages = options.includePages;
  }
  if (typeof options.includeContentTemplates === 'boolean') {
    syncOptions.contentTemplates = options.includeContentTemplates;
  }
  if (options.pages === false) {
    syncOptions.pages = false;
  }
  if (options.contentTemplates === false) {
    syncOptions.contentTemplates = false;
  }
  if (options.pageTemplates === false) {
    syncOptions.pageTemplates = false;
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
  if (typeof options.sync?.pageTemplates === 'boolean') {
    setConfig({ includePageTemplates: options.sync.pageTemplates });
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
        currentConfig.includePageTemplates,
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
 * Names packages installed at another version than the site's extension
 * declares.
 *
 * The component is bundled with the installed copy, but the site's module was
 * written for the declared one, so the component may behave differently on
 * the site than in a local preview. This does not fail the build: a deliberate
 * upgrade ahead of the module is legitimate, and `canvas pull` reports the
 * same disagreement.
 */
export function warnAboutNpmDependencyMismatches(
  mismatches: { name: string; declared: string; installed: string }[],
): void {
  if (mismatches.length === 0) {
    return;
  }
  p.log.warn(
    "Installed at a different version than the site's modules declare: " +
      mismatches
        .map(
          ({ name, declared, installed }) =>
            `${name} (installed ${installed}, site declares ${declared})`,
        )
        .join(', ') +
      '. Run `npm install` after `canvas pull`, or align package.json by hand.',
  );
}
