import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { getConfig, setConfig } from '../config';
import {
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
      scope: '',
      componentDir: './components',
      userAgent: '',
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

    it('should update scope when provided', () => {
      updateConfigFromOptions({ scope: 'custom:scope' });

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
});
