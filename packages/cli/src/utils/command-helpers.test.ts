import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { getConfig, setConfig } from '../config';
import {
  parseBooleanOption,
  pluralizeComponent,
  updateConfigFromOptions,
  validateComponentOptions,
} from './command-helpers';

describe('command-helpers', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    // Reset config before each test
    setConfig({
      siteUrl: '',
      clientId: '',
      clientSecret: '',
      scope:
        'canvas:js_component canvas:asset_library canvas:media:image:create canvas:media:view',
      includePages: false,
      componentDir: './components',
      userAgent: '',
      aliasBaseDir: '',
      outputDir: '',
    });
  });

  afterEach(() => {
    vi.restoreAllMocks();
  });

  describe('validateComponentOptions', () => {
    it('should allow --components flag alone', () => {
      expect(() => {
        validateComponentOptions({ components: 'button,card', all: false });
      }).not.toThrow();
    });

    it('should allow --all flag alone', () => {
      expect(() => {
        validateComponentOptions({ all: true });
      }).not.toThrow();
    });

    it('should error when both --components and --all are used', () => {
      expect(() => {
        validateComponentOptions({ components: 'button', all: true });
      }).toThrow('Cannot use --all and --components options together');
    });
  });

  describe('updateConfigFromOptions', () => {
    it('should update clientId when provided', () => {
      updateConfigFromOptions({ clientId: 'test-client' });

      const config = getConfig();
      expect(config.clientId).toBe('test-client');
    });

    it('should update clientSecret when provided', () => {
      updateConfigFromOptions({ clientSecret: 'test-secret' });

      const config = getConfig();
      expect(config.clientSecret).toBe('test-secret');
    });

    it('should update siteUrl when provided', () => {
      updateConfigFromOptions({ siteUrl: 'https://example.com' });

      const config = getConfig();
      expect(config.siteUrl).toBe('https://example.com');
    });

    it('should update componentDir when dir is provided', () => {
      updateConfigFromOptions({ dir: './my-components' });

      const config = getConfig();
      expect(config.componentDir).toBe('./my-components');
    });

    it('should update aliasBaseDir when provided', () => {
      updateConfigFromOptions({ aliasBaseDir: 'src/components' });

      const config = getConfig();
      expect(config.aliasBaseDir).toBe('src/components');
    });

    it('should update outputDir when provided', () => {
      updateConfigFromOptions({ outputDir: 'output' });

      const config = getConfig();
      expect(config.outputDir).toBe('output');
    });

    it('should update scope when provided', () => {
      updateConfigFromOptions({ scope: 'custom:scope' });

      const config = getConfig();
      expect(config.scope).toBe('custom:scope');
    });

    it('should update includePages when provided', () => {
      updateConfigFromOptions({ includePages: true });

      const config = getConfig();
      expect(config.includePages).toBe(true);
    });

    it('should update the default scope when includePages changes', () => {
      updateConfigFromOptions({ includePages: true });

      const config = getConfig();
      expect(config.scope).toBe(
        'canvas:js_component canvas:asset_library canvas:media:image:create canvas:media:view canvas:page:create canvas:page:read canvas:page:edit',
      );
    });

    it('should preserve an explicit scope when includePages changes', () => {
      setConfig({ scope: 'custom:scope' });

      updateConfigFromOptions({ includePages: true });

      const config = getConfig();
      expect(config.scope).toBe('custom:scope');
    });

    it('should update all flag when provided', () => {
      updateConfigFromOptions({ all: true });

      const config = getConfig();
      expect(config.all).toBe(true);
    });

    it('should update multiple options at once', () => {
      updateConfigFromOptions({
        clientId: 'test-id',
        siteUrl: 'https://example.com',
        all: true,
      });

      const config = getConfig();
      expect(config.clientId).toBe('test-id');
      expect(config.siteUrl).toBe('https://example.com');
      expect(config.all).toBe(true);
    });

    it('should not update config when option is undefined', () => {
      setConfig({ clientId: 'existing-id' });

      updateConfigFromOptions({ clientId: undefined });

      const config = getConfig();
      expect(config.clientId).toBe('existing-id');
    });

    it('should preserve existing values when updating only some options', () => {
      setConfig({
        clientId: 'existing-id',
        siteUrl: 'https://existing.com',
      });

      updateConfigFromOptions({ clientId: 'new-id' });

      const config = getConfig();
      expect(config.clientId).toBe('new-id');
      expect(config.siteUrl).toBe('https://existing.com');
    });
  });

  describe('pluralizeComponent', () => {
    it('should return "component" for count of 1', () => {
      expect(pluralizeComponent(1)).toBe('component');
    });

    it('should return "components" for count of 0', () => {
      expect(pluralizeComponent(0)).toBe('components');
    });

    it('should return "components" for count of 2', () => {
      expect(pluralizeComponent(2)).toBe('components');
    });
  });

  describe('parseBooleanOption', () => {
    it('parses truthy values', () => {
      expect(parseBooleanOption('true')).toBe(true);
      expect(parseBooleanOption('1')).toBe(true);
      expect(parseBooleanOption('yes')).toBe(true);
    });

    it('parses falsy values', () => {
      expect(parseBooleanOption('false')).toBe(false);
      expect(parseBooleanOption('0')).toBe(false);
      expect(parseBooleanOption('no')).toBe(false);
    });

    it('throws for invalid values', () => {
      expect(() => parseBooleanOption('maybe')).toThrow(
        'Expected a boolean value',
      );
    });
  });
});
