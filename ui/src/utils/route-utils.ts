// Utility to remove /region/:regionId from a pathname
export function removeRegionFromPathname(pathname: string): string {
  // Remove all /region/:regionId segments
  const cleaned = pathname.replace(/\/region\/[^/]+/g, '');
  // Remove any double slashes that may result
  return cleaned.replace(/\/\//g, '/').replace(/\/$/, '');
}

// Utility to remove /component/:componentId from a pathname
export function removeComponentFromPathname(pathname: string): string {
  // Remove all /component/:componentId segments
  const cleaned = pathname.replace(/\/component\/[^/]+/g, '');
  // Remove any double slashes that may result
  return cleaned.replace(/\/\//g, '/').replace(/\/$/, '');
}

// Utility to robustly set /region/:regionId in a pathname
export function setRegionInPathname(
  pathname: string,
  regionId?: string,
  defaultRegion?: string,
): string {
  const regionRegex = /\/region\/[^/]+/;
  let newPath = pathname;
  if (regionId === defaultRegion || !regionId) {
    // Remove /region/:regionId if present
    newPath = newPath.replace(regionRegex, '');
  } else {
    if (regionRegex.test(newPath)) {
      // Replace existing /region/:regionId
      newPath = newPath.replace(regionRegex, `/region/${regionId}`);
    } else {
      // Append /region/:regionId
      newPath += `/region/${regionId}`;
    }
  }
  // Clean up double slashes and trailing slash
  return newPath.replace(/\/\//g, '/').replace(/\/$/, '');
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
