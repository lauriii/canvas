import {
  useCallback,
  useEffect,
  useRef,
  useState,
  useSyncExternalStore,
} from 'react';
import {
  CANVAS_COMPONENT_PREVIEW_PATH,
  CANVAS_COMPONENT_PREVIEW_QUERY,
  createHeadlessPreviewHost,
} from '@drupal-canvas/headless-host';

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
  componentPreviewId?: string;
}

const WAITING_TEXT = 'Waiting for the preview to report its draft session…';

// HeadlessPreview keeps its current and pending frames mounted while changing
// routes. Track their owners so one frame's cleanup cannot hide a session that
// the other frame has already reported as active.
const activeMainPreviewOwners = new Map<string, Set<symbol>>();
const mainPreviewListeners = new Map<string, Set<() => void>>();

function hasActiveMainPreview(frontendKey: string): boolean {
  return (activeMainPreviewOwners.get(frontendKey)?.size ?? 0) > 0;
}

function subscribeToMainPreview(
  frontendKey: string,
  listener: () => void,
): () => void {
  const listeners = mainPreviewListeners.get(frontendKey) ?? new Set();
  listeners.add(listener);
  mainPreviewListeners.set(frontendKey, listeners);
  return () => {
    listeners.delete(listener);
    if (listeners.size === 0) {
      mainPreviewListeners.delete(frontendKey);
    }
  };
}

function setMainPreviewActive(
  frontendKey: string,
  owner: symbol,
  active: boolean,
): void {
  const wasActive = hasActiveMainPreview(frontendKey);
  const owners = activeMainPreviewOwners.get(frontendKey) ?? new Set();
  if (active) {
    owners.add(owner);
    activeMainPreviewOwners.set(frontendKey, owners);
  } else {
    owners.delete(owner);
    if (owners.size === 0) {
      activeMainPreviewOwners.delete(frontendKey);
    }
  }
  if (wasActive !== hasActiveMainPreview(frontendKey)) {
    mainPreviewListeners.get(frontendKey)?.forEach((listener) => listener());
  }
}

/**
 * Maps host protocol events to the editor's status line text.
 */
function statusTextFor(
  event: Exclude<HeadlessPreviewHostEvent, { type: 'geometry' }>,
): string {
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
  const { frontendUrl, frontendOrigin, draftUrl, assertionUrl } = settings;
  const mainPreviewOwnerRef = useRef(Symbol('canvas-headless-main-preview'));
  const isComponentPreview = Boolean(previewContext?.componentPreviewId);
  const subscribeToCurrentMainPreview = useCallback(
    (listener: () => void) => subscribeToMainPreview(draftUrl, listener),
    [draftUrl],
  );
  const getMainPreviewSnapshot = useCallback(
    () => !isComponentPreview || hasActiveMainPreview(draftUrl),
    [draftUrl, isComponentPreview],
  );
  const mainPreviewActive = useSyncExternalStore(
    subscribeToCurrentMainPreview,
    getMainPreviewSnapshot,
    () => !isComponentPreview,
  );
  const sessionKey = JSON.stringify([
    frontendUrl,
    frontendOrigin,
    draftUrl,
    assertionUrl,
    entityType,
    entityId,
    previewContext?.viewMode,
    previewContext?.componentPreviewId,
  ]);
  const [statusText, setStatusText] = useState(WAITING_TEXT);
  const [geometry, setGeometry] = useState<CanvasGeometry[]>([]);
  const [geometrySessionKey, setGeometrySessionKey] = useState<string | null>(
    null,
  );
  const hostRef = useRef<HeadlessPreviewHost | null>(null);
  const lastAutoSavesHashRef = useRef(autoSavesHash);
  const viewportHeightRef = useRef(viewportHeight);
  viewportHeightRef.current = viewportHeight;
  const [contentHeight, setContentHeight] = useState<number | null>(null);
  const [contentHeightReady, setContentHeightReady] = useState(false);
  const [heightSessionKey, setHeightSessionKey] = useState<string | null>(null);

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
    if (
      !iframe ||
      !entityType ||
      !entityId ||
      (isComponentPreview && !mainPreviewActive)
    ) {
      return;
    }
    const mainPreviewOwner = mainPreviewOwnerRef.current;
    if (!isComponentPreview) {
      setMainPreviewActive(draftUrl, mainPreviewOwner, false);
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
          setGeometrySessionKey(sessionKey);
        } else {
          if (!isComponentPreview) {
            if (event.type === 'active') {
              setMainPreviewActive(draftUrl, mainPreviewOwner, true);
            } else if (
              event.type === 'activation-failed' ||
              event.type === 'renew-failed' ||
              event.type === 'recovering' ||
              event.type === 'recovery-failed'
            ) {
              setMainPreviewActive(draftUrl, mainPreviewOwner, false);
            }
          }
          setStatusText(statusTextFor(event));
        }
      },
      onHeight: (height) => {
        setContentHeight(height);
        setContentHeightReady(true);
        setHeightSessionKey(sessionKey);
      },
    });
    hostRef.current = host;
    if (viewportHeightRef.current !== undefined) {
      host.setViewportHeight(viewportHeightRef.current);
    }
    if (previewContext?.componentPreviewId) {
      const previewUrl = new URL(
        `${frontendUrl}${CANVAS_COMPONENT_PREVIEW_PATH}`,
      );
      previewUrl.searchParams.set(
        CANVAS_COMPONENT_PREVIEW_QUERY,
        previewContext.componentPreviewId,
      );
      host.attach(previewUrl.toString());
    } else {
      void host.activate({
        ...(entityType && { entity_type: entityType }),
        ...(entityId && { entity: entityId }),
        ...(previewContext?.viewMode && {
          view_mode: previewContext.viewMode,
        }),
      });
    }
    return () => {
      if (!isComponentPreview) {
        setMainPreviewActive(draftUrl, mainPreviewOwner, false);
      }
      if (hostRef.current === host) {
        hostRef.current = null;
      }
      host.destroy();
    };
  }, [
    iframeRef,
    frontendUrl,
    frontendOrigin,
    draftUrl,
    fetchAssertion,
    entityType,
    entityId,
    isComponentPreview,
    mainPreviewActive,
    previewContext?.viewMode,
    previewContext?.componentPreviewId,
    sessionKey,
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

  const currentSessionHeight =
    heightSessionKey === sessionKey ? contentHeight : null;
  const currentSessionGeometry =
    geometrySessionKey === sessionKey &&
    (!isComponentPreview || mainPreviewActive)
      ? geometry
      : [];
  return {
    statusText,
    contentHeight: currentSessionHeight,
    contentHeightReady: heightSessionKey === sessionKey && contentHeightReady,
    geometry: currentSessionGeometry,
  };
}
