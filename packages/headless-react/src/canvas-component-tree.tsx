import { createElement, useMemo } from 'react';
import { createJsonApiClient } from 'drupal-canvas';
import {
  CanvasContextProvider,
  JsonApiClientProvider,
  useHasJsonApiClient,
} from 'drupal-canvas/react';

import '@drupal-canvas/headless/preview.css';

import {
  CANVAS_EMPTY_REGION_PLACEHOLDER_CLASS,
  CANVAS_EMPTY_SLOT_PLACEHOLDER_CLASS,
  CANVAS_PREVIEW_CONTENT_REGION_ELEMENT,
  findCanvasComponent,
  getCanvasComponentRenderData,
  getCanvasTemplateMarkerAttributes,
  hasCanvasPreviewContentRegion,
  isCanvasComponentTreeDraft,
  isCanvasComponentTreeEmpty,
  isCanvasComponentTreeSlotEmpty,
  normalizeCanvasComponentTreeSlot,
  reportMissingCanvasComponent,
  reportMissingCanvasComponentUuid,
} from '@drupal-canvas/headless';

import { useJsonApiRuntimeConfig } from './jsonapi-runtime';

import type { ElementType, ReactNode } from 'react';
import type {
  CanvasComponentTreeElement,
  CanvasMarker as CanvasMarkerProps,
} from '@drupal-canvas/headless';
import type { CanvasContext, JsonApiRuntimeConfig } from 'drupal-canvas';

/** App component implementations keyed by component.yml machine name. */
export type CanvasComponentRegistry = Record<string, ElementType>;

export interface CanvasComponentTreeProps {
  tree: CanvasComponentTreeElement | null;
  components: CanvasComponentRegistry;
  /**
   * The page and site context from `fetchPage()` (`page.context`). When
   * supplied, the tree renders inside its own `CanvasContextProvider`,
   * taking precedence over any outer provider, `null` values included. When
   * omitted, an outer `CanvasContextProvider` is inherited without adding
   * another provider.
   */
  context?: CanvasContext;
  /**
   * The nonsecret JSON:API runtime configuration prepared by the SDK's
   * server integration (`getJsonApiRuntimeConfig()`). When omitted, the
   * configuration of the nearest `JsonApiRuntimeProvider` applies (framework
   * adapters supply it from their server integration). With configuration,
   * the renderer creates the browser client for `useJsonApiClient()`, which
   * sends requests through the application's same-origin proxy. Without
   * configuration, an outer `JsonApiClientProvider` is inherited; otherwise
   * the hook reports the missing provider.
   *
   * Server rendering creates its own client from the same configuration.
   * For public rendering it is a direct, unauthenticated client. In a live
   * draft session it is the same non-null, draft-aware client the browser
   * gets — so SWR keys stay enabled and prefetched fallback data renders into
   * the initial HTML without a hydration mismatch — but it performs no
   * network requests: draft data is not fetched during server rendering, and
   * a request made while rendering (rather than in an effect) fails with
   * `ServerRenderingDraftFetchError`, which names the fix. Prefetch draft
   * data on the server with the SDK's `getClient()` and supply it as SWR
   * fallback data; SWR fetches in the browser after hydration.
   */
  jsonApi?: JsonApiRuntimeConfig;
}

/**
 * Thrown when a draft-session client created for server rendering is asked
 * to perform a network request. Not a session error: the session is fine, it
 * is just not reachable while rendering on the server.
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

interface CanvasElementProps {
  node: CanvasComponentTreeElement;
  components: CanvasComponentRegistry;
  path: string;
  editor: boolean;
}

/**
 * Renders a structured Canvas component tree.
 *
 * HTML strings are intentionally inserted as HTML. Apps must only pass trusted
 * rendered output here.
 */
