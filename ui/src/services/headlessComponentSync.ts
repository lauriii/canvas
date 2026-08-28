import { createApi } from '@reduxjs/toolkit/query/react';

import { baseQuery } from '@/services/baseQuery';
import { componentAndLayoutApi } from '@/services/componentAndLayout';
import { headlessFrontendsApi } from '@/services/headlessFrontends';
import { notificationsApi } from '@/services/notificationsApi';

import type { Dispatch } from '@reduxjs/toolkit';

const SYNC_ENDPOINT_BASE = '/canvas/api/v0/headless/components/sync';

export interface ComponentSyncResult {
  created: number;
  updated: number;
  unchanged: number;
  warnings: string[];
  errors: string[];
}

interface StartResponse {
  assertion: string;
  metadataPath: string;
}

interface CompleteResponse {
  result: ComponentSyncResult;
}

export const headlessComponentSyncApi = createApi({
  reducerPath: 'headlessComponentSyncApi',
  baseQuery,
  endpoints: (builder) => ({
    syncComponents: builder.mutation<ComponentSyncResult, string>({
      async queryFn(frontendUrl, { dispatch }, _extraOptions, runBaseQuery) {
        const start = await runBaseQuery({
          url: `${SYNC_ENDPOINT_BASE}/start`,
          method: 'POST',
          body: { frontendUrl },
        });
        refreshNotifications(dispatch);
        if (start.error) {
          return { error: start.error };
        }

        const startData = start.data as StartResponse | undefined;
        const assertion = startData?.assertion;
        const metadataPath = startData?.metadataPath;
        if (typeof assertion !== 'string' || typeof metadataPath !== 'string') {
          return {
            error: {
              status: 'CUSTOM_ERROR',
              error: 'The synchronization endpoint returned no assertion.',
            },
          };
        }

        let payload: unknown;
        try {
          const response = await fetch(`${frontendUrl}${metadataPath}`, {
            credentials: 'omit',
            headers: {
              Accept: 'application/json',
              Authorization: `Bearer ${assertion}`,
            },
          });
          if (!response.ok) {
            throw new Error(
              `The metadata endpoint answered ${response.status}.`,
            );
          }
          payload = await response.json();
        } catch (error) {
          const message =
            error instanceof Error
              ? error.message
              : 'The component metadata request failed.';
          await runBaseQuery({
            url: `${SYNC_ENDPOINT_BASE}/fail`,
            method: 'POST',
            body: { frontendUrl, message },
          });
          refreshNotifications(dispatch);
          return {
            error: { status: 'CUSTOM_ERROR', error: message },
          };
        }

        const complete = await runBaseQuery({
          url: `${SYNC_ENDPOINT_BASE}/complete`,
          method: 'POST',
          body: { frontendUrl, payload },
        });
        refreshNotifications(dispatch);
        if (complete.error) {
          return { error: complete.error };
        }

        dispatch(
          componentAndLayoutApi.util.invalidateTags([
            { type: 'Components', id: 'LIST' },
            { type: 'CodeComponents', id: 'LIST' },
          ]),
        );
        dispatch(
          headlessFrontendsApi.util.invalidateTags([
            { type: 'HeadlessFrontends', id: 'LIST' },
          ]),
        );
        return {
          data: (complete.data as CompleteResponse).result,
        };
      },
    }),
  }),
});

const refreshNotifications = (dispatch: Dispatch) => {
  dispatch(
    notificationsApi.util.invalidateTags([
      { type: 'Notifications', id: 'LIST' },
    ]),
  );
};

export const { useSyncComponentsMutation } = headlessComponentSyncApi;
