import { isTopLevelPageSpecPath } from './page-spec-path';

import type { DiscoveryResult } from './discovery-client';
import type { PreviewManifest } from './preview-contract';

/**
 * Stable structural fingerprint for discovery + manifest: global CSS URL, sorted
 * component names, sorted page slugs. Content edits to an existing page JSON file
 * should not change this string.
 */
export function computeWorkbenchStructuralFingerprint(
  discovery: DiscoveryResult,
  manifest: PreviewManifest,
): string {
  const globalCss = manifest.globalCssUrl ?? '';
  const componentNames = [...discovery.components]
    .map((component) => component.name)
    .sort()
    .join('\0');
  const pageSlugs = [...discovery.pages]
    .map((page) => page.slug)
    .sort()
    .join('\0');
  return `${globalCss}\n${componentNames}\n${pageSlugs}`;
}

export interface WorkbenchHotPayload {
  reloadFrameOnly?: boolean;
  filePath?: string;
  event?: string;
}

/**
 * When a full manifest refresh runs (`reloadFrameOnly: false`), the shell can
 * skip remounting the preview iframe if the change is an in-place edit to a
 * page spec and discovery structure is unchanged.
 */
export function shouldSkipWorkbenchIframeRemount(params: {
  payload: WorkbenchHotPayload | undefined;
  previousFingerprint: string | null;
  nextFingerprint: string;
}): boolean {
  const { payload, previousFingerprint, nextFingerprint } = params;

  if (payload?.reloadFrameOnly !== false) {
    return false;
  }

  if (!payload.filePath || payload.event !== 'change') {
    return false;
  }

  if (!isTopLevelPageSpecPath(payload.filePath)) {
    return false;
  }

  if (previousFingerprint === null) {
    return false;
  }

  return previousFingerprint === nextFingerprint;
}
