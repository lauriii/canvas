import { createApi } from '@reduxjs/toolkit/query/react';

import { baseQuery } from '@/services/baseQuery';

import type { Extension } from '@/types/Extensions';

export const extensionsApi = createApi({
  reducerPath: 'extensionsApi',
  baseQuery,
  tagTypes: ['Extensions'],
  endpoints: (builder) => ({
    getExtensions: builder.query<Extension[], void>({
      query: () => 'canvas/api/v0/extensions',
      providesTags: () => [{ type: 'Extensions', id: 'LIST' }],
      transformResponse: (response: Record<string, Extension>) => {
        // Sort extensions alphabetically by name.
        const extensions = Object.values(response);
        extensions.sort((a, b) => a.name.localeCompare(b.name));
        return extensions;
      },
    }),
  }),
});

export const { useGetExtensionsQuery } = extensionsApi;
