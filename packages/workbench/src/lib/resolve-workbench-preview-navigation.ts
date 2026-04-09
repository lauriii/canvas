import type { PreviewManifestComponent } from './preview-contract';

export type ResolveWorkbenchPreviewNavigationResult =
  | { kind: 'navigate'; path: string }
  | { kind: 'open'; href: string };

export interface ResolveWorkbenchPreviewNavigationContext {
  workbenchOrigin: string;
  pageSlugs: ReadonlySet<string>;
  manifestComponents: ReadonlyArray<PreviewManifestComponent>;
}

/**
 * Decide whether a clicked URL should navigate the Workbench shell / iframe
 * router in-app or open in a new window.
 */
export function resolveWorkbenchPreviewNavigation(
  resolvedUrl: URL,
  context: ResolveWorkbenchPreviewNavigationContext,
): ResolveWorkbenchPreviewNavigationResult {
  const { workbenchOrigin, pageSlugs, manifestComponents } = context;

  if (
    resolvedUrl.protocol === 'mailto:' ||
    resolvedUrl.protocol === 'tel:' ||
    resolvedUrl.protocol === 'javascript:'
  ) {
    return { kind: 'open', href: resolvedUrl.href };
  }

  if (resolvedUrl.origin !== workbenchOrigin) {
    return { kind: 'open', href: resolvedUrl.href };
  }

  const pathname = resolvedUrl.pathname;

  const segments = pathname.split('/').filter(Boolean);

  if (segments[0] === 'page') {
    if (segments.length === 1) {
      return { kind: 'navigate', path: '/page' + resolvedUrl.search };
    }
    if (segments.length === 2) {
      const slug = segments[1];
      if (slug && pageSlugs.has(slug)) {
        return {
          kind: 'navigate',
          path: `/page/${slug}${resolvedUrl.search}`,
        };
      }
    }
    return { kind: 'open', href: resolvedUrl.href };
  }

  if (segments[0] === 'component') {
    if (segments.length === 1) {
      return { kind: 'navigate', path: '/component' + resolvedUrl.search };
    }
    if (segments.length === 2) {
      const componentId = segments[1];
      const component = manifestComponents.find((c) => c.id === componentId);
      if (component) {
        return {
          kind: 'navigate',
          path: `/component/${componentId}${resolvedUrl.search}`,
        };
      }
      return { kind: 'open', href: resolvedUrl.href };
    }
    if (segments.length === 3) {
      const componentId = segments[1];
      const mockSegment = segments[2];
      const component = manifestComponents.find((c) => c.id === componentId);
      const parsedMock = Number(mockSegment);
      if (
        component &&
        Number.isInteger(parsedMock) &&
        parsedMock >= 1 &&
        parsedMock <= component.mocks.length
      ) {
        return {
          kind: 'navigate',
          path: `/component/${componentId}/${mockSegment}${resolvedUrl.search}`,
        };
      }
      return { kind: 'open', href: resolvedUrl.href };
    }
  }

  if (
    pathname === '/canvas/workbench-preview.html' ||
    pathname.startsWith('/@') ||
    pathname.startsWith('/node_modules/') ||
    pathname.startsWith('/__')
  ) {
    return { kind: 'open', href: resolvedUrl.href };
  }

  return { kind: 'open', href: resolvedUrl.href };
}
