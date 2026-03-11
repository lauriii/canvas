import { describe, expect, it } from 'vitest';

import {
  getOptionalQueryErrorMessage,
  getQueryErrorMessage,
  isQueryError,
} from '@/utils/error-handling';

describe('error-handling', () => {
  it('returns the message field from query error data', () => {
    expect(
      getQueryErrorMessage({
        status: 500,
        data: { message: 'Upload failed.' },
      }),
    ).toBe('HTTP 500: Upload failed.');
  });

  it('falls back to the error field from query error data', () => {
    expect(
      getQueryErrorMessage({
        status: 400,
        data: { error: 'Invalid font payload.' },
      }),
    ).toBe('HTTP 400: Invalid font payload.');
  });

  it('returns null for unknown values in the optional helper', () => {
    expect(getOptionalQueryErrorMessage(undefined)).toBeNull();
    expect(getOptionalQueryErrorMessage('plain string')).toBeNull();
  });

  it('detects RTK Query and serialized errors', () => {
    expect(isQueryError({ status: 404, data: {} })).toBe(true);
    expect(isQueryError({ message: 'Request failed.' })).toBe(true);
    expect(isQueryError(null)).toBe(false);
    expect(isQueryError('Request failed.')).toBe(false);
  });
});
