import { describe, expect, it, vi } from 'vitest';

import {
  CodeComponentMetadataOperationUnsupportedError,
  CodeComponentMetadataValidationUnavailableError,
} from '../services/api';
import { preflightCodeComponentPayloads } from './target-component-metadata';

import type { BuiltComponent } from './build-project';

describe('target component metadata', () => {
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
