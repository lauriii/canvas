import { createApi } from '@reduxjs/toolkit/query/react';
import { baseQuery } from '@/services/baseQuery';
import { setPostPreviewCompleted } from '@/components/review/PublishReview.slice';
import type {
  ComponentModel,
  EvaluatedComponentModel,
} from '@/features/layout/layoutModelSlice';
import { setLayoutModel } from '@/features/layout/layoutModelSlice';
import { setHtml } from '@/features/pagePreview/previewSlice';
import type { ConflictError } from '@/services/pendingChangesApi';
import { createSelector } from '@reduxjs/toolkit';
import type { RootState } from '@/app/store';

type UpdateComponentResultType = {
  html: string;
  layout: any;
  model: any;
  errors?: Array<ConflictError>;
};

type UpdateComponentQueryArg = {
  componentInstanceUuid: string;
  componentType: string;
  model: Omit<ComponentModel, 'name'> | Omit<EvaluatedComponentModel, 'name'>;
};

// Define a service using a base URL and expected endpoints
export const previewApi = createApi({
  reducerPath: 'previewApi',
  baseQuery,
  endpoints: (builder) => ({
    postPreview: builder.mutation<
      { html: string },
      { layout: any; model: any; entity_form_fields: any }
    >({
      query: (body) => ({
        url: 'xb/api/layout/{entity_type}/{entity_id}',
        method: 'POST',
        body,
      }),
      async onQueryStarted(arg, { dispatch, queryFulfilled }) {
        try {
          const { data } = await queryFulfilled;
          const { html } = data;
          // Update our preview slice.
          dispatch(setHtml(html));
          dispatch(setPostPreviewCompleted(true));
        } catch (error) {
          console.error('An error occurred while getting preview', error);
        }
      },
    }),
    updateComponent: builder.mutation<
      UpdateComponentResultType,
      UpdateComponentQueryArg
    >({
      query: (body) => ({
        url: 'xb/api/layout/{entity_type}/{entity_id}',
        method: 'PATCH',
        body,
      }),
      async onQueryStarted(body, { dispatch, queryFulfilled }) {
        try {
          const { data } = await queryFulfilled;
          const { html, layout, model } = data;
          dispatch(setHtml(html));
          // Pass initialized false to prevent a subsequent preview update, we
          // have the data here.
          dispatch(setLayoutModel({ layout, model, initialized: false }));
        } catch {
          // @todo display errors to the user in https://www.drupal.org/i/3505018.
        }
      },
    }),
  }),
});

export const { usePostPreviewMutation, useUpdateComponentMutation } =
  previewApi;

// A selector that returns the current updateComponent mutation loading state
// given a component ID.
// For each API endpoint, RTK Query makes a .select method available allowing
// you to select the current state given a cache key. This returns a new
// function every time. As a result we must use createSelector to memoize it.
// @see https://redux-toolkit.js.org/rtk-query/usage/usage-without-react-hooks
const createUpdateComponentSelector = createSelector(
  (componentInstanceId: string) => componentInstanceId,
  (componentInstanceId) =>
    previewApi.endpoints.updateComponent.select({
      fixedCacheKey: componentInstanceId,
      requestId: undefined,
    }),
);

// A selector that can be called from anywhere in the code base to
// determine the current update mutation loading state given a component
// instance ID. As createUpdateComponentSelector is memoized, we must also use
// createSelector here so that the subsequent selector is memoised.
// @see https://redux-toolkit.js.org/rtk-query/usage/usage-without-react-hooks
// @see https://redux.js.org/tutorials/fundamentals/part-7-standard-patterns#memoizing-selectors-with-createselector
export const selectUpdateComponentLoadingState = createSelector(
  (state: RootState) => state,
  (state: RootState, componentInstanceId: string) =>
    createUpdateComponentSelector(componentInstanceId),
  (state, selectUpdateComponentSelector) =>
    selectUpdateComponentSelector(state).isLoading,
);
