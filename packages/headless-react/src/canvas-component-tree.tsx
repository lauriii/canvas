import { useEffect, useMemo, useSyncExternalStore } from 'react';
import { createJsonApiClient } from 'drupal-canvas';
import {
  CanvasContextProvider,
  JsonApiClientProvider,
  useHasJsonApiClient,
} from 'drupal-canvas/react';
import { convertLegacyCustomElementsFormat } from '@drupal-canvas/headless';
import { CanvasComponentTree as JsonRenderCanvasComponentTree } from '@drupal-canvas/json-render-react';

import { useJsonApiRuntimeConfig } from './jsonapi-runtime';
import {
  getLiveProps,
  settleLiveProps,
  subscribeLiveProps,
  withLiveProps,
} from './live-props';

import type { ReactNode } from 'react';
import type { CanvasComponentTree as CanvasComponentTreeDocument } from '@drupal-canvas/headless';
import type { CanvasComponentRegistry } from '@drupal-canvas/json-render-react';
import type { CanvasContext, JsonApiRuntimeConfig } from 'drupal-canvas';

export type { CanvasComponentRegistry } from '@drupal-canvas/json-render-react';

export interface CanvasComponentTreeProps {
  tree: CanvasComponentTreeDocument | null;
  components: CanvasComponentRegistry;
  /** Whether Canvas editor boundaries and empty drop areas should render. */
  preview?: boolean;
  /**
   * The page and site context from `fetchPage()` (`page.context`). When
   * supplied, the tree renders inside its own `CanvasContextProvider`, taking
   * precedence over any outer provider, including explicit `null` values.
   */
  context?: CanvasContext;
  /**
   * The nonsecret JSON:API runtime configuration prepared by the SDK server
   * integration. When omitted, the nearest `JsonApiRuntimeProvider` applies.
   */
  jsonApi?: JsonApiRuntimeConfig;
}

/**
 * Thrown when a draft-session client created for server rendering is asked to
 * perform a network request.
 */
export class ServerRenderingDraftFetchError extends Error {
  constructor() {
    super(
      '[drupal-canvas] Draft data is not fetched during server rendering: the ' +
        'draft preview session is only reachable from the server integration. ' +
        "Prefetch this data on the server with the SDK's getClient() and " +
        'supply it as SWR fallback data (SWRConfig `fallback`); requests run ' +
        'in the browser after hydration.',
    );
    this.name = 'ServerRenderingDraftFetchError';
  }
}

/** The transport of a draft-session client during server rendering. */
const serverRenderingDraftFetch: typeof fetch = async () => {
  throw new ServerRenderingDraftFetchError();
};

/** Renders Canvas content with context and JSON:API providers. */
export function CanvasComponentTree({
  tree,
  components,
  context,
  jsonApi,
  preview = false,
}: CanvasComponentTreeProps) {
  const serverTree = useMemo(
    () => convertLegacyCustomElementsFormat(tree, components),
    [tree, components],
  );
  // Props the editor holds ahead of this server data render at once. Server
  // data that shows the same values replaces them.
  const liveProps = useSyncExternalStore(
    subscribeLiveProps,
    getLiveProps,
    getLiveProps,
  );
  useEffect(() => settleLiveProps(serverTree), [serverTree]);
  const renderTree = useMemo(
    () => withLiveProps(serverTree, liveProps),
    [serverTree, liveProps],
  );
  const rendered = (
    <JsonRenderCanvasComponentTree
      tree={renderTree}
      components={components}
      preview={preview}
    />
  );
  const withClient = (
    <CanvasTreeClientProvider jsonApi={jsonApi}>
      {rendered}
    </CanvasTreeClientProvider>
  );
  return context === undefined ? (
    withClient
  ) : (
    <CanvasContextProvider context={context}>
      {withClient}
    </CanvasContextProvider>
  );
}

/** Creates or inherits the JSON:API client used by rendered components. */
function CanvasTreeClientProvider({
  jsonApi: explicitConfig,
  children,
}: {
  jsonApi?: JsonApiRuntimeConfig;
  children: ReactNode;
}) {
  const runtimeConfig = useJsonApiRuntimeConfig();
  const hasOuterClient = useHasJsonApiClient();
  const jsonApi = explicitConfig ?? runtimeConfig;
  const key = jsonApi ? JSON.stringify(jsonApi) : null;
  const client = useMemo(() => {
    if (!jsonApi) {
      return null;
    }
    if (typeof window !== 'undefined') {
      return createJsonApiClient({ ...jsonApi, credentials: 'same-origin' });
    }
    if (jsonApi.preview) {
      return createJsonApiClient({
        ...jsonApi,
        fetch: serverRenderingDraftFetch,
      });
    }
    return createJsonApiClient({
      ...jsonApi,
      proxyUrl: undefined,
      resourceVersion: null,
      preview: false,
    });
    // The serialized configuration is the identity of the client.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [key]);
  if (client === null || (hasOuterClient && !jsonApi)) {
    return <>{children}</>;
  }
  return (
    <JsonApiClientProvider client={client}>{children}</JsonApiClientProvider>
  );
}
