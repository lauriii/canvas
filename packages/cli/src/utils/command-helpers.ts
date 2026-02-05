import { setConfig } from '../config';

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
  all?: boolean;
}): void {
  if (options.clientId) setConfig({ clientId: options.clientId });
  if (options.clientSecret) setConfig({ clientSecret: options.clientSecret });
  if (options.siteUrl) setConfig({ siteUrl: options.siteUrl });
  if (options.dir) setConfig({ componentDir: options.dir });
  if (options.scope) setConfig({ scope: options.scope });
  if (options.all) setConfig({ all: options.all });
}

/**
 * Helper to pluralize "component" based on count
 */
export function pluralizeComponent(count: number): string {
  return count === 1 ? 'component' : 'components';
}
