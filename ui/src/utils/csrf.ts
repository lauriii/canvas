/**
 * Fetches a CSRF token from Drupal core's /session/token endpoint.
 *
 * The token authorizes state-changing requests within the current Drupal
 * session. Shared by the RTK Query base query and the headless draft-session
 * hook so a single transport carries the token for every mutation; each
 * caller decides how to handle a failure.
 *
 * @param baseUrl - Drupal's base URL, ending in a slash.
 * @throws Error when the token cannot be generated.
 */
export async function fetchCsrfToken(baseUrl: string): Promise<string> {
  const response = await fetch(`${baseUrl}session/token`);
  if (!response.ok) {
    throw new Error('Failed to generate the CSRF token.');
  }
  return response.text();
}
