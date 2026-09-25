// The directive below must survive any future compiled build of this
// package: the provider holds React context, so it is client code.
'use client';

import { createContext, useContext } from 'react';

import type { ReactNode } from 'react';
import type { JsonApiRuntimeConfig } from 'drupal-canvas/jsonapi-client';

const Context = createContext<JsonApiRuntimeConfig | undefined>(undefined);
Context.displayName = 'CanvasJsonApiRuntime';

export interface JsonApiRuntimeProviderProps {
  /**
   * The nonsecret JSON:API runtime configuration the SDK's server integration
   * prepared for the current request (`getJsonApiRuntimeConfig()`).
   */
  config: JsonApiRuntimeConfig;
  children?: ReactNode;
}

/**
 * Makes the request's JSON:API runtime configuration available to every
 * `CanvasComponentTree` below it, so application code does not pass `jsonApi`
 * to each renderer. Framework adapters render it from their server
 * integration (Next.js: the `CanvasRuntime` server component); TanStack Start
 * applications render it in the root route from loader data. The
 * configuration is serializable and carries no credentials.
 */
export function JsonApiRuntimeProvider({
  config,
  children,
}: JsonApiRuntimeProviderProps) {
  return <Context.Provider value={config}>{children}</Context.Provider>;
}

/** The runtime configuration supplied by the nearest provider, if any. */
export function useJsonApiRuntimeConfig(): JsonApiRuntimeConfig | undefined {
  return useContext(Context);
}
