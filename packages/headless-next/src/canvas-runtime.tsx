import { JsonApiRuntimeProvider } from '@drupal-canvas/headless-react';

import { getJsonApiRuntimeConfig } from './server';

import type { ReactNode } from 'react';

export interface CanvasRuntimeProps {
  children?: ReactNode;
}

/**
 * Supplies the request's JSON:API runtime configuration to every
 * `CanvasComponentTree` below it, so `useJsonApiClient()` works in registered
 * components without application code passing `jsonApi`.
 *
 * A server component: it reads the draft session through the SDK's server
 * integration and hands only nonsecret, serializable configuration (resolved
 * endpoints, the proxy path, preview state) to the client-side provider.
 * Credentials never cross the boundary. Render it once, in the root layout:
 *
 * ```tsx
 * import { CanvasRuntime } from '@drupal-canvas/headless-next/CanvasRuntime';
 *
 * <CanvasRuntime>{children}</CanvasRuntime>
 * ```
 */
export async function CanvasRuntime({ children }: CanvasRuntimeProps) {
  const config = await getJsonApiRuntimeConfig();
  return (
    <JsonApiRuntimeProvider config={config}>{children}</JsonApiRuntimeProvider>
  );
}

export default CanvasRuntime;
