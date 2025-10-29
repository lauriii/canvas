import { addOrUpdateAutoSavesHash } from '@/components/review/PublishReview.slice';

import type { Dispatch } from 'redux';
import type { AutoSavesHash } from '@/types/AutoSaves';

/**
 * Extracts the canonical request URL for autosaves, starting from 'canvas/api/' and removing any query/hash.
 * @param url The full request URL
 * @returns The canonical request URL or undefined if not found
 */
export function extractAutoSavesRequestUrl(
  url: string | undefined,
): string | undefined {
  if (typeof url !== 'string') {
    return undefined;
  }
  // trim to only part after but including canvas/api
  const startIndex = url.indexOf('canvas/api/');
  if (startIndex === -1) {
    return undefined;
  }
  // trim off any query parameters or hash fragments
  const endIndex = url.search(/[?#]/);
  return url.substring(startIndex, endIndex !== -1 ? endIndex : undefined);
}

/**
 * Centralized handler for updating the autosaves hash keyed by request URL.
 * @param dispatch Redux dispatch function
 * @param autoSaves The AutoSavesHash object
 * @param meta Optional meta object from queryFulfilled
 */
export function handleAutoSavesHashUpdate(
  dispatch: Dispatch,
  autoSaves: AutoSavesHash | undefined,
  meta?: any,
) {
  if (!autoSaves) return;
  // key by API endpoint URL
  const url = meta?.request?.url;
  const requestUrl = extractAutoSavesRequestUrl(url);
  if (requestUrl) {
    dispatch(addOrUpdateAutoSavesHash({ [requestUrl]: autoSaves }));
    return;
  }
  console.error(
    'Failed to update autoSavesHash: request URL is invalid or missing canvas/api/',
  );
}
