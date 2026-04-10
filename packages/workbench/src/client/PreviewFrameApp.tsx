import {
  Component,
  useCallback,
  useEffect,
  useLayoutEffect,
  useMemo,
  useRef,
  useState,
} from 'react';
import {
  defineComponentRegistry,
  renderSpec,
} from 'drupal-canvas/json-render-utils';
import { CircleAlertIcon } from 'lucide-react';
import { useNavigate } from 'react-router';
import {
  Alert,
  AlertDescription,
  AlertTitle,
} from '@wb/client/components/ui/alert';
import { fetchDiscoveryResult } from '@wb/lib/discovery-client';
import { fetchPreviewManifest } from '@wb/lib/preview-client';
import {
  isPreviewRenderRequest,
  isWorkbenchDiscoveryRefreshMessage,
} from '@wb/lib/preview-contract';
import { getPreviewTargetKey } from '@wb/lib/preview-target-key';
import { resolveWorkbenchPreviewNavigation } from '@wb/lib/resolve-workbench-preview-navigation';

import type { ErrorInfo, ReactNode } from 'react';
import type { DiscoveryResult } from '@wb/lib/discovery-client';
import type {
  PreviewFrameError,
  PreviewFrameReady,
  PreviewFrameRendered,
  PreviewManifest,
  PreviewRenderRequest,
  PreviewShellSync,
} from '@wb/lib/preview-contract';

function postFrameMessage(
  message:
    | PreviewFrameReady
    | PreviewFrameRendered
    | PreviewFrameError
    | PreviewShellSync,
): void {
  window.parent.postMessage(message, window.location.origin);
}

interface RenderableState {
  type: 'component' | 'page';
  renderId: string;
  node: ReactNode;
}

