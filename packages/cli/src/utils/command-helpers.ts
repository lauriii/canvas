import { InvalidArgumentError } from 'commander';

import {
  getConfig,
  getDefaultScope,
  parseBooleanSetting,
  setConfig,
  usesManagedDefaultScope,
} from '../config';

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
export function updateConfigFromOptions(options: {
  clientId?: string;
  clientSecret?: string;
  siteUrl?: string;
  dir?: string;
  scope?: string;
  includePages?: boolean;
  includeContentTemplates?: boolean;
  includeRegions?: boolean;
  includeBrandKit?: boolean;
  all?: boolean;
  aliasBaseDir?: string;
  outputDir?: string;
}): void {
  if (options.clientId) setConfig({ clientId: options.clientId });
  if (options.clientSecret) setConfig({ clientSecret: options.clientSecret });
  if (options.siteUrl) setConfig({ siteUrl: options.siteUrl });
  if (options.dir) setConfig({ componentDir: options.dir });
  if (typeof options.includePages === 'boolean') {
    setConfig({ includePages: options.includePages });
  }
  if (typeof options.includeContentTemplates === 'boolean') {
    setConfig({ includeContentTemplates: options.includeContentTemplates });
  }
  if (typeof options.includeRegions === 'boolean') {
    setConfig({ includeRegions: options.includeRegions });
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
