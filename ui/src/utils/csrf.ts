const tokens = new Map<string, Promise<string>>();

/**
 * Fetches a CSRF token from Drupal core's /session/token endpoint.
 *
 * The token authorizes state-changing requests within the current Drupal
 * session. Shared by the RTK Query base query and the headless draft-session
 * hook so a single transport carries the token for every mutation; each
 * caller decides how to handle a failure.
 *
 * The token is fixed for a session, so it is fetched once and reused. Call
 * forgetCsrfToken() when the server rejects it.
 *
 * @param baseUrl - Drupal's base URL, ending in a slash.
 * @throws Error when the token cannot be generated.
 */
export function fetchCsrfToken(baseUrl: string): Promise<string> {
  if (!tokens.has(baseUrl)) {
    const token = fetch(`${baseUrl}session/token`).then((response) => {
      if (!response.ok) {
        throw new Error('Failed to generate the CSRF token.');
      }
      return response.text();
    });
    token.catch(() => {
      if (tokens.get(baseUrl) === token) {
        tokens.delete(baseUrl);
      }
    });
    tokens.set(baseUrl, token);
  }
  return tokens.get(baseUrl)!;
}

/**
 * Makes the next fetchCsrfToken() call fetch a new token.
 */
export function forgetCsrfToken(baseUrl: string): void {
  tokens.delete(baseUrl);
}