function PreviewErrorAlert({ message }: { message: string }) {
  return (
    <Alert className="max-w-3xl" variant="destructive">
      <CircleAlertIcon />
      <AlertTitle>Preview failed to render.</AlertTitle>
      <AlertDescription className="whitespace-pre-wrap font-mono text-[11px]">
        {message}
      </AlertDescription>
    </Alert>
  );
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
  { hasError: boolean; message: string | null }
> {
  state = {
    hasError: false,
    message: null,
  };

  static getDerivedStateFromError(): { hasError: boolean } {
    return { hasError: true };
  }

  componentDidCatch(error: unknown, _errorInfo: ErrorInfo): void {
    const message = error instanceof Error ? error.message : String(error);
    this.setState({ message });
    this.props.onError(message, this.props.renderId);
  }

  componentDidUpdate(prevProps: Readonly<{ renderId: string | null }>): void {
    if (prevProps.renderId !== this.props.renderId && this.state.hasError) {
      this.setState({ hasError: false, message: null });
    }
  }

  render(): ReactNode {
    if (this.state.hasError) {
      return (
        <PreviewErrorAlert
          message={this.state.message ?? 'Unknown render error.'}
        />
      );
    }

    return this.props.children;
  }
}

export function PreviewFrameApp() {
  const navigate = useNavigate();
  const [activeRender, setActiveRender] = useState<RenderableState | null>(
    null,
  );
  const [previewError, setPreviewError] = useState<string | null>(null);
  const [discoveryResult, setDiscoveryResult] =
    useState<DiscoveryResult | null>(null);
  const [previewManifest, setPreviewManifest] =
    useState<PreviewManifest | null>(null);
  const lastPreviewTargetKeyRef = useRef<string | null>(null);

  const pageSlugs = useMemo(() => {
    if (!discoveryResult) {
      return new Set<string>();
    }
    return new Set(discoveryResult.pages.map((page) => page.slug));
  }, [discoveryResult]);

  useEffect(() => {
    let cancelled = false;
    void Promise.all([fetchDiscoveryResult(), fetchPreviewManifest()])
      .then(([discovery, manifest]) => {
        if (!cancelled) {
          setDiscoveryResult(discovery);
          setPreviewManifest(manifest);
        }
      })
      .catch(() => {
        /* Discovery failures leave resolver with empty data; links fall through to open. */
      });
    return () => {
      cancelled = true;
    };
  }, []);

  // The iframe document stays mounted when the shell switches pages or components.
  // Reset window scroll when the preview target changes; keep scroll for HMR (same target).
  useLayoutEffect(() => {
    if (!activeRender) {
      lastPreviewTargetKeyRef.current = null;
      return;
    }
    const key = getPreviewTargetKey(activeRender.type, activeRender.renderId);
    if (lastPreviewTargetKeyRef.current !== key) {
      window.scrollTo(0, 0);
      lastPreviewTargetKeyRef.current = key;
    }
  }, [activeRender]);

  const handleRenderRequest = useCallback(
    async (request: PreviewRenderRequest): Promise<void> => {
      if (request.payload.shellPath) {
        navigate(request.payload.shellPath, { replace: true });
      }

      try {
        setPreviewError(null);

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
        setPreviewError(message);
        postFrameMessage({
          source: 'canvas-workbench-frame',
          type: 'preview:error',
          payload: {
            renderId: request.payload.renderId,
            message,
          },
        });
      }
    },
    [navigate],
  );

  const handleRenderRequestRef = useRef(handleRenderRequest);
  handleRenderRequestRef.current = handleRenderRequest;

  useEffect(() => {
    postFrameMessage({
      source: 'canvas-workbench-frame',
      type: 'preview:ready',
    });

    const handleMessage = (event: MessageEvent<unknown>) => {
      if (event.origin !== window.location.origin) {
        return;
      }

      if (isWorkbenchDiscoveryRefreshMessage(event.data)) {
        void Promise.all([fetchDiscoveryResult(), fetchPreviewManifest()])
          .then(([discovery, manifest]) => {
            setDiscoveryResult(discovery);
            setPreviewManifest(manifest);
          })
          .catch(() => {
            /* Same as initial load: leave resolver data stale on failure. */
          });
        return;
      }

      if (!isPreviewRenderRequest(event.data)) {
        return;
      }

      void handleRenderRequestRef.current(event.data);
    };

    window.addEventListener('message', handleMessage);
    return () => {
      window.removeEventListener('message', handleMessage);
    };
  }, []);

  useEffect(() => {
    const navigationContext = {
      workbenchOrigin: window.location.origin,
      pageSlugs,
      manifestComponents: previewManifest?.components ?? [],
    };

    const handleDocumentClick = (event: MouseEvent) => {
      if (event.defaultPrevented) {
        return;
      }
      if (event.button !== 0) {
        return;
      }
      if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) {
        return;
      }

      const eventTarget = event.target;
      if (!(eventTarget instanceof Element)) {
        return;
      }

      const anchor = eventTarget.closest('a[href]');
      if (!(anchor instanceof HTMLAnchorElement)) {
        return;
      }

      const targetAttr = anchor.target?.trim().toLowerCase();
      if (targetAttr && targetAttr !== '_self') {
        return;
      }

      const hrefAttr = anchor.getAttribute('href');
      if (!hrefAttr || hrefAttr.trim().startsWith('javascript:')) {
        return;
      }

      let resolved: URL;
      try {
        resolved = new URL(hrefAttr, window.location.href);
      } catch {
        return;
      }

      const current = new URL(window.location.href);
      if (
        resolved.origin === current.origin &&
        resolved.pathname === current.pathname &&
        resolved.search === current.search &&
        hrefAttr.trim().startsWith('#')
      ) {
        return;
      }

      const decision = resolveWorkbenchPreviewNavigation(
        resolved,
        navigationContext,
      );

      event.preventDefault();

      if (decision.kind === 'open') {
        window.open(decision.href, '_blank', 'noopener,noreferrer');
        return;
      }

      navigate(decision.path);
      postFrameMessage({
        source: 'canvas-workbench-frame',
        type: 'preview:shell-sync',
        payload: { path: decision.path },
      });
    };

    document.addEventListener('click', handleDocumentClick, true);
    return () => {
      document.removeEventListener('click', handleDocumentClick, true);
    };
  }, [navigate, pageSlugs, previewManifest]);

  useEffect(() => {
    const handleSubmit = (event: Event) => {
      const element = (event.target as HTMLElement | null)?.closest('form');
      if (element) {
        event.preventDefault();
      }
    };

    document.addEventListener('submit', handleSubmit, true);
    return () => {
      document.removeEventListener('submit', handleSubmit, true);
    };
  }, []);

  return (
    <main style={{ padding: '1rem' }}>
      <FrameRenderBoundary
        renderId={activeRender?.renderId ?? null}
        onError={(message, renderId) => {
          setPreviewError(message);
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
        ) : previewError ? (
          <PreviewErrorAlert message={previewError} />
        ) : null}
      </FrameRenderBoundary>
    </main>
  );
}

export default PreviewFrameApp;
