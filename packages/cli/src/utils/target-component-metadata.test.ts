import { describe, expect, it, vi } from 'vitest';

import {
  CodeComponentMetadataOperationUnsupportedError,
  CodeComponentMetadataValidationUnavailableError,
} from '../services/api';
import { preflightCodeComponentPayloads } from './target-component-metadata';

import type { BuiltComponent } from './build-project';

describe('target component metadata', () => {
  it('omits only same-push imports from validation without changing uploads', async () => {
    const components = [
      {
        componentName: 'Image feature',
        componentPayload: {
          machineName: 'image_feature',
          importedJsComponents: ['logo', 'existing', 'missing'],
        },
        importedJsComponents: ['logo', 'existing', 'missing'],
      },
      {
        componentName: 'Logo',
        componentPayload: {
          machineName: 'logo',
          importedJsComponents: [],
        },
        importedJsComponents: [],
      },
    ] as BuiltComponent[];
    const originals = structuredClone(components);
    const validateCodeComponentPayload = vi
      .fn()
      .mockRejectedValueOnce(new Error('Missing component: missing'))
      .mockResolvedValueOnce(undefined);

    const preflight = await preflightCodeComponentPayloads(components, {
      validateCodeComponentPayload,
    });

    expect(validateCodeComponentPayload).toHaveBeenNthCalledWith(1, {
      ...originals[0].componentPayload,
      importedJsComponents: ['existing', 'missing'],
    });
    expect(validateCodeComponentPayload).toHaveBeenNthCalledWith(
      2,
      originals[1].componentPayload,
    );
    expect(components).toEqual(originals);
    expect(preflight.results).toEqual([
      expect.objectContaining({ itemName: 'Image feature', success: false }),
      expect.objectContaining({ itemName: 'Logo', success: true }),
    ]);
  });

  it('collects remote payload failures before mutation', async () => {
    const components = [
      {
        componentName: 'First',
        componentPayload: { machineName: 'first' },
      },
      {
        componentName: 'Second',
        componentPayload: { machineName: 'second' },
      },
    ] as BuiltComponent[];
    const validateCodeComponentPayload = vi
      .fn()
      .mockRejectedValueOnce(new Error('Rejected first'))
      .mockResolvedValueOnce(undefined);

    const preflight = await preflightCodeComponentPayloads(components, {
      validateCodeComponentPayload,
    });

    expect(validateCodeComponentPayload).toHaveBeenCalledTimes(2);
    expect(preflight.results).toEqual([
      expect.objectContaining({ itemName: 'First', success: false }),
      expect.objectContaining({ itemName: 'Second', success: true }),
    ]);
  });

  it('fails before mutation when target validation becomes unavailable', async () => {
    const preflight = await preflightCodeComponentPayloads(
      [
        {
          componentName: 'Example',
          componentPayload: { machineName: 'example' },
        } as BuiltComponent,
      ],
      {
        validateCodeComponentPayload: vi
          .fn()
          .mockRejectedValue(
            new CodeComponentMetadataValidationUnavailableError(),
          ),
      },
    );

    expect(preflight.warnings).toEqual([]);
    expect(preflight.results).toEqual([
      expect.objectContaining({ itemName: 'Example', success: false }),
    ]);
  });

  it('falls back to save-time validation for an older target', async () => {
    const preflight = await preflightCodeComponentPayloads(
      [
        {
          componentName: 'Example',
          componentPayload: { machineName: 'example' },
        } as BuiltComponent,
      ],
      {
        validateCodeComponentPayload: vi
          .fn()
          .mockRejectedValue(
            new CodeComponentMetadataOperationUnsupportedError(),
          ),
      },
    );

    expect(preflight.results).toEqual([]);
    expect(preflight.warnings[0]).toContain('partial push');
  });
});
