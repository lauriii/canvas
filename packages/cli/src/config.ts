import fs from 'fs';
import path from 'path';
import dotenv from 'dotenv';
import * as p from '@clack/prompts';
import {
  DEFAULT_CANVAS_CONFIG,
  resolveCanvasConfig,
} from '@drupal-canvas/discovery';

import type { CanvasConfigWarning } from '@drupal-canvas/discovery';

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
  includeContentTemplates: boolean;
  includeRegions: boolean;
  includeBrandKit: boolean;
  all?: boolean;
  // The following properties are loaded from canvas.config.json.
  aliasBaseDir: string;
  outputDir: string;
  componentDir: string;
  pagesDir: string;
  contentTemplatesDir: string;
  regionsDir: string;
  deprecatedComponentDir: string;
  globalCssPath: string;
  layoutPath: string;
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

const canvasConfigWarnings: CanvasConfigWarning[] = [];
const {
  aliasBaseDir,
  outputDir,
  componentDir,
  pagesDir,
  contentTemplatesDir,
  regionsDir,
  deprecatedComponentDir,
  globalCssPath,
  layoutPath,
  sync,
} = resolveCanvasConfig({
  hostRoot: process.cwd(),
  onWarning: (warning) => canvasConfigWarnings.push(warning),
});

export const DEFAULT_INCLUDE_BRAND_KIT = false;

const DEFAULT_SCOPES =
  'canvas:js_component canvas:asset_library canvas:media:image:create canvas:media:view';
const PAGE_SCOPES = 'canvas:page:create canvas:page:read canvas:page:edit';
const CONTENT_TEMPLATE_SCOPES = 'canvas:content_template';
const REGION_SCOPES = 'canvas:page_region';
const BRAND_KIT_SCOPES = 'canvas:brand_kit';

export function getDefaultScope(
  includePages: boolean,
  includeBrandKit: boolean = false,
  includeContentTemplates: boolean = false,
  includeRegions: boolean = false,
): string {
  const parts = [DEFAULT_SCOPES];
  if (includePages) parts.push(PAGE_SCOPES);
  if (includeContentTemplates) parts.push(CONTENT_TEMPLATE_SCOPES);
  if (includeRegions) parts.push(REGION_SCOPES);
  if (includeBrandKit) parts.push(BRAND_KIT_SCOPES);
  return parts.join(' ');
}

export function usesManagedDefaultScope(scope: string): boolean {
  if (scope.length === 0) return true;
  const tokens = new Set(scope.split(/\s+/).filter(Boolean));
  const baseTokens = DEFAULT_SCOPES.split(/\s+/);
  const optionalTokens = new Set(
    [
      PAGE_SCOPES,
      REGION_SCOPES,
      BRAND_KIT_SCOPES,
      CONTENT_TEMPLATE_SCOPES,
    ].flatMap((s) => s.split(/\s+/)),
  );
  for (const token of baseTokens) {
    if (!tokens.has(token)) return false;
    tokens.delete(token);
  }
  for (const token of tokens) {
    if (!optionalTokens.has(token)) return false;
  }
  return true;
}

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
function getEnvBoolean(value: string | undefined, fallback: boolean): boolean {
  if (value === undefined) {
    return fallback;
  }

  return parseBooleanSetting(value) ?? fallback;
}

const configuredSync = readConfiguredSyncSettings();

const includePages =
  configuredSync.pages ??
  getEnvBoolean(process.env.CANVAS_INCLUDE_PAGES, sync.pages);

const includeContentTemplates =
  configuredSync.contentTemplates ??
  getEnvBoolean(
    process.env.CANVAS_INCLUDE_CONTENT_TEMPLATES,
    sync.contentTemplates,
  );

const includeRegions =
  configuredSync.regions ??
  getEnvBoolean(process.env.CANVAS_INCLUDE_REGIONS, sync.regions);

const includeBrandKit = getEnvBoolean(
  process.env.CANVAS_INCLUDE_BRAND_KIT,
  DEFAULT_INCLUDE_BRAND_KIT,
);

