import { createSlice, nanoid } from '@reduxjs/toolkit';

import type { PayloadAction } from '@reduxjs/toolkit';
import type { Notification } from '@/services/notificationsApi';

/**
 * Drupal Messenger payload returned by the Canvas layout preview JSON.
 *
 * @see src/Render/MainContent/CanvasPreviewRenderer.php
 */
export interface DrupalPreviewMessage {
  type: string;
  message: string;
}

interface PreviewMessagesState {
  /**
   * Transient, client-only preview-message notifications rendered as toasts.
   *
   * Persisted only in memory; these are intentionally not sent to the
   * notifications REST API so they do not appear in the notification panel.
   */
  notifications: Notification[];
}

const initialState: PreviewMessagesState = {
  notifications: [],
};

/** Map Drupal Messenger types to the Notification shape used by toasts. */
const TYPE_BY_MESSENGER: Record<string, Notification['type']> = {
  status: 'info',
  warning: 'warning',
  error: 'error',
};

const TITLE_BY_TYPE: Record<Notification['type'], string> = {
  processing: 'Processing',
  success: 'Success',
  info: 'Notice',
  warning: 'Warning',
  error: 'Error',
};

/**
 * Converts the admin-safe HTML produced by the server into plain text for the
 * toast. The server already strips dangerous markup via Xss::filterAdmin, so
 * this only strips the remaining inline tags for display purposes.
 */
function htmlToPlainText(html: string): string {
  if (typeof DOMParser === 'undefined') {
    // Fallback: crude tag stripping.
    return html
      .replace(/<[^>]*>/g, ' ')
      .replace(/\s+/g, ' ')
      .trim();
  }
  const doc = new DOMParser().parseFromString(html, 'text/html');
  return (doc.body.textContent || '').replace(/\s+/g, ' ').trim();
}

function toNotification(entry: DrupalPreviewMessage): Notification | null {
  const type = TYPE_BY_MESSENGER[entry.type] ?? 'info';
  const message = htmlToPlainText(entry.message);
  if (!message) {
    return null;
  }
  return {
    id: `drupal-preview-${nanoid()}`,
    type,
    key: null,
    title: TITLE_BY_TYPE[type],
    message,
    timestamp: Date.now(),
    hasRead: false,
    actions: null,
  };
}

export const previewMessagesSlice = createSlice({
  name: 'previewMessages',
  initialState,
  reducers: {
    addDrupalPreviewMessages: {
      reducer(state, action: PayloadAction<Notification[]>) {
        state.notifications.unshift(...action.payload);
      },
      prepare(messages: DrupalPreviewMessage[] | undefined) {
        const notifications = (messages ?? [])
          .map(toNotification)
          .filter((n): n is Notification => n !== null);
        return { payload: notifications };
      },
    },
    removeDrupalPreviewMessage(state, action: PayloadAction<string>) {
      state.notifications = state.notifications.filter(
        (n) => n.id !== action.payload,
      );
    },
    clearDrupalPreviewMessages(state) {
      state.notifications = [];
    },
  },
  selectors: {
    selectDrupalPreviewMessages: (state) => state.notifications,
  },
});

export const {
  addDrupalPreviewMessages,
  removeDrupalPreviewMessage,
  clearDrupalPreviewMessages,
} = previewMessagesSlice.actions;
export const { selectDrupalPreviewMessages } = previewMessagesSlice.selectors;
