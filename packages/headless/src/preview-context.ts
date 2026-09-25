/** Rendering choices for one preview request, independent of its auth cookie. */
export interface PreviewContext {
  language?: string;
  viewMode?: string;
  pageVariant?: string;
  excludeAutoSave?: boolean;
}

const stringContextKeys = ['language', 'viewMode', 'pageVariant'] as const;

/** Reserves Canvas's prefix without changing the context key. */
function queryName(key: keyof PreviewContext): string {
  return `_canvas_${key}`;
}

function isValidContextValue(key: string, value: unknown): value is string {
  return (
    typeof value === 'string' &&
    value.trim() !== '' &&
    (key !== 'viewMode' || /^[a-z0-9_]+$/.test(value))
  );
}

/**
 * Reads rendering context and removes reserved parameters from Drupal's URI.
 * Explicit parameters replace defaults, including empty values that clear them.
 * The first occurrence of each reserved parameter wins.
 */
export function parsePreviewRequest(
  path: string,
  defaults: PreviewContext = {},
): PreviewContext & {
  requestUri: string;
  excludeAutoSave: boolean;
} {
  const context: PreviewContext = {};
  for (const key of stringContextKeys) {
    const value = defaults[key];
    if (isValidContextValue(key, value)) {
      context[key] = value;
    }
  }
  let excludeAutoSave = defaults.excludeAutoSave === true;
  const fragmentIndex = path.indexOf('#');
  const fragment = fragmentIndex < 0 ? '' : path.slice(fragmentIndex);
  const request = fragmentIndex < 0 ? path : path.slice(0, fragmentIndex);
  const queryIndex = request.indexOf('?');
  if (queryIndex < 0) {
    return { ...context, requestUri: path, excludeAutoSave };
  }

  const seen = new Set<string>();
  // Preserve the page's original query encoding and repeated parameters.
  const parameters = request
    .slice(queryIndex + 1)
    .split('&')
    .filter((parameter) => {
      const query = new URLSearchParams(parameter);
      for (const key of [...stringContextKeys, 'excludeAutoSave'] as const) {
        const name = queryName(key);
        if (query.has(name)) {
          if (!seen.has(name)) {
            const value = query.get(name);
            if (key === 'excludeAutoSave') {
              excludeAutoSave = value === 'true';
            } else {
              delete context[key];
              if (isValidContextValue(key, value)) {
                context[key] = value;
              }
            }
            seen.add(name);
          }
          return false;
        }
      }
      return true;
    });
  if (seen.size === 0) {
    return { ...context, requestUri: path, excludeAutoSave };
  }
  const query = parameters.join('&');
  return {
    ...context,
    requestUri:
      request.slice(0, queryIndex) + (query ? `?${query}` : '') + fragment,
    excludeAutoSave,
  };
}

/** Replaces all preview context, preserving the page's query and fragment. */
export function withPreviewContext(
  path: string,
  context: PreviewContext,
): string {
  const { requestUri } = parsePreviewRequest(path);
  const fragmentIndex = requestUri.indexOf('#');
  const fragment = fragmentIndex < 0 ? '' : requestUri.slice(fragmentIndex);
  const request =
    fragmentIndex < 0 ? requestUri : requestUri.slice(0, fragmentIndex);
  const parameters = new URLSearchParams();
  for (const key of stringContextKeys) {
    const value = context[key];
    if (isValidContextValue(key, value)) {
      parameters.set(queryName(key), value);
    }
  }
  if (context.excludeAutoSave === true) {
    parameters.set(queryName('excludeAutoSave'), 'true');
  }
  const query = parameters.toString();
  return `${request}${query ? `${request.includes('?') ? '&' : '?'}${query}` : ''}${fragment}`;
}
