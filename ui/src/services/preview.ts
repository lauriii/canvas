import { createApi } from '@reduxjs/toolkit/query/react';
import { baseQuery } from '@/services/baseQuery';
import { setPostPreviewCompleted } from '@/components/review/PublishReview.slice';

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
        url: 'api/preview/{entity_type}/{entity_id}',
        method: 'POST',
        body,
      }),
      async onQueryStarted(arg, { dispatch, queryFulfilled }) {
        try {
          await queryFulfilled;
          dispatch(setPostPreviewCompleted(true));
        } catch (error) {
          console.error('An error occurred while getting preview', error);
        }
      },
    }),
  }),
});

// Export hooks for usage in functional components, which are
// auto-generated based on the defined endpoints
export const { usePostPreviewMutation } = previewApi;
