import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { canvasTreeToSpec } from 'drupal-canvas/json-render-utils';
import { useLocation, useNavigate, useParams } from 'react-router';
import { toast } from 'sonner';
import { ThemeMenu } from '@wb/client/components/theme-menu';
import { Badge } from '@wb/client/components/ui/badge';
import { Separator } from '@wb/client/components/ui/separator';
import {
  Sidebar,
  SidebarContent,
  SidebarGroup,
  SidebarGroupContent,
  SidebarGroupLabel,
  SidebarInset,
  SidebarMenu,
  SidebarMenuButton,
  SidebarMenuItem,
  SidebarProvider,
  SidebarRail,
  SidebarTrigger,
} from '@wb/client/components/ui/sidebar';
import { Tabs, TabsList, TabsTrigger } from '@wb/client/components/ui/tabs';
import { fetchDiscoveryResult } from '@wb/lib/discovery-client';
import {
  fetchPreviewManifest,
  fetchPreviewPageSpec,
} from '@wb/lib/preview-client';
import { isPreviewFrameEvent } from '@wb/lib/preview-contract';
import { toViteFsUrl } from '@wb/lib/preview-runtime';
import { WORKBENCH_PREVIEW_HTML_PATH } from '@wb/lib/workbench-preview-constants';
import {
  computeWorkbenchStructuralFingerprint,
  shouldSkipWorkbenchIframeRemount,
} from '@wb/lib/workbench-preview-iframe-remount';

import type { Spec } from '@json-render/core';
import type {
  DiscoveredComponent,
  DiscoveredPage,
  DiscoveryResult,
} from '@wb/lib/discovery-client';
import type {
  PreviewManifest,
  PreviewManifestComponent,
  PreviewManifestComponentMock,
  PreviewRenderRequest,
  PreviewWarning,
  WorkbenchDiscoveryRefresh,
} from '@wb/lib/preview-contract';
import type { WorkbenchHotPayload } from '@wb/lib/workbench-preview-iframe-remount';

const SIDEBAR_COOKIE_NAME = 'sidebar_state';
const DEFAULT_COMPONENT_VARIANT_ID = '__default__';

interface ComponentPreviewVariant {
  id: string;
  label: string;
  mockIndex: number | null;
  source: 'default' | 'mock';
  mock: PreviewManifestComponentMock | null;
}

function toComponentRoute(
  componentId: string,
  mockIndex: number | null,
): string {
  if (mockIndex === null) {
    return `/component/${componentId}`;
  }

  return `/component/${componentId}/${mockIndex + 1}`;
}

function getSidebarDefaultOpen(): boolean {
  if (typeof document === 'undefined') {
    return true;
  }

  const cookieEntry = document.cookie
    .split('; ')
    .find((entry) => entry.startsWith(`${SIDEBAR_COOKIE_NAME}=`));
  if (!cookieEntry) {
    return true;
  }

  return cookieEntry.split('=')[1] !== 'false';
}

function pickInitialSelection(manifest: PreviewManifest): string | null {
  const firstPreviewable = manifest.components.find(
    (component) => component.previewable,
  );
  if (firstPreviewable) {
    return firstPreviewable.id;
  }

  return manifest.components[0]?.id ?? null;
}

function pickInitialPage(pages: DiscoveredPage[]): DiscoveredPage | null {
  return pages[0] ?? null;
}

function getWarningToastId(warning: PreviewWarning, index: number): string {
  return `${warning.code}:${warning.path ?? 'none'}:${index}`;
}

