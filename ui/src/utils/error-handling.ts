import type { SerializedError } from '@reduxjs/toolkit';
import type { FetchBaseQueryError } from '@reduxjs/toolkit/query';

export function isQueryError(
  error: unknown,
): error is FetchBaseQueryError | SerializedError {
  return (
    !!error &&
    typeof error === 'object' &&
    ('status' in error || 'message' in error)
  );
}

export function getQueryErrorMessage(
  error: FetchBaseQueryError | SerializedError,
): string {
  if ('status' in error) {
    if (error.status === 'PARSING_ERROR') {
      return 'The server returned an unexpected response format.';
    }
    if (error.status === 404) {
      return 'Resource not found.';
    }
    const errorData = error.data as { message?: string; error?: string };
    return `HTTP ${error.status}: ${errorData?.message || errorData?.error || 'No additional information'}`;
  }
  return error.message || 'Unknown error occurred';
}

export function getOptionalQueryErrorMessage(error: unknown): string | null {
  if (!isQueryError(error)) {
    return null;
  }

  return getQueryErrorMessage(error);
}
