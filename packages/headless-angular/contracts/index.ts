import type { EntityResult, PageResult } from '@drupal-canvas/headless';

/** Explicit browser allowlist: never include DraftData, tokens or PKCE verifiers. */
export interface CanvasSession {
  enabled: boolean;
  tokenExpiresAt: number | null;
  initialExpired: boolean;
  renewUrl: string | null;
  editorOrigin: string | null;
}
export interface CanvasPageData {
  page: PageResult | null;
  session: CanvasSession;
}
/** Passed to Angular's public REQUEST_CONTEXT, not serialized wholesale. */
export interface CanvasRequestContext {
  canvas: {
    path: string;
    data: CanvasPageData;
    fetchEntity: (options: CanvasEntityOptions) => Promise<EntityResult | null>;
  };
}
export interface CanvasEntityOptions {
  type: string;
  id: string;
  viewMode?: string;
}
export function assertCanvasPath(path: string): void {
  if (
    !path.startsWith('/') ||
    path.startsWith('//') ||
    /[\\\r\n#]/.test(path)
  ) {
    throw new Error(
      'Canvas paths must be site-relative and contain no fragment.',
    );
  }
}
