/**
 * @file
 * The client for the Canvas Headless module's entity-render endpoint: render
 * one content entity directly and get its Canvas content back as structured
 * data. Drupal Canvas Headless exposes it at
 * `/canvas/content-api/entity?type={type}&id={id}&viewMode={viewMode}`.
 */

import { getSessionToken } from '../token';

import type { DraftData } from '../draft-data';
import type { EntityResult } from '../entity';

/**
 * Fetches one content entity by type and ID, rendered through Canvas.
 *
 * Renders the entity in the given view mode (defaults to `full`) without the
 * surrounding page. With a draft session the request
 * carries the session's user-bound bearer token, so content the initiating
 * editor may see (e.g. unpublished entities) renders; without one — or once
 * the session token has expired — the request is anonymous and resolves only
 * what anonymous visitors may see. Returns null for anything the current
 * access level cannot see (403/404).
 */
export async function fetchEntity(options: {
  baseUrl: string;
  type: string;
  id: string;
  viewMode?: string;
  draftData?: DraftData | null;
  fetchImpl?: typeof fetch;
}): Promise<EntityResult | null> {
  const { baseUrl, type, id, viewMode, draftData, fetchImpl = fetch } = options;

  const headers: Record<string, string> = { Accept: 'application/json' };
  let liveDraft = false;
  if (draftData) {
    const token = getSessionToken(draftData);
    if (token) {
      liveDraft = true;
      headers.Authorization = `${token.tokenType} ${token.value}`;
    }
  }

  const url = new URL(
    `${baseUrl.replace(/\/$/, '')}/canvas/content-api/entity`,
  );
  url.searchParams.set('type', type);
  url.searchParams.set('id', id);
  if (viewMode) {
    url.searchParams.set('viewMode', viewMode);
  }
  const response = await fetchImpl(url, {
    headers,
    cache: 'no-store',
  });

  if (!response.ok) {
    return null;
  }
  const result = (await response.json()) as EntityResult;
  if (liveDraft && result.managedByCanvas) {
    return {
      ...result,
      content: {
        ...(result.content ?? { element: 'renderless-container' }),
        canvasDraftMode: true,
      },
    };
  }
  return result;
}
