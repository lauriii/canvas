import { createSlice, nanoid } from '@reduxjs/toolkit';

import type { PayloadAction } from '@reduxjs/toolkit';
import type { Notification } from '@/services/notificationsApi';

/**
 * A status message returned by a layout preview response.
 *
 * @see \Drupal\canvas\Render\MainContent\CanvasPreviewRenderer::collectMessages()
 */
export interface PreviewMessage {
  type: string;
  message: string;
}

/**
 * Preview messages are shown as a toast and then forgotten. They are kept out
 * of notificationsApi on purpose, so they do not reach the activity center.
 */
interface PreviewMessagesState {
  notifications: Notification[];
}

const initialState: PreviewMessagesState = {
  notifications: [],
};

const TYPE_BY_MESSENGER_TYPE: Record<string, Notification['type']> = {
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

function toNotification({ type, message }: PreviewMessage): Notification {
  const notificationType = TYPE_BY_MESSENGER_TYPE[type] ?? 'info';
  return {
    id: `preview-message-${nanoid()}`,
    type: notificationType,
    key: null,
    title: TITLE_BY_TYPE[notificationType],
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
    addPreviewMessages: {
      reducer(state, action: PayloadAction<Notification[]>) {
        // A preview is requested on every edit, and Drupal only deduplicates
        // messages within one request, and only when they are not repeated on
        // purpose. Do not stack a message that is already shown, or one the
        // same response returned twice.
        const shown = new Set(
          state.notifications.map(({ type, message }) => `${type}:${message}`),
        );
        state.notifications.unshift(
          ...action.payload.filter(({ type, message }) => {
            const key = `${type}:${message}`;
            if (shown.has(key)) {
              return false;
            }
            shown.add(key);
            return true;
          }),
        );
      },
      prepare: (messages: PreviewMessage[] = []) => ({
        payload: messages.filter(({ message }) => message).map(toNotification),
      }),
    },
    removePreviewMessage(state, action: PayloadAction<string>) {
      state.notifications = state.notifications.filter(
        (notification) => notification.id !== action.payload,
      );
    },
  },
  selectors: {
    selectPreviewMessages: (state) => state.notifications,
  },
});

export const { addPreviewMessages, removePreviewMessage } =
  previewMessagesSlice.actions;
export const { selectPreviewMessages } = previewMessagesSlice.selectors;
