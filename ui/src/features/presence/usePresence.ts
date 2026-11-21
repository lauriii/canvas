import { useEffect, useMemo, useRef } from 'react';
import { matchPath, useLocation } from 'react-router';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import { selectClientInstanceId } from '@/components/review/PublishReview.slice';
import { selectBaseUrl } from '@/features/configuration/configurationSlice';
import { setPresenceRoster } from '@/features/presence/presenceSlice';
import { selectSelection } from '@/features/ui/uiSlice';
import { fetchCsrfToken } from '@/utils/csrf';

import type { PresenceRoster } from '@/features/presence/presenceSlice';

// @see \Drupal\canvas\Presence\PresenceManager::TTL
export const PRESENCE_TIMING = {
  visibleHeartbeatMs: 5_000,
  hiddenHeartbeatMs: 30_000,
  selectionDebounceMs: 250,
  idleAfterMs: 60_000,
};

export interface PresenceTarget {
  entityType: string;
  entityId: string;
}

/**
 * Maps an editor route to the entity whose auto-save entry it edits.
 */
export const getPresenceTarget = (pathname: string): PresenceTarget | null => {
  const editor = matchPath('/editor/:entityType/:entityId/*', pathname);
  if (editor?.params.entityType && editor.params.entityId) {
    return {
      entityType: editor.params.entityType,
      entityId: editor.params.entityId,
    };
  }
  const template = matchPath(
    '/template/:entityType/:bundle/:viewMode/*',
    pathname,
  );
  if (template) {
    const { entityType, bundle, viewMode } = template.params;
    return {
      entityType: 'content_template',
      entityId: `${entityType}.${bundle}.${viewMode}`,
    };
  }
  const pattern = matchPath('/pattern/:patternId/*', pathname);
  if (pattern?.params.patternId) {
    return { entityType: 'pattern', entityId: pattern.params.patternId };
  }
  const codeComponent =
    matchPath('/code-editor/code/:id', pathname) ??
    matchPath('/code-editor/component/:id', pathname);
  if (codeComponent?.params.id) {
    return { entityType: 'js_component', entityId: codeComponent.params.id };
  }
  return null;
};

const presenceUrl = (baseUrl: string, target: PresenceTarget): string =>
  `${baseUrl}canvas/api/v0/presence/${encodeURIComponent(target.entityType)}/${encodeURIComponent(target.entityId)}`;

/**
 * Keeps this tab's presence session alive and the roster up to date.
 *
 * Presence is informational: failures are ignored and never reach the error
 * screen.
 */
const usePresence = () => {
  const dispatch = useAppDispatch();
  const { pathname } = useLocation();
  const baseUrl = useAppSelector(selectBaseUrl);
  const clientInstanceId = useAppSelector(selectClientInstanceId);
  const selection = useAppSelector(selectSelection).items;

  const target = getPresenceTarget(pathname);
  const targetKey = target ? `${target.entityType}/${target.entityId}` : null;
  // Stable while the target is the same.
  // eslint-disable-next-line react-hooks/exhaustive-deps
  const stableTarget = useMemo(() => target, [targetKey]);

  const selectionRef = useRef(selection);
  selectionRef.current = selection;
  // Set on mount; Date.now() must not run during render.
  const lastInputRef = useRef(0);
  const sendNowRef = useRef<() => void>(() => {});

  useEffect(() => {
    lastInputRef.current = Date.now();
    const onInput = () => {
      const wasIdle =
        Date.now() - lastInputRef.current > PRESENCE_TIMING.idleAfterMs;
      lastInputRef.current = Date.now();
      if (wasIdle) {
        sendNowRef.current();
      }
    };
    const events = ['pointerdown', 'pointermove', 'keydown', 'wheel'];
    events.forEach((name) =>
      window.addEventListener(name, onInput, { passive: true }),
    );
    return () =>
      events.forEach((name) => window.removeEventListener(name, onInput));
  }, []);

  useEffect(() => {
    if (!stableTarget) {
      dispatch(setPresenceRoster(null));
      return;
    }
    const url = presenceUrl(baseUrl, stableTarget);
    let timer: ReturnType<typeof setTimeout> | undefined;
    let stopped = false;

    const isActive = () =>
      document.visibilityState === 'visible' &&
      Date.now() - lastInputRef.current < PRESENCE_TIMING.idleAfterMs;

    const schedule = () => {
      clearTimeout(timer);
      if (stopped) {
        return;
      }
      timer = setTimeout(
        send,
        document.visibilityState === 'visible'
          ? PRESENCE_TIMING.visibleHeartbeatMs
          : PRESENCE_TIMING.hiddenHeartbeatMs,
      );
    };

    async function send() {
      clearTimeout(timer);
      try {
        const response = await fetch(url, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': await fetchCsrfToken(baseUrl),
          },
          body: JSON.stringify({
            clientInstanceId,
            selection: selectionRef.current,
            active: isActive(),
          }),
        });
        if (!stopped) {
          dispatch(
            setPresenceRoster(
              response.ok ? ((await response.json()) as PresenceRoster) : null,
            ),
          );
        }
      } catch {
        // Offline or the server is unreachable; try again on the next beat.
      }
      schedule();
    }

    const leave = () => {
      // keepalive, unlike sendBeacon(), can carry the CSRF header.
      fetchCsrfToken(baseUrl)
        .then((token) =>
          fetch(
            `${url}?clientInstanceId=${encodeURIComponent(clientInstanceId)}`,
            {
              method: 'DELETE',
              headers: { 'X-CSRF-Token': token },
              keepalive: true,
            },
          ),
        )
        .catch(() => {});
    };

    const onVisibilityChange = () => {
      void send();
    };
    sendNowRef.current = () => void send();
    document.addEventListener('visibilitychange', onVisibilityChange);
    window.addEventListener('pagehide', leave);
    void send();

    return () => {
      stopped = true;
      clearTimeout(timer);
      sendNowRef.current = () => {};
      document.removeEventListener('visibilitychange', onVisibilityChange);
      window.removeEventListener('pagehide', leave);
      leave();
      dispatch(setPresenceRoster(null));
    };
  }, [baseUrl, clientInstanceId, dispatch, stableTarget]);

  // Others see selection changes right away, not on the next beat.
  const selectionKey = selection.join(',');
  useEffect(() => {
    const timer = setTimeout(
      () => sendNowRef.current(),
      PRESENCE_TIMING.selectionDebounceMs,
    );
    return () => clearTimeout(timer);
  }, [selectionKey]);
};

export default usePresence;