let config: Config = {
  siteUrl: process.env.CANVAS_SITE_URL || '',
  clientId: process.env.CANVAS_CLIENT_ID || '',
  clientSecret: process.env.CANVAS_CLIENT_SECRET || '',
  scope:
    process.env.CANVAS_SCOPE ||
    getDefaultScope(
      includePages,
      includeBrandKit,
      includeContentTemplates,
      includeRegions,
    ),
  userAgent: process.env.CANVAS_USER_AGENT || '',
  includePages,
  includeContentTemplates,
  includeRegions,
  includeBrandKit,
  aliasBaseDir: aliasBaseDir,
  outputDir: outputDir,
  componentDir: componentDir,
  pagesDir: pagesDir,
  contentTemplatesDir: contentTemplatesDir,
  regionsDir: regionsDir,
  // We need this because the old commands use './components' as a default.
  deprecatedComponentDir: deprecatedComponentDir,
  globalCssPath: globalCssPath,
  layoutPath: layoutPath,
  fonts: loadFontsFromBrandKitFile(process.cwd()),
};

export function getConfig(): Config {
  return config;
}

let emittedCanvasConfigWarnings = false;

export function emitCanvasConfigWarnings(): boolean {
  if (emittedCanvasConfigWarnings) {
    return false;
  }

  emittedCanvasConfigWarnings = true;
  for (const warning of canvasConfigWarnings) {
    p.log.warn(warning.message);
  }
  return canvasConfigWarnings.length > 0;
}

export function setConfig(newConfig: Partial<Config>): void {
  config = { ...config, ...newConfig };
}

interface LegacyMigrationOptions {
  skipPrompt?: boolean;
}

interface LegacySyncEnvSetting {
  envName:
    | 'CANVAS_INCLUDE_PAGES'
    | 'CANVAS_INCLUDE_CONTENT_TEMPLATES'
    | 'CANVAS_INCLUDE_REGIONS';
  configKey: 'pages' | 'contentTemplates' | 'regions';
  configPath: 'sync.pages' | 'sync.contentTemplates' | 'sync.regions';
  setKey: 'includePages' | 'includeContentTemplates' | 'includeRegions';
}

const LEGACY_SYNC_ENV_SETTINGS: LegacySyncEnvSetting[] = [
  {
    envName: 'CANVAS_INCLUDE_PAGES',
    configKey: 'pages',
    configPath: 'sync.pages',
    setKey: 'includePages',
  },
  {
    envName: 'CANVAS_INCLUDE_CONTENT_TEMPLATES',
    configKey: 'contentTemplates',
    configPath: 'sync.contentTemplates',
    setKey: 'includeContentTemplates',
  },
  {
    envName: 'CANVAS_INCLUDE_REGIONS',
    configKey: 'regions',
    configPath: 'sync.regions',
    setKey: 'includeRegions',
  },
];

function getCanvasConfigPath(): string {
  return path.resolve(process.cwd(), 'canvas.config.json');
}

function readCanvasConfigFile(): {
  configPath: string;
  hasConfigFile: boolean;
  parsedConfig: Record<string, unknown> | null;
  configParseError: boolean;
} {
  const configPath = getCanvasConfigPath();
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

  return { configPath, hasConfigFile, parsedConfig, configParseError };
}

function readConfiguredSyncSettings(): Partial<{
  pages: boolean;
  contentTemplates: boolean;
  regions: boolean;
}> {
  const { parsedConfig, configParseError } = readCanvasConfigFile();
  if (configParseError) {
    return {};
  }
  const parsedSync = parsedConfig?.sync;
  if (
    !parsedSync ||
    typeof parsedSync !== 'object' ||
    Array.isArray(parsedSync)
  ) {
    return {};
  }

  const syncConfig = parsedSync as Record<string, unknown>;
  return {
    pages: typeof syncConfig.pages === 'boolean' ? syncConfig.pages : undefined,
    contentTemplates:
      typeof syncConfig.contentTemplates === 'boolean'
        ? syncConfig.contentTemplates
        : undefined,
    regions:
      typeof syncConfig.regions === 'boolean' ? syncConfig.regions : undefined,
  };
}

