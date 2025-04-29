// Need to use the React-specific entry point to import createApi
import { createApi } from '@reduxjs/toolkit/query/react';
import { baseQuery } from '@/services/baseQuery';
import type { LayoutModelPiece } from '@/features/layout/layoutModelSlice';
import type { SectionsList } from '@/types/Section';

interface SaveSectionData extends LayoutModelPiece {
  name: string;
}

// Define a service using a base URL and expected endpoints
export const sectionApi = createApi({
  reducerPath: 'sectionsApi',
  baseQuery,
  tagTypes: ['Sections'],
  endpoints: (builder) => ({
    getSections: builder.query<SectionsList, void>({
      query: () => `/xb/api/v0/config/pattern`,
      providesTags: () => [{ type: 'Sections', id: 'LIST' }],
    }),
    saveSection: builder.mutation<{ html: string }, SaveSectionData>({
      query: (body) => ({
        url: '/xb/api/v0/config/pattern',
        method: 'POST',
        body,
      }),
      invalidatesTags: () => [{ type: 'Sections', id: 'LIST' }],
    }),
    deleteSection: builder.mutation<void, string>({
      query: (id) => ({
        url: `/xb/api/v0/config/pattern/${id}`,
        method: 'DELETE',
      }),
      invalidatesTags: () => [{ type: 'Sections', id: 'LIST' }],
    }),
  }),
});

// Export hooks for usage in functional sections, which are
// auto-generated based on the defined endpoints
export const {
  useGetSectionsQuery,
  useSaveSectionMutation,
  useDeleteSectionMutation,
} = sectionApi;