export function App() {
  const location = useLocation();
  const navigate = useNavigate();
  const params = useParams<{
    slug?: string;
    componentId?: string;
    mockIndex?: string;
  }>();
  const [discoveryResult, setDiscoveryResult] =
    useState<DiscoveryResult | null>(null);
  const [previewManifest, setPreviewManifest] =
    useState<PreviewManifest | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [isFrameReady, setIsFrameReady] = useState(false);
  const [iframeKey, setIframeKey] = useState(0);
  const iframeRef = useRef<HTMLIFrameElement | null>(null);
  const lastStructuralFingerprintRef = useRef<string | null>(null);
  const warningToastIdsRef = useRef<Set<string>>(new Set());
  const [sidebarDefaultOpen] = useState(getSidebarDefaultOpen);

  const selectedComponentId = params.componentId ?? null;
  const selectedMockIndex = useMemo<number | null>(() => {
    if (params.mockIndex === undefined) {
      return null;
    }

    const parsedMockIndex = Number(params.mockIndex);
    if (!Number.isInteger(parsedMockIndex) || parsedMockIndex < 1) {
      return -1;
    }

    return parsedMockIndex - 1;
  }, [params.mockIndex]);
  const selectedPageSlug = params.slug ?? null;
  const isComponentRoute =
    location.pathname === '/component' ||
    location.pathname.startsWith('/component/');
  const isPageRoute =
    location.pathname === '/page' || location.pathname.startsWith('/page/');

  const loadWorkbenchData = useCallback(async (): Promise<{
    discovery: DiscoveryResult;
    manifest: PreviewManifest;
  }> => {
    const [discovery, manifest] = await Promise.all([
      fetchDiscoveryResult(),
      fetchPreviewManifest(),
    ]);

    return {
      discovery,
      manifest,
    };
  }, []);

  const sortedComponents = useMemo<PreviewManifestComponent[]>(() => {
    if (!previewManifest) {
      return [];
    }

    return [...previewManifest.components].sort((componentA, componentB) =>
      componentA.label.localeCompare(componentB.label),
    );
  }, [previewManifest]);

  const sortedPages = useMemo<DiscoveredPage[]>(() => {
    if (!discoveryResult) {
      return [];
    }

    return [...discoveryResult.pages].sort((pageA, pageB) =>
      pageA.name.localeCompare(pageB.name),
    );
  }, [discoveryResult]);

  const selectedComponent = useMemo<PreviewManifestComponent | null>(() => {
    if (!previewManifest || isPageRoute || !isComponentRoute) {
      return null;
    }

    if (selectedComponentId) {
      const routedComponent = previewManifest.components.find(
        (component) => component.id === selectedComponentId,
      );
      if (routedComponent) {
        return routedComponent;
      }
    }

    return null;
  }, [isComponentRoute, isPageRoute, previewManifest, selectedComponentId]);

  const selectedPage = useMemo<DiscoveredPage | null>(() => {
    if (!isPageRoute) {
      return null;
    }
    if (!selectedPageSlug) {
      return null;
    }

    return sortedPages.find((page) => page.slug === selectedPageSlug) ?? null;
  }, [isPageRoute, selectedPageSlug, sortedPages]);

  const componentPreviewVariants = useMemo<ComponentPreviewVariant[]>(() => {
    if (!selectedComponent) {
      return [];
    }

    return [
      {
        id: DEFAULT_COMPONENT_VARIANT_ID,
        label: 'Default',
        mockIndex: null,
        source: 'default',
        mock: null,
      },
      ...selectedComponent.mocks.map((mock, index) => ({
        id: mock.id,
        label: mock.label,
        mockIndex: index,
        source: 'mock' as const,
        mock,
      })),
    ];
  }, [selectedComponent]);

  const selectedComponentVariant =
    useMemo<ComponentPreviewVariant | null>(() => {
      if (!selectedComponent) {
        return null;
      }

      if (selectedMockIndex === null) {
        return componentPreviewVariants[0] ?? null;
      }

      return (
        componentPreviewVariants.find(
          (variant) => variant.mockIndex === selectedMockIndex,
        ) ?? null
      );
    }, [componentPreviewVariants, selectedComponent, selectedMockIndex]);

  const selectedComponentMock = selectedComponentVariant?.mock ?? null;

  useEffect(() => {
    let isMounted = true;

    void loadWorkbenchData()
      .then(({ discovery, manifest }) => {
        if (!isMounted) {
          return;
        }

        setDiscoveryResult(discovery);
        setPreviewManifest(manifest);
        lastStructuralFingerprintRef.current =
          computeWorkbenchStructuralFingerprint(discovery, manifest);
      })
      .catch((fetchError: unknown) => {
        if (!isMounted) {
          return;
        }

        setError(
          fetchError instanceof Error
            ? fetchError.message
            : 'Unknown workbench loading error.',
        );
      });

    return () => {
      isMounted = false;
    };
  }, [loadWorkbenchData]);

  useEffect(() => {
    if (!import.meta.hot) {
      return;
    }

    const onWorkbenchUpdate = (payload: WorkbenchHotPayload | undefined) => {
      if (payload?.reloadFrameOnly) {
        // Source-only change: the preview iframe is a separate Vite entry; Vite HMR
        // updates modules in place. No parent-driven remount or extra preview:render.
        return;
      }

      void loadWorkbenchData()
        .then(({ discovery, manifest }) => {
          const previousFingerprint = lastStructuralFingerprintRef.current;
          const nextFingerprint = computeWorkbenchStructuralFingerprint(
            discovery,
            manifest,
          );

          setDiscoveryResult(discovery);
          setPreviewManifest(manifest);
          lastStructuralFingerprintRef.current = nextFingerprint;

          if (
            shouldSkipWorkbenchIframeRemount({
              payload,
              previousFingerprint,
              nextFingerprint,
            })
          ) {
            const iframeWindow = iframeRef.current?.contentWindow;
            if (iframeWindow) {
              const message: WorkbenchDiscoveryRefresh = {
                source: 'canvas-workbench-parent',
                type: 'workbench:discovery-refresh',
              };
              iframeWindow.postMessage(message, window.location.origin);
            }
            return;
          }

          setIsFrameReady(false);
          setIframeKey((value) => value + 1);
        })
        .catch((refreshError: unknown) => {
          setError(
            refreshError instanceof Error
              ? refreshError.message
              : 'Unknown workbench loading error.',
          );
        });
    };

    import.meta.hot.on('canvas:workbench:update', onWorkbenchUpdate);
    return () => {
      import.meta.hot?.off('canvas:workbench:update', onWorkbenchUpdate);
    };
  }, [loadWorkbenchData]);

  useEffect(() => {
    if (!previewManifest || !discoveryResult) {
      return;
    }

    if (isPageRoute) {
      if (selectedPage) {
        return;
      }

      const fallbackPage = pickInitialPage(sortedPages);
      if (fallbackPage) {
        navigate(`/page/${fallbackPage.slug}`, { replace: true });
        return;
      }

      navigate('/component', { replace: true });
      return;
    }

    const hasSelectedRoute = Boolean(
      selectedComponentId &&
      previewManifest.components.some(
        (component) => component.id === selectedComponentId,
      ),
    );
    if (hasSelectedRoute) {
      return;
    }

    const fallbackId = pickInitialSelection(previewManifest);
    if (!fallbackId) {
      return;
    }

    navigate(`/component/${fallbackId}`, { replace: true });
  }, [
    discoveryResult,
    isComponentRoute,
    isPageRoute,
    navigate,
    previewManifest,
    selectedComponentId,
    selectedPage,
    sortedPages,
  ]);

  useEffect(() => {
    if (!selectedComponent) {
      return;
    }

    if (selectedMockIndex === null) {
      return;
    }

    if (selectedComponentVariant) {
      return;
    }

    navigate(toComponentRoute(selectedComponent.id, null), { replace: true });
  }, [
    componentPreviewVariants,
    navigate,
    selectedComponent,
    selectedComponentVariant,
    selectedMockIndex,
  ]);

  const locationRef = useRef(location);
  locationRef.current = location;

  useEffect(() => {
    const handleFrameMessage = (event: MessageEvent<unknown>) => {
      if (event.origin !== window.location.origin) {
        return;
      }

      if (!isPreviewFrameEvent(event.data)) {
        return;
      }

      if (event.data.type === 'preview:shell-sync') {
        const syncPath = event.data.payload.path;
        const current =
          locationRef.current.pathname + locationRef.current.search;
        if (syncPath !== current) {
          navigate(syncPath);
        }
        return;
      }

      if (event.data.type === 'preview:ready') {
        setIsFrameReady(true);
        return;
      }

      if (event.data.type === 'preview:rendered') {
        return;
      }
    };

    window.addEventListener('message', handleFrameMessage);
    return () => {
      window.removeEventListener('message', handleFrameMessage);
    };
  }, [navigate]);

  useEffect(() => {
    if (!previewManifest) {
      return;
    }

    const nextToastIds = new Set<string>();

    previewManifest.warnings.forEach((warning, index) => {
      const toastId = getWarningToastId(warning, index);
      nextToastIds.add(toastId);

      toast.warning(warning.code, {
        id: toastId,
        description: warning.path
          ? `${warning.message} (${warning.path})`
          : warning.message,
        duration: Number.POSITIVE_INFINITY,
      });
    });

    warningToastIdsRef.current.forEach((toastId) => {
      if (!nextToastIds.has(toastId)) {
        toast.dismiss(toastId);
      }
    });

    warningToastIdsRef.current = nextToastIds;
  }, [previewManifest]);

  useEffect(
    () => () => {
      warningToastIdsRef.current.forEach((toastId) => {
        toast.dismiss(toastId);
      });
    },
    [],
  );

  useEffect(() => {
    if (!isFrameReady || !previewManifest) {
      return;
    }

    const pageSpecAbortController = new AbortController();
    const frameWindow = iframeRef.current?.contentWindow;
    if (!frameWindow) {
      return;
    }

    const shellPath = location.pathname + location.search;

    if (
      !isPageRoute &&
      selectedComponent?.previewable &&
      selectedComponent.js.url
    ) {
      const defaultSpec: Spec = canvasTreeToSpec([
        {
          uuid: crypto.randomUUID(),
          parent_uuid: null,
          slot: null,
          component_id: selectedComponent.name,
          component_version: null,
          inputs: selectedComponent.exampleProps,
          label: null,
        },
      ]);
      const specToRender = selectedComponentMock?.spec ?? defaultSpec;
      const selectedMockRenderId = selectedComponentMock
        ? `${selectedComponent.id}:${selectedComponentMock.id}`
        : selectedComponent.id;
      const registrySources = selectedComponentMock
        ? (discoveryResult?.components
            .filter(
              (
                component,
              ): component is DiscoveredComponent & { jsEntryPath: string } =>
                component.jsEntryPath !== null,
            )
            .map(
              (component: DiscoveredComponent & { jsEntryPath: string }) => ({
                name: component.name,
                jsEntryUrl: toViteFsUrl(component.jsEntryPath),
              }),
            ) ?? [])
        : [
            {
              name: selectedComponent.name,
              jsEntryUrl: selectedComponent.js.url,
            },
          ];
      const cssUrls = selectedComponentMock
        ? [
            ...(previewManifest.globalCssUrl
              ? [previewManifest.globalCssUrl]
              : []),
            ...(discoveryResult?.components ?? [])
              .filter((component) => component.cssEntryPath !== null)
              .map((component) => toViteFsUrl(component.cssEntryPath!)),
          ]
        : [
            ...(previewManifest.globalCssUrl
              ? [previewManifest.globalCssUrl]
              : []),
            ...(selectedComponent.css.url ? [selectedComponent.css.url] : []),
          ];

      const message: PreviewRenderRequest = {
        source: 'canvas-workbench-parent',
        type: 'preview:render',
        payload: {
          renderId: selectedMockRenderId,
          renderType: 'component',
          spec: specToRender,
          registrySources,
          cssUrls,
          shellPath,
        },
      };
      frameWindow.postMessage(message, window.location.origin);
      return;
    }

    if (isPageRoute && selectedPage && discoveryResult) {
      void (async () => {
        try {
          const pageSpec = await fetchPreviewPageSpec(
            selectedPage.slug,
            pageSpecAbortController.signal,
          );
          if (pageSpecAbortController.signal.aborted) {
            return;
          }
          const pageMessage: PreviewRenderRequest = {
            source: 'canvas-workbench-parent',
            type: 'preview:render',
            payload: {
              renderId: selectedPage.slug,
              renderType: 'page',
              spec: pageSpec,
              shellPath,
              registrySources: discoveryResult.components
                .filter(
                  (
                    component,
                  ): component is DiscoveredComponent & {
                    jsEntryPath: string;
                  } => component.jsEntryPath !== null,
                )
                .map(
                  (
                    component: DiscoveredComponent & { jsEntryPath: string },
                  ) => ({
                    name: component.name,
                    jsEntryUrl: toViteFsUrl(component.jsEntryPath),
                  }),
                ),
              cssUrls: [
                ...(previewManifest.globalCssUrl
                  ? [previewManifest.globalCssUrl]
                  : []),
                ...discoveryResult.components
                  .filter((component) => component.cssEntryPath !== null)
                  .map((component) => toViteFsUrl(component.cssEntryPath!)),
              ],
            },
          };
          frameWindow.postMessage(pageMessage, window.location.origin);
        } catch (pageLoadError: unknown) {
          if (
            pageLoadError instanceof DOMException &&
            pageLoadError.name === 'AbortError'
          ) {
            return;
          }
          setError(
            pageLoadError instanceof Error
              ? pageLoadError.message
              : 'Unknown page loading error.',
          );
        }
      })();
    }

    return () => {
      pageSpecAbortController.abort();
    };
  }, [
    discoveryResult,
    isFrameReady,
    isPageRoute,
    location.pathname,
    location.search,
    previewManifest,
    selectedComponent,
    selectedComponentMock,
    selectedPage,
  ]);

  if (error) {
    return <div>Workbench failed: {error}</div>;
  }

  if (!discoveryResult || !previewManifest) {
    return;
  }

  const selectedKind = selectedPage
    ? 'page'
    : selectedComponent
      ? 'component'
      : null;
  const selectedName =
    selectedPage?.name ?? selectedComponent?.label ?? 'No selection';
  const selectedPath =
    selectedPage?.relativePath ?? selectedComponent?.projectRelativeDirectory;

  return (
    <SidebarProvider defaultOpen={sidebarDefaultOpen}>
      <Sidebar>
        <SidebarContent>
          {sortedPages.length > 0 ? (
            <SidebarGroup>
              <SidebarGroupLabel>Pages</SidebarGroupLabel>
              <SidebarGroupContent>
                <SidebarMenu>
                  {sortedPages.map((page) => (
                    <SidebarMenuItem key={page.path}>
                      <SidebarMenuButton
                        isActive={page.slug === selectedPage?.slug}
                        onClick={() => {
                          navigate(`/page/${page.slug}`);
                        }}
                      >
                        <span>{page.name}</span>
                      </SidebarMenuButton>
                    </SidebarMenuItem>
                  ))}
                </SidebarMenu>
              </SidebarGroupContent>
            </SidebarGroup>
          ) : null}

          {sortedComponents.length > 0 ? (
            <SidebarGroup>
              <SidebarGroupLabel>Components</SidebarGroupLabel>
              <SidebarGroupContent>
                <SidebarMenu>
                  {sortedComponents.map((component) => (
                    <SidebarMenuItem key={component.id}>
                      <SidebarMenuButton
                        isActive={component.id === selectedComponent?.id}
                        onClick={() => {
                          navigate(toComponentRoute(component.id, null));
                        }}
                      >
                        <span>{component.label}</span>
                        {!component.previewable ? (
                          <Badge variant="destructive">No preview</Badge>
                        ) : null}
                      </SidebarMenuButton>
                    </SidebarMenuItem>
                  ))}
                </SidebarMenu>
              </SidebarGroupContent>
            </SidebarGroup>
          ) : null}
        </SidebarContent>

        <SidebarRail />
      </Sidebar>

      <SidebarInset className="min-h-dvh min-w-0 overflow-hidden">
        <header className="flex h-14 shrink-0 items-center justify-between border-b px-4">
          <div className="flex min-w-0 self-stretch">
            <div className="flex items-center pr-2">
              <SidebarTrigger className="-ml-1" />
            </div>
            <Separator orientation="vertical" className="h-auto self-stretch" />
            <div className="flex min-w-0 flex-col justify-center pl-3">
              <h2 className="truncate text-sm font-semibold">{selectedName}</h2>
              {selectedPath ? (
                <p className="font-mono truncate text-xs text-muted-foreground">
                  {selectedPath}
                </p>
              ) : null}
            </div>
          </div>
          <ThemeMenu />
        </header>

        <section className="flex min-h-0 min-w-0 flex-1 flex-col gap-3 overflow-hidden p-4">
          {selectedComponent && componentPreviewVariants.length > 1 ? (
            <Tabs
              value={
                selectedComponentVariant?.id ?? DEFAULT_COMPONENT_VARIANT_ID
              }
              onValueChange={(value: string) => {
                const variant = componentPreviewVariants.find(
                  (v) => v.id === value,
                );
                if (variant && selectedComponent) {
                  navigate(
                    toComponentRoute(selectedComponent.id, variant.mockIndex),
                  );
                }
              }}
            >
              <TabsList variant="line">
                {componentPreviewVariants.map((variant) => (
                  <TabsTrigger key={variant.id} value={variant.id}>
                    {variant.label}
                  </TabsTrigger>
                ))}
              </TabsList>
            </Tabs>
          ) : null}

          {selectedKind === null ? (
            <div className="flex flex-1 rounded-none border p-4">
              No components or pages were discovered.
            </div>
          ) : selectedKind === 'component' &&
            selectedComponent &&
            !selectedComponent.previewable ? (
            <div className="flex flex-1 rounded-none border p-4">
              This component is not previewable in strict mode. Reason:{' '}
              {selectedComponent.ineligibilityReason}
            </div>
          ) : (
            <div className="min-h-0 flex-1 rounded-none border">
              <iframe
                key={iframeKey}
                ref={iframeRef}
                title="Canvas component preview"
                src={WORKBENCH_PREVIEW_HTML_PATH}
                className="h-full w-full"
                sandbox="allow-scripts allow-same-origin allow-popups"
              />
            </div>
          )}
        </section>
      </SidebarInset>
    </SidebarProvider>
  );
}

export default App;
