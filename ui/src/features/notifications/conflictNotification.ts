import { useEffect, useState } from 'react';

import { getConflictRouteForChange } from '@/features/conflict/conflictUtils';
import { useGetAllPendingChangesQuery } from '@/services/pendingChangesApi';

import type { Notification } from '@/services/notificationsApi';
import type {
  PendingChange,
  PendingChanges,
} from '@/services/pendingChangesApi';

export const CONFLICT_NOTIFICATION_KEY = 'canvas-conflict';
export const CONFLICT_NOTIFICATION_ACTION_HREF = '/conflict';

const STORAGE_KEY = 'DrupalCanvasConflictNotificationState';
const STORAGE_EVENT = 'canvas:conflictNotificationStateChange';

export interface ConflictNotificationStorageState {
  shownFingerprint?: string;
  readFingerprint?: string;
  shownItemFingerprints?: string[];
  readItemFingerprints?: string[];
}

interface ConflictChange extends PendingChange {
  pointer: string;
}

export interface ConflictNotification extends Notification {
  fingerprint: string;
  itemFingerprints: string[];
}

const getConflictedChanges = (changes?: PendingChanges): ConflictChange[] =>
  Object.entries(changes ?? {})
    .filter(([, change]) => change.hasConflict)
    .map(([pointer, change]) => ({
      ...change,
      pointer,
    }))
    .sort(
      (a, b) => b.updated - a.updated || a.pointer.localeCompare(b.pointer),
    );

// A conflict notification is unique to the current conflict state. If any of
// these values change, the conflict should be surfaced again.
const buildConflictItemFingerprint = (change: ConflictChange): string =>
  [
    change.pointer,
    change.entity_type,
    String(change.entity_id),
    change.langcode,
    change.data_hash,
    change.conflict_id ?? '',
    change.label,
    String(change.updated),
  ].join(':');

const buildFingerprint = (changes: ConflictChange[]): string =>
  changes.map(buildConflictItemFingerprint).join('|');

const uniqueFingerprints = (fingerprints: string[]): string[] =>
  Array.from(new Set(fingerprints));

// Track individual conflicts so resolving one item does not re-show the rest.
const hasStoredEveryCurrentItem = (
  storedFingerprints: string[] | undefined,
  currentFingerprints: string[],
): boolean =>
  currentFingerprints.length > 0 &&
  currentFingerprints.every(
    (fingerprint) => storedFingerprints?.includes(fingerprint) ?? false,
  );

const mergeConflictItemFingerprints = (
  existing: string[] | undefined,
  current: string[],
): string[] => uniqueFingerprints([...(existing ?? []), ...current]);

const hashFingerprint = (fingerprint: string): string => {
  let hash = 0;
  for (let i = 0; i < fingerprint.length; i += 1) {
    hash = Math.imul(31, hash) + fingerprint.charCodeAt(i);
  }
  return (hash >>> 0).toString(36);
};

const readNotificationStorageState = (): ConflictNotificationStorageState => {
  try {
    const value = window.localStorage.getItem(STORAGE_KEY);
    return value ? (JSON.parse(value) as ConflictNotificationStorageState) : {};
  } catch {
    return {};
  }
};

const writeNotificationStorageState = (
  state: ConflictNotificationStorageState,
): void => {
  try {
    window.localStorage.setItem(STORAGE_KEY, JSON.stringify(state));
    // Keep same-tab notification surfaces in sync after localStorage changes.
    window.dispatchEvent(new Event(STORAGE_EVENT));
  } catch {
    // Storage can be unavailable in private browsing or restricted embeds.
  }
};

const getStateWithShownConflict = (
  storageState: ConflictNotificationStorageState,
  notification: ConflictNotification,
): ConflictNotificationStorageState => ({
  ...storageState,
  shownFingerprint: notification.fingerprint,
  shownItemFingerprints: mergeConflictItemFingerprints(
    storageState.shownItemFingerprints,
    notification.itemFingerprints,
  ),
});

export const shouldShowConflictNotificationToast = (
  notification: ConflictNotification,
): boolean => {
  const storageState = readNotificationStorageState();
  if (storageState.shownFingerprint === notification.fingerprint) {
    return false;
  }

  return !hasStoredEveryCurrentItem(
    storageState.shownItemFingerprints,
    notification.itemFingerprints,
  );
};

export const markConflictNotificationShown = (
  notification: ConflictNotification,
): void => {
  const storageState = readNotificationStorageState();
  writeNotificationStorageState(
    getStateWithShownConflict(storageState, notification),
  );
};

export const markConflictNotificationRead = (
  notification: ConflictNotification,
): void => {
  const storageState = readNotificationStorageState();
  const shownState = getStateWithShownConflict(storageState, notification);
  writeNotificationStorageState({
    ...shownState,
    readFingerprint: notification.fingerprint,
    readItemFingerprints: mergeConflictItemFingerprints(
      storageState.readItemFingerprints,
      notification.itemFingerprints,
    ),
  });
};

export const createConflictNotification = (
  changes?: PendingChanges,
  storageState: ConflictNotificationStorageState = readNotificationStorageState(),
): ConflictNotification | undefined => {
  const conflictedChanges = getConflictedChanges(changes);
  if (conflictedChanges.length === 0) return undefined;
  const firstConflictedChange = conflictedChanges[0];
  if (!firstConflictedChange) return undefined;

  const fingerprint = buildFingerprint(conflictedChanges);
  const itemFingerprints = conflictedChanges.map(buildConflictItemFingerprint);
  const latestUpdated = Math.max(
    ...conflictedChanges.map((change) => change.updated),
  );

  return {
    id: `${CONFLICT_NOTIFICATION_KEY}:${hashFingerprint(fingerprint)}`,
    type: 'warning',
    key: CONFLICT_NOTIFICATION_KEY,
    title: Drupal.t('Conflict detected'),
    message: Drupal.t('One or more Canvas auto-save items have conflicts.'),
    timestamp: latestUpdated * 1000,
    hasRead:
      storageState.readFingerprint === fingerprint ||
      hasStoredEveryCurrentItem(
        storageState.readItemFingerprints,
        itemFingerprints,
      ),
    actions: [
      {
        label: Drupal.t('Resolve conflicts'),
        href: getConflictRouteForChange(firstConflictedChange),
      },
    ],
    fingerprint,
    itemFingerprints,
  };
};

export const isConflictNotification = (id: string): boolean =>
  id.startsWith(`${CONFLICT_NOTIFICATION_KEY}:`);

export const useConflictNotification = (enabled = true) => {
  const { data: pendingChanges } = useGetAllPendingChangesQuery(undefined, {
    skip: !enabled,
  });
  const [, setStorageVersion] = useState(0);

  useEffect(() => {
    const onStorageChange = () => setStorageVersion((version) => version + 1);
    window.addEventListener(STORAGE_EVENT, onStorageChange);
    return () => window.removeEventListener(STORAGE_EVENT, onStorageChange);
  }, []);

  const storageState = readNotificationStorageState();
  const notification = createConflictNotification(pendingChanges, storageState);

  const markShown = (target?: ConflictNotification) => {
    const targetNotification = target ?? notification;
    if (targetNotification) {
      markConflictNotificationShown(targetNotification);
    }
  };

  const markRead = (target?: ConflictNotification) => {
    const targetNotification = target ?? notification;
    if (targetNotification) {
      markConflictNotificationRead(targetNotification);
    }
  };

  return {
    notification,
    markRead,
    markShown,
    shouldShowToast: notification
      ? shouldShowConflictNotificationToast(notification)
      : false,
  };
};
