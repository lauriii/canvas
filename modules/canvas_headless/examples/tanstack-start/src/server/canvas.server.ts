/**
 * Server-only Canvas helpers. The `.server.ts` suffix keeps this module —
 * and the SDK's server entry it imports — out of the client bundle; only
 * the server functions in canvas.functions.ts may import it.
 */
import {
  fetchPage,
  getClient,
  getDraftConfig,
  getDraftData,
  isDraftModeEnabled,
  isDraftSessionExpired,
} from '@drupal-canvas/headless-tanstack-start'

import type { Page } from '@drupal-canvas/headless-tanstack-start'
import type {
  Article,
  CanvasPage,
  ContentLists,
  DraftSessionState,
} from '#/lib/content'

interface JsonApiDocument<T> {
  data?: T | null
  errors?: Array<{ status?: string; detail?: string }>
}

/**
 * The draft session state the root route's banner needs. Nothing here is a
 * secret: the expiry instant, Drupal's own renew URL (a signed assertion
 * claim), and the embedder origin allowlist that is also published through
 * the CSP header.
 */
export async function readDraftSessionState(): Promise<DraftSessionState> {
  if (!isDraftModeEnabled()) {
    return {
      enabled: false,
      tokenExpiresAt: null,
      expired: false,
      renewUrl: null,
      embedderOrigins: [],
    }
  }
  const draftData = await getDraftData()
  return {
    enabled: true,
    tokenExpiresAt: draftData?.tokenExpiresAt ?? null,
    expired: !draftData || isDraftSessionExpired(draftData),
    renewUrl: draftData?.renewUrl ?? null,
    embedderOrigins: getDraftConfig().embedderOrigins,
  }
}

/**
 * The homepage's content lists, via JSON:API. The client is
 * draft-session-aware and answers working copies while a session is live.
 */
export async function readContentLists(): Promise<ContentLists> {
  const client = await getClient()
  const [canvasPagesDocument, articlesDocument] = await Promise.all([
    client.getCollection('canvas_page--canvas_page') as Promise<
      JsonApiDocument<Array<CanvasPage>>
    >,
    client.getCollection('node--article') as Promise<
      JsonApiDocument<Array<Article>>
    >,
  ])
  return {
    canvasPages: canvasPagesDocument?.data ?? [],
    articles: articlesDocument?.data ?? [],
  }
}

/**
 * Resolves a Drupal path through Drupal's routing (the SDK's fetchPage()),
 * carrying the live draft session's bearer token when there is one.
 */
export function readPageForPath(path: string): Promise<Page | null> {
  return fetchPage(path)
}
