// Utility to remove /component/:componentId from a pathname
export function removeComponentFromPathname(pathname: string): string {
  // Remove all /component/:componentId segments
  const cleaned = pathname.replace(/\/component\/[^/]+/g, '');
  // Remove any double slashes that may result
  return cleaned.replace(/\/\//g, '/').replace(/\/$/, '');
}

// Utility to robustly set /component/:componentId in a pathname
export function setComponentInPathname(
  pathname: string,
  componentId?: string,
): string {
  const componentRegex = /\/component\/[^/]+$/;
  let newPath = pathname;
  if (!componentId) {
    // Remove /component/:componentId if present
    newPath = newPath.replace(componentRegex, '');
  } else {
    if (componentRegex.test(newPath)) {
      // Replace existing /component/:componentId
      newPath = newPath.replace(componentRegex, `/component/${componentId}`);
    } else {
      // Ensure no trailing slash before appending
      newPath = newPath.replace(/\/$/, '') + `/component/${componentId}`;
    }
  }
  // Clean up double slashes and trailing slash
  return newPath.replace(/\/\//g, '/').replace(/\/$/, '');
}

// Utility to update the preview entity ID in template editor pathname leaving other route segments intact
export function setPreviewEntityIdInPathname(
  pathname: string,
  entityId?: string | number,
): string {
  // Normalize pathname by removing trailing slash
  const normalizedPathname = pathname.replace(/\/$/, '');

  // Match /template/:entityType/:bundle/:viewMode with optional previewEntityId and any following segments
  // Captures everything after viewMode in two groups: previewEntityId and remaining path segments
  const templateRouteRegex =
    /^\/template\/([^/]+)\/([^/]+)\/([^/]+)(\/[^/]+)?(.*)$/;
  const match = normalizedPathname.match(templateRouteRegex);

  if (!match) {
    throw new Error(
      `setPreviewEntityIdInPathname: Current route "${pathname}" is not a template editor route. Expected format: /template/:entityType/:bundle/:viewMode. Use navigateToTemplateEditor() instead.`,
    );
  }

  // eslint-disable-next-line @typescript-eslint/no-unused-vars
  const [, entityType, bundle, viewMode, _existingEntityId, remainingPath] =
    match;

  // Build the new path
  const baseRoute = `/template/${entityType}/${bundle}/${viewMode}`;
  const entitySegment = entityId ? `/${entityId}` : '';
  // Preserve any path segments that came after the previewEntityId (region, component, etc.)
  const trailingSegments = remainingPath || '';

  return `${baseRoute}${entitySegment}${trailingSegments}`;
}

// Utility to detect the routes that encode a component selection in their
// pathname: the entity editor, the template editor, and the pattern editor,
// each addressing the thing whose component tree is being edited. The helpers
// above must not be applied to any other route, because a route without a
// component tree has nowhere to put a selection, and because the same segment
// name means something else elsewhere — /code-editor/component/:codeComponentId
// identifies a code component, not a component instance.
// @see ui/src/app/AppRoutes.tsx
export function isLayoutEditorPathname(pathname: string): boolean {
  return /^\/(editor\/[^/]+\/[^/]+|template(\/[^/]+){4}|pattern\/[^/]+)(\/|$)/.test(
    pathname,
  );
}