export function CanvasComponentTree({
  tree,
  components,
  context,
  jsonApi,
}: CanvasComponentTreeProps) {
  const rendered = <CanvasTreeContent tree={tree} components={components} />;
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

/**
 * Creates the JSON:API client for the tree from the runtime configuration,
 * reusing it while the configuration stays unchanged. Without configuration
 * an outer provider is inherited.
 */
function CanvasTreeClientProvider({
  jsonApi: explicitConfig,
  children,
}: {
  jsonApi?: JsonApiRuntimeConfig;
  children: ReactNode;
}) {
  const runtimeConfig = useJsonApiRuntimeConfig();
  const hasOuterClient = useHasJsonApiClient();
  // Precedence: the explicit prop, then the adapter-supplied runtime
  // configuration, then an outer JsonApiClientProvider.
  const jsonApi = explicitConfig ?? runtimeConfig;
  const key = jsonApi ? JSON.stringify(jsonApi) : null;
  const client = useMemo(() => {
    if (!jsonApi) {
      return null;
    }
    if (typeof window !== 'undefined') {
      // Browser: through the application's same-origin proxy, which
      // authenticates from the session cookie.
      return createJsonApiClient({ ...jsonApi, credentials: 'same-origin' });
    }
    if (jsonApi.preview) {
      // Server rendering in a live draft session: the same client as the
      // browser's (so SWR fallback data renders and hydration matches), but
      // draft data is prefetched by the server integration, never fetched
      // while rendering — see the `jsonApi` prop.
      return createJsonApiClient({
        ...jsonApi,
        fetch: serverRenderingDraftFetch,
      });
    }
    // Server rendering of public content: direct, unauthenticated.
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

function CanvasTreeContent({
  tree,
  components,
}: Pick<CanvasComponentTreeProps, 'tree' | 'components'>) {
  const editor = isCanvasComponentTreeDraft(tree);
  const previewContentRegion = editor && hasCanvasPreviewContentRegion(tree);
  const emptyRegion =
    editor && !previewContentRegion && isCanvasComponentTreeEmpty(tree);
  const content = tree ? (
    <CanvasElement
      node={tree}
      components={components}
      path="tree"
      editor={editor}
      key={getCanvasElementKey(tree, 'tree')}
    />
  ) : null;

  return editor && !previewContentRegion ? (
    <>
      <CanvasMarker position="start" type="region" id="content" />
      {emptyRegion && <CanvasEmptyRegionPlaceholder />}
      {content}
      <CanvasMarker position="end" type="region" id="content" />
    </>
  ) : (
    content
  );
}

/** Renders an empty-region drop area while editing. */
function CanvasEmptyRegionPlaceholder() {
  return (
    <div aria-hidden="true" className={CANVAS_EMPTY_REGION_PLACEHOLDER_CLASS} />
  );
}

function CanvasElement({ node, components, path, editor }: CanvasElementProps) {
  if (node.element === CANVAS_PREVIEW_CONTENT_REGION_ELEMENT) {
    const content = renderStructuralChildren(node, components, path, editor);
    if (!editor) {
      return <>{content}</>;
    }
    return (
      <>
        <CanvasMarker position="start" type="region" id="content" />
        {isCanvasComponentTreeEmpty(node) && <CanvasEmptyRegionPlaceholder />}
        {content}
        <CanvasMarker position="end" type="region" id="content" />
      </>
    );
  }

  if (node.element === 'drupal-markup') {
    return <>{renderStructuralChildren(node, components, path, editor)}</>;
  }

  const componentData = getCanvasComponentRenderData(node);
  if (!componentData) {
    return (
      <>
        {renderSlots(node, components, path, editor).flatMap(
          ({ content }) => content,
        )}
      </>
    );
  }

  const Component = findCanvasComponent(components, componentData);
  if (!Component) {
    reportMissingCanvasComponent(componentData, path);
    return null;
  }

  const renderedSlots = renderSlots(node, components, path, editor);
  const slotProps = Object.fromEntries(
    renderedSlots.map(({ name, content }) => [
      name === 'default' ? 'children' : name,
      content,
    ]),
  );
  const component = createElement(Component, {
    ...componentData.props,
    ...slotProps,
  });

  if (!editor) {
    return component;
  }
  if (!componentData.componentUuid) {
    reportMissingCanvasComponentUuid(componentData, path);
    return component;
  }
  return (
    <>
      <CanvasMarker
        position="start"
        type="component"
        id={componentData.componentUuid}
      />
      {component}
      <CanvasMarker
        position="end"
        type="component"
        id={componentData.componentUuid}
      />
    </>
  );
}

function renderStructuralChildren(
  node: CanvasComponentTreeElement,
  components: CanvasComponentRegistry,
  path: string,
  editor: boolean,
): ReactNode[] {
  return Object.values(node.slots ?? {}).flatMap((slot, slotIndex) =>
    normalizeCanvasComponentTreeSlot(slot).map((child, childIndex) => {
      const childPath = `${path}:${slotIndex}:${childIndex}`;
      return typeof child === 'string' ? (
        <CanvasMarkup html={child} key={childPath} />
      ) : (
        <CanvasElement
          node={child}
          components={components}
          path={childPath}
          editor={editor}
          key={getCanvasElementKey(child, childPath)}
        />
      );
    }),
  );
}

function renderSlots(
  node: CanvasComponentTreeElement,
  components: CanvasComponentRegistry,
  path: string,
  editor: boolean,
) {
  const componentData = getCanvasComponentRenderData(node);
  return Object.entries(node.slots ?? {}).map(([name, slot]) => {
    const empty = isCanvasComponentTreeSlotEmpty(slot);
    const children =
      editor && empty ? [] : normalizeCanvasComponentTreeSlot(slot);
    return {
      name,
      content: wrapSlot(
        children.map((child, index) => {
          const childPath = `${path}:${name}:${index}`;
          return typeof child === 'string' ? (
            <CanvasMarkup html={child} key={childPath} />
          ) : (
            <CanvasElement
              node={child}
              components={components}
              path={childPath}
              editor={editor}
              key={getCanvasElementKey(child, childPath)}
            />
          );
        }),
        editor,
        componentData?.componentUuid,
        name,
        empty,
      ),
    };
  });
}

/** Keeps a Canvas component's React identity tied to its stored UUID. */
function getCanvasElementKey(
  node: CanvasComponentTreeElement,
  fallback: string,
): string {
  const componentUuid = getCanvasComponentRenderData(node)?.componentUuid;
  return componentUuid ? `component:${componentUuid}` : fallback;
}

function wrapSlot(
  content: ReactNode[],
  editor: boolean,
  componentUuid: string | undefined,
  slotName: string,
  empty: boolean,
): ReactNode[] {
  if (!editor || !componentUuid) {
    return content;
  }
  const id = `${componentUuid}/${slotName}`;
  return [
    <CanvasMarker position="start" type="slot" id={id} key={`${id}:start`} />,
    ...(empty
      ? [<CanvasEmptySlotPlaceholder key={`${id}:empty-placeholder`} />]
      : []),
    ...content,
    <CanvasMarker position="end" type="slot" id={id} key={`${id}:end`} />,
  ];
}

/** Renders a minimum empty-slot drop area while editing. */
function CanvasEmptySlotPlaceholder() {
  return (
    <div aria-hidden="true" className={CANVAS_EMPTY_SLOT_PLACEHOLDER_CLASS} />
  );
}

/** React uses template markers because it cannot render comment nodes. */
function CanvasMarker({ position, type, id }: CanvasMarkerProps) {
  return createElement(
    'template',
    getCanvasTemplateMarkerAttributes({ position, type, id }),
  );
}

function CanvasMarkup({ html }: { html: string }) {
  return (
    <span
      style={{ display: 'contents' }}
      dangerouslySetInnerHTML={{ __html: html }}
    />
  );
}
