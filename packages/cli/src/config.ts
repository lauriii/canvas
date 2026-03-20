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

export interface Config {
  siteUrl: string;
  clientId: string;
  clientSecret: string;
  scope: string;
  userAgent: string;
  all?: boolean;
  // The following properties are loaded from canvas.config.json.
  aliasBaseDir: string;
  outputDir: string;
  componentDir: string;
  pagesDir: string;
  deprecatedComponentDir: string;
  globalCssPath: string;
}

const {
  aliasBaseDir,
  outputDir,
  componentDir,
  pagesDir,
  deprecatedComponentDir,
  globalCssPath,
} = resolveCanvasConfig({ hostRoot: process.cwd() });

let config: Config = {
  siteUrl: process.env.CANVAS_SITE_URL || '',
  clientId: process.env.CANVAS_CLIENT_ID || '',
  clientSecret: process.env.CANVAS_CLIENT_SECRET || '',
  scope: process.env.CANVAS_SCOPE || 'canvas:js_component canvas:asset_library',
  userAgent: process.env.CANVAS_USER_AGENT || '',
  aliasBaseDir: aliasBaseDir,
  outputDir: outputDir,
  componentDir: componentDir,
  pagesDir: pagesDir,
  // We need this because the old commands use './components' as a default
  // but the new componentDir that supports flexible codebases defaults to process.cwd().
  deprecatedComponentDir: deprecatedComponentDir,
  globalCssPath: globalCssPath,
};

export function getConfig(): Config {
  return config;
}

export function setConfig(newConfig: Partial<Config>): void {
  config = { ...config, ...newConfig };
}

let legacyComponentDirMigrationHandled = false;

interface LegacyMigrationOptions {
  skipPrompt?: boolean;
}

/**
 * Detects legacy CANVAS_COMPONENT_DIR usage and helps users migrate to
 * canvas.config.json.
 */
export async function handleLegacyComponentDirMigration(
  options: LegacyMigrationOptions = {},
): Promise<void> {
  if (legacyComponentDirMigrationHandled) {
    return;
  }
  legacyComponentDirMigrationHandled = true;

  const legacyComponentDir = process.env.CANVAS_COMPONENT_DIR?.trim();
  if (!legacyComponentDir) {
    return;
  }

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

  p.log.warn(
    'CANVAS_COMPONENT_DIR is deprecated for component directory configuration. Use componentDir in canvas.config.json instead.',
  );

  // Preserve behavior for the current run while migration is in progress.
  setConfig({
    componentDir: legacyComponentDir,
    deprecatedComponentDir: legacyComponentDir,
  });

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

  const shouldWriteConfig = await p.confirm({
    message: hasConfigFile
      ? `Add "componentDir": "${legacyComponentDir}" to canvas.config.json?`
      : `Create canvas.config.json with "componentDir": "${legacyComponentDir}"?`,
    initialValue: true,
  });

  if (p.isCancel(shouldWriteConfig) || !shouldWriteConfig) {
    p.log.info(
      `Skipped config update. You can set "componentDir": "${legacyComponentDir}" in canvas.config.json later.`,
    );
    return;
  }

  const nextConfig = hasConfigFile
    ? { ...(parsedConfig ?? {}), componentDir: legacyComponentDir }
    : { componentDir: legacyComponentDir };

  fs.writeFileSync(
    configPath,
    `${JSON.stringify(nextConfig, null, 2)}\n`,
    'utf-8',
  );
  p.log.info('Updated canvas.config.json with componentDir.');
}

export function resetLegacyComponentDirMigrationForTests(): void {
  legacyComponentDirMigrationHandled = false;
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
