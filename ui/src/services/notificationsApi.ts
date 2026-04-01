import { createApi } from '@reduxjs/toolkit/query/react';

import { baseQuery } from '@/services/baseQuery';

export interface NotificationAction {
  label: string;
  href: string;
}

export interface Notification {
  id: string;
  type: 'processing' | 'success' | 'info' | 'warning' | 'error';
  key: string | null;
  title: string;
  message: string;
  timestamp: number;
  hasRead: boolean;
  actions: NotificationAction[] | null;
}

interface NotificationsResponse {
  data: {
    notifications: Notification[];
  };
}

export const notificationsApi = createApi({
  reducerPath: 'notificationsApi',
  baseQuery,
  endpoints: (builder) => ({
    getNotifications: builder.query<NotificationsResponse, void>({
      query: () => '/canvas/api/v0/notifications',
    }),
    markNotificationsRead: builder.mutation<void, { ids: string[] }>({
      query: (body) => ({
        url: '/canvas/api/v0/notifications/read',
        method: 'POST',
        body,
      }),
      async onQueryStarted({ ids }, { dispatch, queryFulfilled }) {
        const patch = dispatch(
          notificationsApi.util.updateQueryData(
            'getNotifications',
            undefined,
            (draft) => {
              for (const notification of draft.data.notifications) {
                if (ids.includes(notification.id)) {
                  notification.hasRead = true;
                }
              }
            },
          ),
        );
        try {
          await queryFulfilled;
        } catch {
          patch.undo();
        }
      },
    }),
  }),
});

export const { useGetNotificationsQuery, useMarkNotificationsReadMutation } =
  notificationsApi;
