import { createApi } from "@reduxjs/toolkit/query/react";
import { baseQuery } from "@/services/baseQuery";

// Define a service using a base URL and expected endpoints
export const previewApi = createApi({
  reducerPath: 'previewApi',
  baseQuery,
  endpoints: (builder) => ({
    postPreview: builder.mutation<
      { html: string },
      { layout: any; model: any }
    >({
      query: (body) => ({
        url: 'api/preview',
        method: 'POST',
        body,
      }),
    }),
  }),
});

// Export hooks for usage in functional components, which are
// auto-generated based on the defined endpoints
export const { usePostPreviewMutation } = previewApi;
