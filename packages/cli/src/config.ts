import fs from 'fs';
import path from 'path';
import dotenv from 'dotenv';
import * as p from '@clack/prompts';
import { resolveCanvasConfig } from '@drupal-canvas/discovery';

// Load environment variables.
export function loadEnvFiles() {
  // Load from the user's home directory (for global settings).
  const homeDir = process.env.HOME || process.env.USERPROFILE || '';
  if (homeDir) {
    const homeEnvPath = path.resolve(homeDir, '.canvasrc');
    if (fs.existsSync(homeEnvPath)) {
      dotenv.config({ path: homeEnvPath });
    }
  }
  // Then load from the current directory so the local .env file takes precedence.
  const localEnvPath = path.resolve(process.cwd(), '.env');
  if (fs.existsSync(localEnvPath)) {
    dotenv.config({ path: localEnvPath });
  }
}

// Load environment variables before creating config.
loadEnvFiles();

/** Defaults for provider-based font resolution (weights, styles, subsets). */
export interface FontDefaults {
  weights?: string[];
  styles?: string[];
  subsets?: string[];
}

/** Provider-specific options (e.g. Adobe kit ID). */
export interface FontProviderOptions {
  adobe?: { id: string[] };
}

/** Per-axis default value override (axis tag -> number). Used for variable fonts. */
export type FontAxisDefaults = Record<string, number>;

/** Shared fields for all font family entries. */
interface FontFamilyEntryBase {
  name: string;
  weights?: string[];
  styles?: string[];
  /** Optional axis default overrides for variable fonts (e.g. { "wght": 500 }). Clamped to axis min/max. */
  axisDefaults?: FontAxisDefaults;
}

/** Font family entry for a local file. */
export interface LocalFontFamilyEntry extends FontFamilyEntryBase {
  src: string;
  provider?: never;
  subsets?: never;
}

/** Font family entry for a provider-based font. */
export interface ProviderFontFamilyEntry extends FontFamilyEntryBase {
  provider?: 'google' | 'bunny' | 'fontshare' | 'fontsource' | 'npm' | 'adobe';
  src?: never;
  /** Subsets to request from the provider (e.g. ['latin', 'cyrillic']). */
  subsets?: string[];
}

/** A single font family entry in canvas.brand-kit.json families. */
export type FontFamilyEntry = LocalFontFamilyEntry | ProviderFontFamilyEntry;

export interface FontsConfig {
  defaults?: FontDefaults;
  families: FontFamilyEntry[];
  providers?: FontProviderOptions;
}

export interface Config {
  siteUrl: string;
  clientId: string;
  clientSecret: string;
  scope: string;
  userAgent: string;
  includePages: boolean;
  includeBrandKit: boolean;
  all?: boolean;
  // The following properties are loaded from canvas.config.json.
  aliasBaseDir: string;
  outputDir: string;
  componentDir: string;
  pagesDir: string;
  deprecatedComponentDir: string;
  globalCssPath: string;
  fonts?: FontsConfig;
}

/** Filename for Brand Kit (font) configuration in the project root. */
export const BRAND_KIT_CONFIG_FILENAME = 'canvas.brand-kit.json';

/** Global Brand Kit id used by the CLI for font sync (single site-wide kit). */
export const BRAND_KIT_GLOBAL_ID = 'global';

/** Top-level shape of canvas.brand-kit.json (fonts and future brand kit keys). */
export interface BrandKitConfigFile {
  fonts?: FontsConfig;
}

function loadFontsFromBrandKitFile(hostRoot: string): FontsConfig | undefined {
  const configPath = path.resolve(hostRoot, BRAND_KIT_CONFIG_FILENAME);
  if (!fs.existsSync(configPath)) {
    return undefined;
  }
  const raw = fs.readFileSync(configPath, 'utf-8');
  let parsed: BrandKitConfigFile;
  try {
    parsed = JSON.parse(raw) as BrandKitConfigFile;
  } catch (err) {
    const message =
      err instanceof SyntaxError
        ? err.message
        : err instanceof Error
          ? err.message
          : String(err);
    throw new Error(`Invalid JSON in ${BRAND_KIT_CONFIG_FILENAME}: ${message}`);
  }
  const fonts = parsed?.fonts;
  if (fonts && typeof fonts === 'object' && Array.isArray(fonts.families)) {
    return fonts;
  }
  return undefined;
}

