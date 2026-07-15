import type { ConnectionStatus } from './types';

const COMPONENTS_ENDPOINT_PATH = '/api/canvas/components';

/**
 * Checks whether a frontend is reachable and has a configured Canvas adapter.
 *
 * A configured SDK component endpoint answers an unauthenticated request with
 * 401 and CORS headers for the Drupal origin. If that request cannot be read,
 * a no-CORS request to the frontend itself distinguishes a missing or
 * misconfigured adapter from an unreachable site.
 */
export const checkFrontendConnection = async (
  url: string,
  signal?: AbortSignal,
): Promise<ConnectionStatus> => {
  try {
    const response = await fetch(`${url}${COMPONENTS_ENDPOINT_PATH}`, {
      credentials: 'omit',
      signal,
    });
    return response.status === 401 ? 'ready' : 'setup-needed';
  } catch {
    if (signal?.aborted) {
      return 'unreachable';
    }
  }

  try {
    await fetch(url, {
      credentials: 'omit',
      mode: 'no-cors',
      signal,
    });
    return 'setup-needed';
  } catch {
    return 'unreachable';
  }
};
