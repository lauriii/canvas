import { useCallback, useEffect, useState } from 'react';
import { createHeadlessPreviewHost } from '@drupal-canvas/headless-host';

import { fetchCsrfToken } from '@/utils/csrf';
import { getBaseUrl } from '@/utils/drupal-globals';

import type { RefObject } from 'react';
import type { HeadlessPreviewHostEvent } from '@drupal-canvas/headless-host';
import type { HeadlessSettings } from '@drupal-canvas/types';

export interface HeadlessDraftSession {
  statusText: string;
}

const WAITING_TEXT = 'Waiting for the preview to report its draft session…';

/**
 * Maps host protocol events to the editor's status line text.
 */
function statusTextFor(event: HeadlessPreviewHostEvent): string {
  switch (event.type) {
    case 'active':
      return `Draft session active — renews automatically around ${new Date(event.tokenExpiresAt).toLocaleTimeString()}.`;
    case 'activation-failed':
      return 'The preview could not be started. Are you still logged into Drupal? Reload this page to retry.';
    case 'renewing':
      return 'Renewing the draft session…';
    case 'renew-failed':
      return 'The draft session could not be renewed. Are you still logged into Drupal? Reload this page to retry.';
    case 'recovering':
      return 'Draft session expired — restarting the preview…';
    case 'recovery-failed':
      return 'The draft session could not be restarted. Are you still logged into Drupal? Reload this page to retry.';
  }
}

/**
 * Drives the headless draft session for the editor frame's iframe.
 *
 * The protocol itself (activation, renewal relay, recovery) lives in
 * @drupal-canvas/headless-host; this hook wires it to the Canvas editor:
 * assertions are fetched from the canvas_headless module's endpoint with
 * the same CSRF token the editor's API mutations use (fetchCsrfToken, sent
 * as the X-CSRF-Token header), and a new session activates whenever the
 * edited entity changes, including in-SPA navigation between entities.
 */
export function useHeadlessDraftSession(
  iframeRef: RefObject<HTMLIFrameElement>,
  settings: HeadlessSettings,
  entityType: string | undefined,
  entityId: string | undefined,
): HeadlessDraftSession {
  const { frontendOrigin, draftUrl, assertionUrl } = settings;
  const [statusText, setStatusText] = useState(WAITING_TEXT);

  const fetchAssertion = useCallback(
    async (params: Record<string, string>): Promise<string> => {
      const csrfToken = await fetchCsrfToken(getBaseUrl());

      const url = new URL(assertionUrl, window.location.origin);
      Object.entries(params).forEach(([name, value]) =>
        url.searchParams.set(name, value),
      );
      const response = await fetch(url, {
        method: 'POST',
        headers: {
          Accept: 'application/json',
          'X-CSRF-Token': csrfToken,
        },
      });
      if (!response.ok) {
        throw new Error(`Assertion endpoint answered ${response.status}`);
      }
      const body = await response.json();
      if (typeof body.assertion !== 'string') {
        throw new Error('Assertion endpoint returned no assertion.');
      }
      return body.assertion;
    },
    [assertionUrl],
  );

  // One host per (iframe, app, entity) combination: switching to another
  // entity — including via in-SPA navigation — tears the host down and
  // activates a fresh session entering at the new entity's path.
  useEffect(() => {
    const iframe = iframeRef.current;
    if (!iframe || !entityType || !entityId) {
      return;
    }
    setStatusText(WAITING_TEXT);
    const host = createHeadlessPreviewHost({
      iframe,
      frontendOrigin,
      draftUrl,
      fetchAssertion,
      onEvent: (event) => setStatusText(statusTextFor(event)),
    });
    void host.activate({ entity_type: entityType, entity: entityId });
    return () => {
      host.destroy();
    };
  }, [
    iframeRef,
    frontendOrigin,
    draftUrl,
    fetchAssertion,
    entityType,
    entityId,
  ]);

  return { statusText };
}
