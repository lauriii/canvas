/**
 * @file
 * The JSON:API client context for React Code Components. Rendering
 * integrations own client creation and reuse; the provider receives an
 * already configured client and the hook reads it.
 */

import { createContext, useContext } from 'react';

import { warnOnce } from './warnings.js';

import type { ReactNode } from 'react';
import type { JsonApiClient } from './jsonapi-client.js';

export interface JsonApiClientProviderProps {
  client: JsonApiClient;
  children?: ReactNode;
}

const Context = createContext<JsonApiClient | undefined>(undefined);
Context.displayName = 'CanvasJsonApiClientContext';

/**
 * Makes a configured JSON:API client available to every React descendant.
 * It does not construct the client or manage authentication.
 */
export function JsonApiClientProvider({
  client,
  children,
}: JsonApiClientProviderProps) {
  return <Context.Provider value={client}>{children}</Context.Provider>;
}

/**
 * Returns the nearest provider's client, or `null` with a deduplicated
 * warning when no provider exists. The hook never fetches data or creates a
 * client on render.
 */
export function useJsonApiClient(): JsonApiClient | null {
  const client = useContext(Context);
  if (client === undefined) {
    warnOnce(
      'useJsonApiClient(): no JsonApiClientProvider is mounted above this ' +
        'component. Headless React renderers provide one when they receive ' +
        'the JSON:API runtime configuration from the framework adapter; ' +
        'applications can also wrap components in JsonApiClientProvider. ' +
        'In Drupal and Workbench the rendering integration provides it.',
    );
    return null;
  }
  return client;
}

/**
 * Whether a `JsonApiClientProvider` is mounted above the calling component.
 */
export function useHasJsonApiClient(): boolean {
  return useContext(Context) !== undefined;
}
