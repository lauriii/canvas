import { describe, expect, it } from 'vitest';

import { extractErrorMessageFromApiResponse } from '@/features/error-handling/error-handling';

import type { FetchBaseQueryError } from '@reduxjs/toolkit/query';

describe('extractErrorMessageFromApiResponse', () => {
  it('returns DOMPurify-sanitized HTML from 422 validation errors, preserving safe tags like <em>', () => {
    const error: FetchBaseQueryError = {
      status: 422,
      data: {
        errors: [
          {
            detail:
              'CSS variable <em class="placeholder">--red</em> is already in use by another color.',
          },
        ],
      },
    };

    expect(extractErrorMessageFromApiResponse(error)).toBe(
      'CSS variable <em class="placeholder">--red</em> is already in use by another color.',
    );
  });

  it('returns plain string errors from simple string array errors', () => {
    const error: FetchBaseQueryError = {
      status: 409,
      data: {
        errors: [
          'You do not have the latest changes, please refresh your browser.',
        ],
      },
    };

    expect(extractErrorMessageFromApiResponse(error)).toBe(
      'You do not have the latest changes, please refresh your browser.',
    );
  });

  it('returns fallback message when errors array is empty', () => {
    const error: FetchBaseQueryError = {
      status: 500,
      data: { errors: [] },
    };

    expect(extractErrorMessageFromApiResponse(error)).toBe(
      'Error occurred, see browser console for more details.',
    );
  });

  it('returns fallback message when errors key is absent', () => {
    const error: FetchBaseQueryError = {
      status: 500,
      data: {},
    };

    expect(extractErrorMessageFromApiResponse(error)).toBe(
      'Error occurred, see browser console for more details.',
    );
  });
});
