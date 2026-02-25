import { Component, useEffect, useRef, useState } from 'react';
import {
  defineComponentRegistry,
  renderCanvasTree,
  specToCanvasTree,
} from 'drupal-canvas/json-render-utils';
import { resolvePreviewComponent } from '@wb/lib/preview-component-resolver';
import { isPreviewRenderRequest } from '@wb/lib/preview-contract';

import type { ComponentType, ErrorInfo, ReactNode } from 'react';
import type {
  PreviewFrameError,
  PreviewFrameReady,
  PreviewFrameRendered,
  PreviewRenderRequest,
} from '@wb/lib/preview-contract';

function postFrameMessage(
  message: PreviewFrameReady | PreviewFrameRendered | PreviewFrameError,
): void {
  window.parent.postMessage(message, window.location.origin);
}

function applyComponentStylesheet(cssUrl: string | null): void {
  const existing = document.querySelector<HTMLLinkElement>(
    'link[data-canvas-preview-css="component"]',
  );

  if (!cssUrl) {
    existing?.remove();
    return;
  }

  if (existing) {
    existing.href = cssUrl;
    return;
  }

  const link = document.createElement('link');
  link.rel = 'stylesheet';
  link.href = cssUrl;
  link.dataset.canvasPreviewCss = 'component';
  document.head.appendChild(link);
}

type RenderableState =
  | {
      kind: 'component';
      renderId: string;
      component: ComponentType;
      props: Record<string, unknown>;
    }
  | {
      kind: 'page';
      renderId: string;
      node: ReactNode;
    };

function RenderSignal({
  renderId,
  kind,
  onRendered,
}: {
  renderId: string;
  kind: 'component' | 'page';
  onRendered: (kind: 'component' | 'page', renderId: string) => void;
}) {
  useEffect(() => {
    onRendered(kind, renderId);
  }, [kind, onRendered, renderId]);

  return null;
}

class FrameRenderBoundary extends Component<
  {
    renderId: string | null;
    onError: (message: string, renderId: string | null) => void;
    children: ReactNode;
  },
  { hasError: boolean }
> {
  state = {
    hasError: false,
  };

  static getDerivedStateFromError(): { hasError: boolean } {
    return { hasError: true };
  }

  componentDidCatch(error: unknown, _errorInfo: ErrorInfo): void {
    this.props.onError(
      error instanceof Error ? error.message : String(error),
      this.props.renderId,
    );
  }

  componentDidUpdate(prevProps: Readonly<{ renderId: string | null }>): void {
    if (prevProps.renderId !== this.props.renderId && this.state.hasError) {
      this.setState({ hasError: false });
    }
  }

  render(): ReactNode {
    if (this.state.hasError) {
      return (
        <div>Component rendering failed. See parent status for details.</div>
      );
    }

    return this.props.children;
  }
}

export function PreviewFrameApp() {
  const [activeRender, setActiveRender] = useState<RenderableState | null>(
    null,
  );
  const loadedGlobalCssUrlRef = useRef<string | null>(null);

  useEffect(() => {
    postFrameMessage({
      source: 'canvas-workbench-frame',
      type: 'preview:ready',
    });

    const handleMessage = (event: MessageEvent<unknown>) => {
      if (event.origin !== window.location.origin) {
        return;
      }

      if (!isPreviewRenderRequest(event.data)) {
        return;
      }

      void handleRenderRequest(event.data);
    };

    window.addEventListener('message', handleMessage);
    return () => {
      window.removeEventListener('message', handleMessage);
    };
  }, []);

  async function handleRenderRequest(
    request: PreviewRenderRequest,
  ): Promise<void> {
    try {
      if (
        request.payload.globalCssUrl &&
        loadedGlobalCssUrlRef.current !== request.payload.globalCssUrl
      ) {
        await import(/* @vite-ignore */ request.payload.globalCssUrl);
        loadedGlobalCssUrlRef.current = request.payload.globalCssUrl;
      }

      if (request.payload.mode === 'component') {
        applyComponentStylesheet(request.payload.cssUrl);

        const loadedModule = (await import(
          /* @vite-ignore */ request.payload.moduleUrl
        )) as Record<string, unknown>;
        const { component: renderableComponent, reason } =
          resolvePreviewComponent(loadedModule);
        if (!renderableComponent) {
          throw new Error(
            reason ?? 'Module does not export a renderable component.',
          );
        }

        setActiveRender({
          kind: 'component',
          renderId: request.payload.componentId,
          component: renderableComponent,
          props: request.payload.props,
        });
        return;
      }

      applyComponentStylesheet(null);

      await Promise.all(
        request.payload.components
          .filter((component) => component.cssEntryUrl !== null)
          .map(async (component) => {
            await import(/* @vite-ignore */ component.cssEntryUrl!);
          }),
      );

      const registry = await defineComponentRegistry(
        request.payload.components.map((component) => ({
          name: component.name,
          jsEntryPath: component.jsEntryUrl,
        })),
      );
      const pageResponse = await fetch(request.payload.pageSpecUrl);
      if (!pageResponse.ok) {
        throw new Error(
          `Failed to load page "${request.payload.pageSlug}" (${pageResponse.status}).`,
        );
      }
      const pageSpec = await pageResponse.json();
      const tree = specToCanvasTree(pageSpec);
      const renderedPage = await renderCanvasTree(tree, registry);

      setActiveRender({
        kind: 'page',
        renderId: request.payload.pageSlug,
        node: renderedPage,
      });
    } catch (error) {
      const message =
        error instanceof Error
          ? `${error.message}${error.stack ? `\n${error.stack}` : ''}`
          : `Unknown render error: ${String(error)}`;
      setActiveRender(null);
      postFrameMessage({
        source: 'canvas-workbench-frame',
        type: 'preview:error',
        payload: {
          renderId:
            request.payload.mode === 'component'
              ? request.payload.componentId
              : request.payload.pageSlug,
          message,
        },
      });
    }
  }

  const ActiveComponent =
    activeRender?.kind === 'component' ? activeRender.component : null;

  return (
    <main style={{ padding: '1rem' }}>
      <FrameRenderBoundary
        renderId={activeRender?.renderId ?? null}
        onError={(message, renderId) => {
          postFrameMessage({
            source: 'canvas-workbench-frame',
            type: 'preview:error',
            payload: {
              renderId,
              message,
            },
          });
        }}
      >
        {ActiveComponent && activeRender?.kind === 'component' ? (
          <>
            <RenderSignal
              renderId={activeRender.renderId}
              kind="component"
              onRendered={(kind, renderId) => {
                postFrameMessage({
                  source: 'canvas-workbench-frame',
                  type: 'preview:rendered',
                  payload: {
                    kind,
                    renderId,
                  },
                });
              }}
            />
            <ActiveComponent {...activeRender.props} />
          </>
        ) : activeRender?.kind === 'page' ? (
          <>
            <RenderSignal
              renderId={activeRender.renderId}
              kind="page"
              onRendered={(kind, renderId) => {
                postFrameMessage({
                  source: 'canvas-workbench-frame',
                  type: 'preview:rendered',
                  payload: {
                    kind,
                    renderId,
                  },
                });
              }}
            />
            {activeRender.node}
          </>
        ) : (
          <div>No preview rendered yet. Select a component from Workbench.</div>
        )}
      </FrameRenderBoundary>
    </main>
  );
}

export default PreviewFrameApp;
