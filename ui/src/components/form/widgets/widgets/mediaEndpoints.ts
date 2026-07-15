import { fetchCsrfToken } from '@/utils/csrf';
import { getDrupalSettings } from '@/utils/drupal-globals';

import type { ClientWidgetContext } from '../types';

/**
 * Typed fetch helpers for the Canvas media and file HTTP endpoints used by
 * the native media, image, and file client widgets.
 *
 * All requests are same-origin and base-path aware. State-changing requests
 * carry Drupal's session CSRF token, matching the RTK Query base query's
 * behavior for mutations.
 */

/**
 * The `inputs_resolved` payload of an image-backed media item. Its shape
 * matches the image prop JSON schema, so it can serve as an optimistic
 * resolved value until the server evaluation echo replaces it.
 */
export interface MediaInputsResolved {
  src: string;
  alt?: string;
  width?: number;
  height?: number;
  [key: string]: unknown;
}

export interface MediaBrowseItem {
  id: number | string;
  uuid: string;
  label: string;
  thumbnailUrl: string | null;
  inputs_resolved: MediaInputsResolved | null;
}

export interface MediaBrowsePager {
  page: number;
  perPage: number;
  total: number;
}

export interface MediaBrowseResponse {
  items: MediaBrowseItem[];
  pager: MediaBrowsePager;
}

export interface MediaUploadResponse {
  id: number | string;
  uuid: string;
  inputs_resolved: MediaInputsResolved | null;
}

export interface FileUploadResponse {
  fid: number;
  uuid: string;
  url: string;
  filename: string;
  filesize: number;
  width: number | null;
  height: number | null;
}

/**
 * Error thrown for non-2xx endpoint responses, carrying the server's message
 * and per-field validation errors so widgets can surface them inline.
 */
export class MediaEndpointError extends Error {
  readonly status: number;
  readonly errors: string[];

  constructor(message: string, status: number, errors: string[] = []) {
    super(message);
    this.name = 'MediaEndpointError';
    this.status = status;
    this.errors = errors;
  }
}

/**
 * Renders any endpoint failure as a single line of user-facing text.
 */
export const formatEndpointError = (error: unknown): string => {
  if (error instanceof MediaEndpointError && error.errors.length > 0) {
    return `${error.message} ${error.errors.join(' ')}`.trim();
  }
  return error instanceof Error ? error.message : String(error);
};

const getBaseUrl = (): string => getDrupalSettings()?.path?.baseUrl ?? '/';

const apiBase = (): string => `${getBaseUrl()}canvas/api/v0`;

/**
 * Renders one entry of an error payload's `errors` list as text.
 *
 * Canvas endpoints mix formats: plain strings, per-field string arrays, and
 * JSON:API error objects such as `{detail, source}`. Prefer the
 * human-readable `detail` (then `message`, then `title`), mirroring the RTK
 * Query error normalization, so validation messages don't collapse to
 * `[object Object]`.
 */
const toErrorText = (entry: unknown): string => {
  if (entry !== null && typeof entry === 'object') {
    const record = entry as Record<string, unknown>;
    for (const key of ['detail', 'message', 'title']) {
      const candidate = record[key];
      if (typeof candidate === 'string' && candidate !== '') {
        return candidate;
      }
    }
  }
  return String(entry);
};

const throwEndpointError = async (response: Response): Promise<never> => {
  let message = `Request failed with status ${response.status}.`;
  let errors: string[] = [];
  try {
    const payload = (await response.json()) as {
      message?: string;
      errors?: unknown;
    };
    if (typeof payload.message === 'string' && payload.message !== '') {
      message = payload.message;
    }
    if (Array.isArray(payload.errors)) {
      errors = payload.errors.map(toErrorText);
    } else if (payload.errors !== null && typeof payload.errors === 'object') {
      errors = Object.values(payload.errors).flat().map(toErrorText);
    }
  } catch {
    // A non-JSON error body keeps the generic status message.
  }
  throw new MediaEndpointError(message, response.status, errors);
};