function writeCanvasConfigFile(
  configPath: string,
  configContent: Record<string, unknown>,
): void {
  fs.writeFileSync(
    configPath,
    `${JSON.stringify(configContent, null, 2)}\n`,
    'utf-8',
  );
}

export async function handleLegacySyncEnvMigration(
  options: LegacyMigrationOptions = {},
): Promise<boolean> {
  const legacyValues = LEGACY_SYNC_ENV_SETTINGS.map((setting) => {
    const value = process.env[setting.envName];
    return {
      ...setting,
      value,
      parsed: value === undefined ? undefined : parseBooleanSetting(value),
    };
  }).filter((setting) => setting.value !== undefined);

  if (legacyValues.length === 0) {
    return false;
  }

  const { configPath, parsedConfig, configParseError } = readCanvasConfigFile();
  const existingSync =
    parsedConfig?.sync &&
    typeof parsedConfig.sync === 'object' &&
    !Array.isArray(parsedConfig.sync)
      ? (parsedConfig.sync as Record<string, unknown>)
      : {};

  for (const setting of legacyValues) {
    p.log.warn(
      `${setting.envName} is deprecated. Set "${setting.configPath}" in canvas.config.json instead.`,
    );
    if (
      setting.parsed !== undefined &&
      typeof existingSync[setting.configKey] !== 'boolean'
    ) {
      setConfig({ [setting.setKey]: setting.parsed });
    }
  }

  if (configParseError) {
    p.log.warn(
      'canvas.config.json exists but is invalid. Deprecated sync environment settings will apply for this run only. Fix canvas.config.json if you want to persist them.',
    );
    return true;
  }

  const missingSettings = legacyValues.filter(
    (setting) =>
      setting.parsed !== undefined &&
      typeof existingSync[setting.configKey] !== 'boolean',
  );

  if (missingSettings.length === 0) {
    return true;
  }

  const additions = missingSettings
    .map((setting) => `"${setting.configPath}": ${String(setting.parsed)}`)
    .join(', ');

  if (options.skipPrompt) {
    p.log.info(
      `Add ${additions} to canvas.config.json to persist this setting.`,
    );
    return true;
  }

  const confirmed = await p.confirm({
    message: `Sync settings are now managed in canvas.config.json. Move these deprecated environment settings there? (${additions})`,
    initialValue: true,
  });

  if (p.isCancel(confirmed) || !confirmed) {
    return true;
  }

  const nextSync = { ...existingSync };
  for (const setting of missingSettings) {
    nextSync[setting.configKey] = setting.parsed;
  }
  const nextConfig = { ...(parsedConfig ?? {}), sync: nextSync };
  writeCanvasConfigFile(configPath, nextConfig);
  p.log.info('Updated canvas.config.json with sync settings.');
  return true;
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
): Promise<boolean> {
  const { configPath, hasConfigFile, parsedConfig, configParseError } =
    readCanvasConfigFile();

  const hasComponentDirConfig =
    typeof parsedConfig?.componentDir === 'string' &&
    parsedConfig.componentDir.trim().length > 0;

  if (hasComponentDirConfig) {
    return false;
  }

  const legacyComponentDir =
    process.env.CANVAS_COMPONENT_DIR?.trim() ||
    DEFAULT_CANVAS_CONFIG.componentDir;

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
    return true;
  }

  if (options.skipPrompt) {
    p.log.info(
      `Add "componentDir": "${legacyComponentDir}" to canvas.config.json to persist this setting.`,
    );
    return true;
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

  writeCanvasConfigFile(configPath, nextConfig);
  p.log.info('Updated canvas.config.json with componentDir.');
  setConfig({ componentDir });
  return true;
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
        placeholder: 'components',
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
