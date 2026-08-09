import { useCallback, useEffect, useRef, useState } from 'react';
import { createHeadlessPreviewHost } from '@drupal-canvas/headless-host';

import { fetchCsrfToken } from '@/utils/csrf';
import { getBaseUrl } from '@/utils/drupal-globals';

import type { RefObject } from 'react';
import type {
  HeadlessPreviewHost,
  HeadlessPreviewHostEvent,
} from '@drupal-canvas/headless-host';
import type { CanvasGeometry } from '@drupal-canvas/preview-geometry';
import type { HeadlessSettings } from '@drupal-canvas/types';
import type { AutoSavesHashRecord } from '@/types/AutoSaves';

export interface HeadlessDraftSession {
  statusText: string;
  /** The app's last-reported rendered content height, in CSS pixels; null until a report arrives. */
  contentHeight: number | null;
  /** Whether the active document has reported its first content height. */
  contentHeightReady: boolean;
  geometry: CanvasGeometry[];
}

export interface HeadlessPreviewContext {
  viewMode?: string;
}

const WAITING_TEXT = Drupal.t(
  'Waiting for the preview to report its draft session…',
);

/**
 * Maps host protocol events to the editor's status line text.
 */
function statusTextFor(
  event: Exclude<HeadlessPreviewHostEvent, { type: 'geometry' }>,
): string {
  switch (event.type) {
    case 'active':
      return Drupal.t(
        'Draft session active — renews automatically around !time.',
        { '!time': new Date(event.tokenExpiresAt).toLocaleTimeString() },
      );
    case 'activation-failed':
      return Drupal.t(
        'The preview could not be started. Are you still logged into Drupal? Reload this page to retry.',
      );
    case 'renewing':
      return Drupal.t('Renewing the draft session…');
    case 'renew-failed':
      return Drupal.t(
        'The draft session could not be renewed. Are you still logged into Drupal? Reload this page to retry.',
      );
    case 'recovering':
      return Drupal.t('Draft session expired — restarting the preview…');
    case 'recovery-failed':
      return Drupal.t(
        'The draft session could not be restarted. Are you still logged into Drupal? Reload this page to retry.',
      );
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
 * edited entity changes, including in-SPA navigation between entities. A
 * successful auto-save asks the app to refresh through the same protocol.
 */
export function useHeadlessDraftSession(
  iframeRef: RefObject<HTMLIFrameElement>,
  settings: HeadlessSettings,
  entityType: string | undefined,
  entityId: string | undefined,
  autoSavesHash?: AutoSavesHashRecord,
  viewportHeight?: number,
  previewContext?: HeadlessPreviewContext,
): HeadlessDraftSession {
  const { frontendOrigin, draftUrl, assertionUrl } = settings;
  const [statusText, setStatusText] = useState(WAITING_TEXT);
  const [geometry, setGeometry] = useState<CanvasGeometry[]>([]);
  const hostRef = useRef<HeadlessPreviewHost | null>(null);
  const lastAutoSavesHashRef = useRef(autoSavesHash);
  const viewportHeightRef = useRef(viewportHeight);
  viewportHeightRef.current = viewportHeight;
  const [contentHeight, setContentHeight] = useState<number | null>(null);
  const [contentHeightReady, setContentHeightReady] = useState(false);

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

  // One host per (iframe, app, entity) combination. HeadlessPreview keeps the
  // current combination alive while a second iframe activates the next entity,
  // then unmounts this hook after the replacement is ready.
  useEffect(() => {
    const iframe = iframeRef.current;
    if (!iframe || !entityType || !entityId) {
      return;
    }
    setStatusText(WAITING_TEXT);
    setContentHeightReady(false);
    setGeometry([]);
    const host = createHeadlessPreviewHost({
      iframe,
      frontendOrigin,
      draftUrl,
      fetchAssertion,
      onEvent: (event) => {
        if (event.type === 'geometry') {
          setGeometry(event.geometry);
        } else {
          setStatusText(statusTextFor(event));
        }
      },
      onHeight: (height) => {
        setContentHeight(height);
        setContentHeightReady(true);
      },
    });
    hostRef.current = host;
    if (viewportHeightRef.current !== undefined) {
      host.setViewportHeight(viewportHeightRef.current);
    }
    void host.activate({
      entity_type: entityType,
      entity: entityId,
      ...(previewContext?.viewMode && {
        view_mode: previewContext.viewMode,
      }),
    });
    return () => {
      if (hostRef.current === host) {
        hostRef.current = null;
      }
      host.destroy();
    };
  }, [
    iframeRef,
    frontendOrigin,
    draftUrl,
    fetchAssertion,
    entityType,
    entityId,
    previewContext?.viewMode,
  ]);

  useEffect(() => {
    if (
      autoSavesHash === undefined ||
      autoSavesHash === lastAutoSavesHashRef.current
    ) {
      return;
    }
    lastAutoSavesHashRef.current = autoSavesHash;
    hostRef.current?.refresh();
  }, [autoSavesHash]);

  useEffect(() => {
    if (viewportHeight === undefined) {
      return;
    }
    setContentHeight(null);
    setContentHeightReady(false);
    hostRef.current?.setViewportHeight(viewportHeight);
  }, [viewportHeight]);

  return { statusText, contentHeight, contentHeightReady, geometry };
}