const csrfHeaders = async (): Promise<Record<string, string>> => {
  try {
    return { 'X-CSRF-Token': await fetchCsrfToken(getBaseUrl()) };
  } catch (error) {
    // Mirror the RTK Query base query: log and attempt the request anyway so
    // the server response is what surfaces to the user.
    console.error((error as Error).message);
    return {};
  }
};

/**
 * Lists media items of a media type: `GET /canvas/api/v0/media/{media_type}`.
 *
 * Passing `ids` restricts the listing to those media entities; used by the
 * media widget to hydrate labels and thumbnails for stored target ids.
 */
export async function browseMedia(
  mediaType: string,
  options: {
    search?: string;
    page?: number;
    ids?: Array<number | string>;
  } = {},
): Promise<MediaBrowseResponse> {
  const params = new URLSearchParams();
  if (options.search) {
    params.set('search', options.search);
  }
  if (options.page !== undefined) {
    params.set('page', String(options.page));
  }
  if (options.ids && options.ids.length > 0) {
    params.set('ids', options.ids.join(','));
  }
  const query = params.toString();
  const response = await fetch(
    `${apiBase()}/media/${encodeURIComponent(mediaType)}${query ? `?${query}` : ''}`,
    { credentials: 'same-origin' },
  );
  if (!response.ok) {
    await throwEndpointError(response);
  }
  return response.json() as Promise<MediaBrowseResponse>;
}

/**
 * Uploads a file as a new media entity:
 * `POST /canvas/api/v0/media/{media_type}/upload`.
 */
export async function uploadMedia(
  mediaType: string,
  file: File,
  options: { alt?: string; title?: string } = {},
): Promise<MediaUploadResponse> {
  const body = new FormData();
  body.append('file', file, file.name);
  if (options.alt) {
    body.append('alt', options.alt);
  }
  if (options.title) {
    body.append('title', options.title);
  }
  const response = await fetch(
    `${apiBase()}/media/${encodeURIComponent(mediaType)}/upload`,
    {
      method: 'POST',
      credentials: 'same-origin',
      headers: await csrfHeaders(),
      body,
    },
  );
  if (!response.ok) {
    await throwEndpointError(response);
  }
  return response.json() as Promise<MediaUploadResponse>;
}

/**
 * Uploads a plain (non-media) file for a specific component prop:
 * `POST /canvas/api/v0/file/upload`.
 *
 * The component/version/prop query parameters let the server apply the
 * prop's field validation (extensions, size, image dimensions).
 */
export async function uploadFile(
  file: File,
  context: { component: string; version: string; prop: string },
): Promise<FileUploadResponse> {
  const params = new URLSearchParams({
    component: context.component,
    version: context.version,
    prop: context.prop,
  });
  const body = new FormData();
  body.append('file', file, file.name);
  const response = await fetch(`${apiBase()}/file/upload?${params}`, {
    method: 'POST',
    credentials: 'same-origin',
    headers: await csrfHeaders(),
    body,
  });
  if (!response.ok) {
    await throwEndpointError(response);
  }
  return response.json() as Promise<FileUploadResponse>;
}

/**
 * Derives the media type (bundle) for a media prop from its field instance
 * settings' `handler_settings.target_bundles`.
 */
export function getMediaTypeFromContext(
  context: ClientWidgetContext,
): string | null {
  const instance = context.sourceTypeSettings.instance as
    | {
        handler_settings?: {
          target_bundles?: string[] | Record<string, string> | null;
        };
      }
    | undefined;
  const bundles = instance?.handler_settings?.target_bundles;
  const list = Array.isArray(bundles) ? bundles : Object.values(bundles ?? {});
  // ponytail: Canvas configures media props with exactly one target bundle,
  // so the first bundle is the media type; revisit if multi-bundle media
  // props ever ship.
  return list.length > 0 ? String(list[0]) : null;
}
