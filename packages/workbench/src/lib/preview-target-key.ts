/**
 * Stable key for the current Workbench preview selection (page or component).
 * Used to detect when the shell navigates to a different preview so the iframe
 * can reset scroll without affecting same-target HMR.
 */
export function getPreviewTargetKey(
  renderType: 'component' | 'page',
  renderId: string,
): string {
  return `${renderType}:${renderId}`;
}