const {
  aliasBaseDir,
  outputDir,
  componentDir,
  pagesDir,
  deprecatedComponentDir,
  globalCssPath,
} = resolveCanvasConfig({ hostRoot: process.cwd() });

export const DEFAULT_INCLUDE_PAGES = false;
export const DEFAULT_INCLUDE_BRAND_KIT = false;

const DEFAULT_SCOPE =
  'canvas:js_component canvas:asset_library canvas:media:image:create canvas:media:view';
const DEFAULT_SCOPE_WITH_PAGES = `${DEFAULT_SCOPE} canvas:page:create canvas:page:read canvas:page:edit`;
const DEFAULT_SCOPE_WITH_BRAND_KIT = `${DEFAULT_SCOPE} canvas:brand_kit`;
const DEFAULT_SCOPE_WITH_PAGES_AND_BRAND_KIT = `${DEFAULT_SCOPE_WITH_PAGES} canvas:brand_kit`;

export function parseBooleanSetting(value: string): boolean | undefined {
  const normalizedValue = value.trim().toLowerCase();

  if (['1', 'true', 'yes', 'on'].includes(normalizedValue)) {
    return true;
  }

  if (['0', 'false', 'no', 'off'].includes(normalizedValue)) {
    return false;
  }

  return undefined;
}

export function getDefaultScope(
  includePages: boolean,
  includeBrandKit: boolean = false,
): string {
  if (includePages && includeBrandKit) {
    return DEFAULT_SCOPE_WITH_PAGES_AND_BRAND_KIT;
  }
  if (includePages) {
    return DEFAULT_SCOPE_WITH_PAGES;
  }
  if (includeBrandKit) {
    return DEFAULT_SCOPE_WITH_BRAND_KIT;
  }
  return DEFAULT_SCOPE;
}

export function usesManagedDefaultScope(scope: string): boolean {
  return (
    scope.length === 0 ||
    scope === DEFAULT_SCOPE ||
    scope === DEFAULT_SCOPE_WITH_PAGES ||
    scope === DEFAULT_SCOPE_WITH_BRAND_KIT ||
    scope === DEFAULT_SCOPE_WITH_PAGES_AND_BRAND_KIT
  );
}

function getEnvBoolean(value: string | undefined, fallback: boolean): boolean {
  if (value === undefined) {
    return fallback;
  }

  return parseBooleanSetting(value) ?? fallback;
}

const includePages = getEnvBoolean(
  process.env.CANVAS_INCLUDE_PAGES,
  DEFAULT_INCLUDE_PAGES,
);

const includeBrandKit = getEnvBoolean(
  process.env.CANVAS_INCLUDE_BRAND_KIT,
  DEFAULT_INCLUDE_BRAND_KIT,
);

let config: Config = {
  siteUrl: process.env.CANVAS_SITE_URL || '',
  clientId: process.env.CANVAS_CLIENT_ID || '',
  clientSecret: process.env.CANVAS_CLIENT_SECRET || '',
  scope:
    process.env.CANVAS_SCOPE || getDefaultScope(includePages, includeBrandKit),
  userAgent: process.env.CANVAS_USER_AGENT || '',
  includePages,
  includeBrandKit,
  aliasBaseDir: aliasBaseDir,
  outputDir: outputDir,
  componentDir: componentDir,
  pagesDir: pagesDir,
  // We need this because the old commands use './components' as a default
  // but the new componentDir that supports flexible codebases defaults to process.cwd().
  deprecatedComponentDir: deprecatedComponentDir,
  globalCssPath: globalCssPath,
  fonts: loadFontsFromBrandKitFile(process.cwd()),
};

export function getConfig(): Config {
  return config;
}

export function setConfig(newConfig: Partial<Config>): void {
  config = { ...config, ...newConfig };
}

interface LegacyMigrationOptions {
  skipPrompt?: boolean;
}

/**
 * Ensures that canvas.config.json has a componentDir defined.
 *
 * Resolution order:
 * 1. canvas.config.json has componentDir — done
 * 2. CANVAS_COMPONENT_DIR env var — use it with deprecation warning, offer to persist
 * 3. None — prompt to create canvas.config.json (or show instructions if non-interactive)
 */
