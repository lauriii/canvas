import * as fs from 'fs';
import * as path from 'path';
import * as dotenv from 'dotenv';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import * as p from '@clack/prompts';

import {
  ensureConfig,
  getConfig,
  handleLegacyComponentDirMigration,
  loadEnvFiles,
  promptForConfig,
  setConfig,
} from './config';

vi.mock('fs');
vi.mock('path');
vi.mock('dotenv');
vi.mock('@clack/prompts');

describe('config', () => {
  describe('get/set', () => {
    beforeEach(() => {
      vi.clearAllMocks();
      vi.resetAllMocks();
      setConfig({
        siteUrl: '',
        clientId: '',
        clientSecret: '',
        includePages: false,
        fonts: undefined,
        componentDir: './components',
      });
    });

    it('should return default config values', () => {
      const config = getConfig();
      expect(config).toEqual({
        aliasBaseDir: 'src',
        clientId: '',
        clientSecret: '',
        componentDir: './components',
        deprecatedComponentDir: './components',
        fonts: undefined,
        globalCssPath: './src/components/global.css',
        includePages: false,
        includeBrandKit: false,
        outputDir: 'dist',
        pagesDir: './pages',
        scope:
          'canvas:js_component canvas:asset_library canvas:media:image:create canvas:media:view',
        siteUrl: '',
        userAgent: '',
      });
    });

    it('should update config values', () => {
      setConfig({
        siteUrl: 'https://example.com',
        clientId: 'test-client',
      });
      expect(getConfig()).toEqual({
        aliasBaseDir: 'src',
        clientId: 'test-client',
        clientSecret: '',
        componentDir: './components',
        deprecatedComponentDir: './components',
        fonts: undefined,
        globalCssPath: './src/components/global.css',
        includePages: false,
        includeBrandKit: false,
        outputDir: 'dist',
        pagesDir: './pages',
        scope:
          'canvas:js_component canvas:asset_library canvas:media:image:create canvas:media:view',
        siteUrl: 'https://example.com',
        userAgent: '',
      });
    });
  });

  describe('ensure', () => {
    it('should not prompt if all required keys are present', async () => {
      setConfig({
        siteUrl: 'https://example.com',
        clientId: 'test-client',
        clientSecret: 'test-secret',
        includePages: false,
        componentDir: './components',
      });

      await ensureConfig(['siteUrl', 'clientId', 'clientSecret']);
      expect(p.text).not.toHaveBeenCalled();
      expect(p.password).not.toHaveBeenCalled();
    });

    it('should prompt for missing required keys', async () => {
      setConfig({
        siteUrl: '',
        clientId: '',
        clientSecret: '',
      });
      await ensureConfig(['siteUrl', 'clientId', 'clientSecret']);
      expect(p.text).toHaveBeenCalledTimes(2);
      expect(p.password).toHaveBeenCalledTimes(1);
    });
  });

  describe('prompt', () => {
    it('should validate site URL', async () => {
      await promptForConfig('siteUrl');

      expect(p.text).toHaveBeenCalledWith({
        message: 'Enter the site URL',
        placeholder: 'https://example.com',
        validate: expect.any(Function),
      });

      // Get the validate function that was passed to p.text()
      const validateFn = vi.mocked(p.text).mock.calls[0][0].validate as (
        value: string,
      ) => string | undefined;

      expect(validateFn('invalid-url')).toBe(
        'URL must start with http:// or https://',
      );
      expect(validateFn('https://example.com')).toBeUndefined();
      expect(validateFn('')).toBe('Site URL is required');
    });

    it('should validate client ID', async () => {
      await promptForConfig('clientId');

      expect(p.text).toHaveBeenCalledWith({
        message: 'Enter your client ID',
        validate: expect.any(Function),
      });

      // Get the validate function that was passed to p.text()
      const validateFn = vi.mocked(p.text).mock.calls[0][0].validate as (
        value: string,
      ) => string | undefined;

      expect(validateFn('')).toBe('Client ID is required');
      expect(validateFn('test-client')).toBeUndefined();
    });

    it('should validate client secret', async () => {
      await promptForConfig('clientSecret');

      expect(p.password).toHaveBeenCalledWith({
        message: 'Enter your client secret',
        validate: expect.any(Function),
      });

      const validateFn = vi.mocked(p.password).mock.calls[0][0].validate as (
        value: string,
      ) => string | undefined;

      expect(validateFn('')).toBe('Client secret is required');
      expect(validateFn('test-secret')).toBeUndefined();
    });

    it('should validate component directory', async () => {
      await promptForConfig('componentDir');

      expect(p.text).toHaveBeenCalledWith({
        message: 'Enter the component directory',
        placeholder: './components',
        validate: expect.any(Function),
      });

      const validateFn = vi.mocked(p.text).mock.calls[0][0].validate as (
        value: string,
      ) => string | undefined;

      expect(validateFn('')).toBe('Component directory is required');
      expect(validateFn('test-dir')).toBeUndefined();
    });

    it('should handle cancelled prompts', async () => {
      vi.mocked(p.isCancel).mockReturnValue(true);
      vi.mocked(p.text).mockResolvedValue('cancelled');

      await expect(ensureConfig(['siteUrl'])).rejects.toThrow(
        'process.exit unexpectedly called with "0"',
      );
    });
  });

  describe('load env', () => {
    beforeEach(() => {
      vi.resetModules();
      vi.unstubAllEnvs();
      vi.stubEnv('HOME', '/home/user');
    });

    it('should load from home directory .canvasrc file only', async () => {
      const mockHomeEnvPath = '/home/user/.canvasrc';
      const mockLocalEnvPath = '/current/dir/.env';
      vi.mocked(path.resolve)
        .mockReturnValueOnce(mockHomeEnvPath)
        .mockReturnValueOnce(mockLocalEnvPath);
      vi.mocked(fs.existsSync)
        .mockReturnValueOnce(true)
        .mockReturnValueOnce(false);

      loadEnvFiles();
      expect(dotenv.config).toHaveBeenCalledExactlyOnceWith({
        path: mockHomeEnvPath,
      });
    });

    it('should load from local .env file only', async () => {
      const mockHomeEnvPath = '/home/user/.canvasrc';
      const mockLocalEnvPath = '/current/dir/.env';
      vi.mocked(path.resolve)
        .mockReturnValueOnce(mockHomeEnvPath)
        .mockReturnValueOnce(mockLocalEnvPath);
      vi.mocked(fs.existsSync)
        .mockReturnValueOnce(false)
        .mockReturnValueOnce(true);

      loadEnvFiles();
      expect(dotenv.config).toHaveBeenCalledWith({ path: mockLocalEnvPath });
    });

    it('should give precedence to local .env over home .canvasrc', async () => {
      const mockHomeEnvPath = '/home/user/.canvasrc';
      const mockLocalEnvPath = '/current/dir/.env';
      vi.mocked(path.resolve)
        .mockReturnValueOnce(mockHomeEnvPath)
        .mockReturnValueOnce(mockLocalEnvPath);
      vi.mocked(fs.existsSync).mockReturnValue(true);

      loadEnvFiles();
      expect(dotenv.config).toHaveBeenCalledTimes(2);
      expect(dotenv.config).toHaveBeenLastCalledWith({
        path: mockLocalEnvPath,
      });
    });

    it('should initialize config with environment variables', async () => {
      vi.stubEnv('CANVAS_SITE_URL', 'https://test.example.com');
      vi.stubEnv('CANVAS_CLIENT_ID', 'test-client');
      vi.stubEnv('CANVAS_CLIENT_SECRET', 'test-secret');
      vi.stubEnv(
        'CANVAS_SCOPE',
        'canvas:js_component canvas:asset_library canvas:media:image:create canvas:media:view canvas:page:create canvas:page:read canvas:page:edit',
      );
      vi.stubEnv('CANVAS_INCLUDE_PAGES', 'true');
      vi.stubEnv('CANVAS_USER_AGENT', 'simpletest123456');

      // Re-import config to trigger initialization
      const { getConfig } = await import('./config');

      expect(getConfig()).toEqual({
        aliasBaseDir: 'src',
        clientId: 'test-client',
        clientSecret: 'test-secret',
        componentDir: process.cwd(),
        deprecatedComponentDir: './components',
        fonts: undefined,
        globalCssPath: './src/components/global.css',
        includePages: true,
        includeBrandKit: false,
        outputDir: 'dist',
        pagesDir: './pages',
        scope:
          'canvas:js_component canvas:asset_library canvas:media:image:create canvas:media:view canvas:page:create canvas:page:read canvas:page:edit',
        siteUrl: 'https://test.example.com',
        userAgent: 'simpletest123456',
      });
    });

    it('should use default config values when no environment files exist', async () => {
      vi.mocked(fs.existsSync).mockReturnValue(false);

      // Re-import config to trigger initialization
      const { getConfig } = await import('./config');

      expect(getConfig()).toEqual({
        aliasBaseDir: 'src',
        siteUrl: '',
        clientId: '',
        clientSecret: '',
        includeBrandKit: false,
        includePages: false,
        scope:
          'canvas:js_component canvas:asset_library canvas:media:image:create canvas:media:view',
        componentDir: process.cwd(),
        deprecatedComponentDir: './components',
        fonts: undefined,
        globalCssPath: './src/components/global.css',
        outputDir: 'dist',
        pagesDir: './pages',
        userAgent: '',
      });
    });

    it('should enable page scopes when CANVAS_INCLUDE_PAGES is true', async () => {
      vi.stubEnv('CANVAS_INCLUDE_PAGES', 'true');

      const { getConfig } = await import('./config');

      expect(getConfig().includePages).toBe(true);
      expect(getConfig().scope).toBe(
        'canvas:js_component canvas:asset_library canvas:media:image:create canvas:media:view canvas:page:create canvas:page:read canvas:page:edit',
      );
    });
  });

  describe('legacy componentDir migration', () => {
    beforeEach(() => {
      vi.clearAllMocks();
      vi.unstubAllEnvs();
      vi.mocked(path.resolve).mockReturnValue(
        '/current/dir/canvas.config.json',
      );
      vi.mocked(p.isCancel).mockReturnValue(false);
    });

    it('should skip when componentDir already exists in canvas.config.json', async () => {
      vi.mocked(fs.existsSync).mockReturnValue(true);
      vi.mocked(fs.readFileSync).mockReturnValue(
        JSON.stringify({ componentDir: './components' }),
      );

      await handleLegacyComponentDirMigration();

      expect(p.confirm).not.toHaveBeenCalled();
      expect(fs.writeFileSync).not.toHaveBeenCalled();
    });

    it('should use legacy env var as default and write to config', async () => {
      vi.stubEnv('CANVAS_COMPONENT_DIR', './legacy-components');
      vi.mocked(fs.existsSync).mockReturnValue(false);
      vi.mocked(p.text).mockResolvedValue('./legacy-components');

      await handleLegacyComponentDirMigration();

      expect(p.log.warn).toHaveBeenCalledWith(
        'CANVAS_COMPONENT_DIR is deprecated. Set "componentDir" in canvas.config.json instead.',
      );
      expect(p.text).toHaveBeenCalledWith(
        expect.objectContaining({
          defaultValue: './legacy-components',
        }),
      );
      expect(fs.writeFileSync).toHaveBeenCalledTimes(1);
      const writeContent = vi.mocked(fs.writeFileSync).mock
        .calls[0][1] as string;
      expect(JSON.parse(writeContent)).toEqual({
        componentDir: './legacy-components',
      });
      expect(getConfig().componentDir).toBe('./legacy-components');
      expect(getConfig().deprecatedComponentDir).toBe('./legacy-components');
    });

    it('should prompt with default when no env var set', async () => {
      vi.mocked(fs.existsSync).mockReturnValue(false);
      vi.mocked(p.text).mockResolvedValue('src/components');

      await handleLegacyComponentDirMigration();

      expect(p.text).toHaveBeenCalledWith(
        expect.objectContaining({
          defaultValue: 'src/components',
        }),
      );
      expect(fs.writeFileSync).toHaveBeenCalledTimes(1);
      const writeContent = vi.mocked(fs.writeFileSync).mock
        .calls[0][1] as string;
      expect(JSON.parse(writeContent)).toEqual({
        componentDir: 'src/components',
      });
      expect(getConfig().componentDir).toBe('src/components');
    });

    it('should extend existing canvas.config.json when missing componentDir', async () => {
      vi.mocked(fs.existsSync).mockReturnValue(true);
      vi.mocked(fs.readFileSync).mockReturnValue(
        JSON.stringify({ aliasBaseDir: 'src' }),
      );
      vi.mocked(p.text).mockResolvedValue('src/components');

      await handleLegacyComponentDirMigration();

      expect(p.text).toHaveBeenCalledWith(
        expect.objectContaining({
          message: expect.stringContaining('missing "componentDir"'),
        }),
      );
      const writeContent = vi.mocked(fs.writeFileSync).mock
        .calls[0][1] as string;
      expect(JSON.parse(writeContent)).toEqual({
        aliasBaseDir: 'src',
        componentDir: 'src/components',
      });
    });

    it('should exit when cancelled', async () => {
      vi.mocked(fs.existsSync).mockReturnValue(false);
      vi.mocked(p.text).mockResolvedValue('cancelled');
      vi.mocked(p.isCancel).mockReturnValue(true);

      await expect(handleLegacyComponentDirMigration()).rejects.toThrow(
        'process.exit unexpectedly called with "1"',
      );
      expect(p.cancel).toHaveBeenCalledWith(
        'No component directory configured. Use --dir <directory> or set "componentDir" in canvas.config.json.',
      );
    });

    it('should skip prompt in non-interactive mode and show instructions', async () => {
      vi.mocked(fs.existsSync).mockReturnValue(false);

      await handleLegacyComponentDirMigration({ skipPrompt: true });

      expect(p.confirm).not.toHaveBeenCalled();
      expect(fs.writeFileSync).not.toHaveBeenCalled();
      expect(p.log.info).toHaveBeenCalledWith(
        'Add "componentDir": "src/components" to canvas.config.json to persist this setting.',
      );
    });
  });
});
