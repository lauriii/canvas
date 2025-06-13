import dotenv from 'dotenv';
import path from 'path';
import fs from 'fs';

// Load environment variables.
function loadEnvFiles() {
  // Load from the user's home directory (for global settings).
  const homeDir = process.env.HOME || process.env.USERPROFILE || '';
  if (homeDir) {
    const homeEnvPath = path.resolve(homeDir, '.xbrc');
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
  site_url: string;
  auth_token: string;
  framework: 'react' | 'vue' | 'unknown';
  component_dir: string;
  verbose: boolean;
  all?: boolean;
}

let config: Config = {
  site_url: process.env.EXPERIENCE_BUILDER_SITE_URL || '',
  auth_token: process.env.EXPERIENCE_BUILDER_AUTH_TOKEN || '',
  framework:
    (process.env.EXPERIENCE_BUILDER_FRAMEWORK as 'react' | 'vue') || 'react',
  component_dir: process.env.EXPERIENCE_BUILDER_COMPONENT_DIR || './components',
  verbose: process.env.EXPERIENCE_BUILDER_VERBOSE === 'true',
};

export function getConfig(): Config {
  return config;
}

export function setConfig(newConfig: Partial<Config>): void {
  config = { ...config, ...newConfig };
}
