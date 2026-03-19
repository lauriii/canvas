import { Component, useEffect, useState } from 'react';
import {
  defineComponentRegistry,
  renderSpec,
} from 'drupal-canvas/json-render-utils';
import { isPreviewRenderRequest } from '@wb/lib/preview-contract';

import type { ErrorInfo, ReactNode } from 'react';
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

interface RenderableState {
  type: 'component' | 'page';
  renderId: string;
  node: ReactNode;
}

function RenderSignal({
  renderId,
  type,
  onRendered,
}: {
  renderId: string;
  type: 'component' | 'page';
  onRendered: (type: 'component' | 'page', renderId: string) => void;
}) {
  useEffect(() => {
    onRendered(type, renderId);
  }, [type, onRendered, renderId]);

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
      await Promise.all(
        request.payload.cssUrls.map(async (cssUrl) => {
          await import(/* @vite-ignore */ cssUrl);
        }),
      );

      const registry = await defineComponentRegistry(
        request.payload.registrySources.map((source) => ({
          name: source.name,
          jsEntryPath: source.jsEntryUrl,
        })),
      );

      const node = renderSpec(request.payload.spec, registry);
      const renderType: RenderableState['type'] = request.payload.renderType;

      setActiveRender({
        type: renderType,
        renderId: request.payload.renderId,
        node,
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
          renderId: request.payload.renderId,
          message,
        },
      });
    }
  }

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
        {activeRender ? (
          <>
            <RenderSignal
              renderId={activeRender.renderId}
              type={activeRender.type}
              onRendered={(type, renderId) => {
                postFrameMessage({
                  source: 'canvas-workbench-frame',
                  type: 'preview:rendered',
                  payload: {
                    type,
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
