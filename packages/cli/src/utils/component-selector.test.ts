import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import * as p from '@clack/prompts';

import { ALL_COMPONENTS_SELECTOR } from './command-helpers';
import {
  selectLocalComponents,
  selectRemoteComponents,
} from './component-selector';
import * as findComponentDirectories from './find-component-directories';

import type { Component } from '../types/Component';

vi.mock('@clack/prompts');
vi.mock('./find-component-directories');

/**
 * Helper to create a mock Component with required fields
 */
function createMockComponent(machineName: string, name: string): Component {
  return {
    machineName,
    name,
    status: 'published',
    sourceCodeJs: '',
    sourceCodeCss: '',
    compiledJs: '',
    compiledCss: '',
    props: {},
    slots: {},
    required: [],
    importedJsComponents: [],
    dataDependencies: {},
  } as unknown as Component;
}

describe('component-selector', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  afterEach(() => {
    vi.restoreAllMocks();
  });

  describe('selectLocalComponents', () => {
    const mockComponentDirs = [
      '/components/button',
      '/components/card',
      '/components/hero',
    ];

    beforeEach(() => {
      vi.mocked(
        findComponentDirectories.findComponentDirectories,
      ).mockResolvedValue(mockComponentDirs);
    });

    it('should return all components when --all flag is used with skipConfirmation', async () => {
      vi.mocked(p.log.info).mockReturnValue(undefined);

      const result = await selectLocalComponents({
        all: true,
        skipConfirmation: true,
      });

      expect(result.directories).toEqual(mockComponentDirs);
      expect(p.log.info).toHaveBeenCalledWith('Selected all components');
    });

    it('should prompt for confirmation when --all flag is used without skipConfirmation', async () => {
      vi.mocked(p.log.info).mockReturnValue(undefined);
      vi.mocked(p.confirm).mockResolvedValue(true);

      const result = await selectLocalComponents({
        all: true,
        skipConfirmation: false,
      });

      expect(result.directories).toEqual(mockComponentDirs);
      expect(p.confirm).toHaveBeenCalledWith({
        message: 'Process 3 components?',
        initialValue: true,
      });
    });

    it('should return cancelled when confirmation is rejected', async () => {
      vi.mocked(p.confirm).mockResolvedValue(false);

      await expect(
        selectLocalComponents({
          all: true,
          skipConfirmation: false,
        }),
      ).rejects.toThrow('Operation cancelled by user');
    });

    it('should select specific components when --components flag is used', async () => {
      const result = await selectLocalComponents({
        components: 'button,hero',
        skipConfirmation: true,
      });

      expect(result.directories).toEqual([
        '/components/button',
        '/components/hero',
      ]);
    });

    it('should return error when component is not found', async () => {
      await expect(
        selectLocalComponents({
          components: 'nonexistent',
          skipConfirmation: true,
        }),
      ).rejects.toThrow(
        'The following component(s) were not found locally: nonexistent',
      );
    });

    it('should return error with multiple not found components', async () => {
      await expect(
        selectLocalComponents({
          components: 'nonexistent1,button,nonexistent2',
          skipConfirmation: true,
        }),
      ).rejects.toThrow(
        'The following component(s) were not found locally: nonexistent1, nonexistent2',
      );
    });

    it('should show interactive multiselect when no flags provided', async () => {
      vi.mocked(p.multiselect).mockResolvedValue(['/components/button']);
      vi.mocked(p.confirm).mockResolvedValue(true);

      const result = await selectLocalComponents({
        selectMessage: 'Select components to build',
      });

      expect(p.multiselect).toHaveBeenCalledWith({
        message: 'Select components to build',
        options: [
          {
            value: ALL_COMPONENTS_SELECTOR,
            label: 'All components',
          },
          { value: '/components/button', label: 'button' },
          { value: '/components/card', label: 'card' },
          { value: '/components/hero', label: 'hero' },
        ],
        initialValues: [],
        required: true,
      });
      expect(result.directories).toEqual(['/components/button']);
    });

    it('should return all components when "All components" is selected interactively', async () => {
      vi.mocked(p.multiselect).mockResolvedValue([ALL_COMPONENTS_SELECTOR]);
      vi.mocked(p.confirm).mockResolvedValue(true);

      const result = await selectLocalComponents({});

      expect(result.directories).toEqual(mockComponentDirs);
    });

    it('should handle cancelled multiselect', async () => {
      vi.mocked(p.multiselect).mockResolvedValue(Symbol.for('cancel'));
      vi.mocked(p.isCancel).mockReturnValue(true);

      await expect(selectLocalComponents({})).rejects.toThrow(
        'Operation cancelled by user',
      );
    });

    it('should return cancelled when no components found', async () => {
      vi.mocked(
        findComponentDirectories.findComponentDirectories,
      ).mockResolvedValue([]);

      await expect(selectLocalComponents({})).rejects.toThrow(
        'No local components were found in ./components',
      );
    });

    it('should use custom messages when provided', async () => {
      vi.mocked(p.confirm).mockResolvedValue(true);

      await selectLocalComponents({
        all: true,
        skipConfirmation: false,
        confirmMessage: 'Upload these components?',
      });

      expect(p.confirm).toHaveBeenCalledWith({
        message: 'Upload these components?',
        initialValue: true,
      });
    });
  });

  describe('selectRemoteComponents', () => {
    const mockComponents: Record<string, Component> = {
      button: createMockComponent('button', 'Button'),
      card: createMockComponent('card', 'Card'),
      hero: createMockComponent('hero', 'Hero'),
    };

    it('should return all components when --all flag is used with skipConfirmation', async () => {
      const result = await selectRemoteComponents(mockComponents, {
        all: true,
        skipConfirmation: true,
      });

      expect(result.components).toEqual(mockComponents);
    });

    it('should prompt for confirmation when --all flag is used without skipConfirmation', async () => {
      vi.mocked(p.confirm).mockResolvedValue(true);

      const result = await selectRemoteComponents(mockComponents, {
        all: true,
        skipConfirmation: false,
      });

      expect(result.components).toEqual(mockComponents);
      expect(p.confirm).toHaveBeenCalled();
    });

    it('should select specific components when --components flag is used', async () => {
      const result = await selectRemoteComponents(mockComponents, {
        components: 'button,hero',
        skipConfirmation: true,
      });

      expect(result.components).toEqual({
        button: mockComponents.button,
        hero: mockComponents.hero,
      });
    });

    it('should return error when remote component is not found', async () => {
      await expect(
        selectRemoteComponents(mockComponents, {
          components: 'nonexistent',
          skipConfirmation: true,
        }),
      ).rejects.toThrow(
        'The following component(s) were not found: nonexistent',
      );
    });

    it('should show interactive multiselect when no flags provided', async () => {
      vi.mocked(p.multiselect).mockResolvedValue(['button']);
      vi.mocked(p.confirm).mockResolvedValue(true);

      const result = await selectRemoteComponents(mockComponents, {
        selectMessage: 'Select components to download',
      });

      expect(p.multiselect).toHaveBeenCalledWith({
        message: 'Select components to download',
        options: [
          {
            value: ALL_COMPONENTS_SELECTOR,
            label: 'All components',
          },
          { value: 'button', label: 'Button (button)' },
          { value: 'card', label: 'Card (card)' },
          { value: 'hero', label: 'Hero (hero)' },
        ],
        initialValues: [],
        required: true,
      });
      expect(result.components).toEqual({ button: mockComponents.button });
    });

    it('should handle empty components list', async () => {
      await expect(
        selectRemoteComponents(
          {},
          {
            all: true,
          },
        ),
      ).rejects.toThrow('No components found');
    });

    it('should use custom not found message when provided', async () => {
      await expect(
        selectRemoteComponents(mockComponents, {
          components: 'missing',
          notFoundMessage: 'Custom error: missing not found',
        }),
      ).rejects.toThrow('Custom error: missing not found');
    });
  });
});