export async function handleLegacyComponentDirMigration(
  options: LegacyMigrationOptions = {},
): Promise<void> {
  const configPath = path.resolve(process.cwd(), 'canvas.config.json');
  const hasConfigFile = fs.existsSync(configPath);

  let parsedConfig: Record<string, unknown> | null = null;
  let configParseError = false;

  if (hasConfigFile) {
    try {
      const raw = fs.readFileSync(configPath, 'utf-8');
      const parsed = JSON.parse(raw) as unknown;

      if (parsed && typeof parsed === 'object' && !Array.isArray(parsed)) {
        parsedConfig = parsed as Record<string, unknown>;
      } else {
        configParseError = true;
      }
    } catch {
      configParseError = true;
    }
  }

  const hasComponentDirConfig =
    typeof parsedConfig?.componentDir === 'string' &&
    parsedConfig.componentDir.trim().length > 0;

  if (hasComponentDirConfig) {
    return;
  }

  const legacyComponentDir =
    process.env.CANVAS_COMPONENT_DIR?.trim() || 'src/components';

  if (process.env.CANVAS_COMPONENT_DIR) {
    p.log.warn(
      'CANVAS_COMPONENT_DIR is deprecated. Set "componentDir" in canvas.config.json instead.',
    );
    // Preserve behavior for the current run.
    setConfig({
      componentDir: legacyComponentDir,
      deprecatedComponentDir: legacyComponentDir,
    });
  }

  if (configParseError) {
    p.log.warn(
      'canvas.config.json exists but is invalid. Update it manually by adding a componentDir key.',
    );
    return;
  }

  if (options.skipPrompt) {
    p.log.info(
      `Add "componentDir": "${legacyComponentDir}" to canvas.config.json to persist this setting.`,
    );
    return;
  }

  const componentDir = await p.text({
    message: hasConfigFile
      ? 'canvas.config.json is missing "componentDir". Enter the component directory:'
      : 'No canvas.config.json found. Enter the component directory:',
    defaultValue: legacyComponentDir,
    placeholder: legacyComponentDir,
  });

  if (p.isCancel(componentDir)) {
    p.cancel(
      'No component directory configured. Use --dir <directory> or set "componentDir" in canvas.config.json.',
    );
    process.exit(1);
  }

  const nextConfig = hasConfigFile
    ? { ...(parsedConfig ?? {}), componentDir }
    : { componentDir };

  fs.writeFileSync(
    configPath,
    `${JSON.stringify(nextConfig, null, 2)}\n`,
    'utf-8',
  );
  p.log.info('Updated canvas.config.json with componentDir.');
  setConfig({ componentDir });
}

export type ConfigKey = keyof Config;

export async function ensureConfig(requiredKeys: ConfigKey[]): Promise<void> {
  const config = getConfig();
  const missingKeys = requiredKeys.filter((key) => !config[key]);

  for (const key of missingKeys) {
    await promptForConfig(key);
  }
}

export async function promptForConfig(key: ConfigKey): Promise<void> {
  switch (key) {
    case 'siteUrl': {
      const value = await p.text({
        message: 'Enter the site URL',
        placeholder: 'https://example.com',
        validate: (value) => {
          if (!value) return 'Site URL is required';
          if (!value.startsWith('http'))
            return 'URL must start with http:// or https://';
          return;
        },
      });

      if (p.isCancel(value)) {
        p.cancel('Operation cancelled');
        process.exit(0);
      }

      setConfig({ siteUrl: value });
      break;
    }

    case 'clientId': {
      const value = await p.text({
        message: 'Enter your client ID',
        validate: (value) => {
          if (!value) return 'Client ID is required';
          return;
        },
      });

      if (p.isCancel(value)) {
        p.cancel('Operation cancelled');
        process.exit(0);
      }

      setConfig({ clientId: value });
      break;
    }

    case 'clientSecret': {
      const value = await p.password({
        message: 'Enter your client secret',
        validate: (value) => {
          if (!value) return 'Client secret is required';
          return;
        },
      });

      if (p.isCancel(value)) {
        p.cancel('Operation cancelled');
        process.exit(0);
      }

      setConfig({ clientSecret: value });
      break;
    }

    case 'componentDir': {
      const value = await p.text({
        message: 'Enter the component directory',
        placeholder: './components',
        validate: (value) => {
          if (!value) return 'Component directory is required';
          return;
        },
      });

      if (p.isCancel(value)) {
        p.cancel('Operation cancelled');
        process.exit(0);
      }

      setConfig({ componentDir: value });
      break;
    }
  }
}
