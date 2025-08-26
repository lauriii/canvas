import { createApi } from '@reduxjs/toolkit/query/react';
import { baseQuery } from './baseQuery';

import type { Segment } from '@/types/Personalization';

export const personalizationApi = createApi({
  reducerPath: 'personalizationApi',
  baseQuery,
  tagTypes: ['Segment', 'SegmentAutoSave'],
  endpoints: (builder) => ({
    // Get all segments
    getSegments: builder.query<Record<string, Segment>, void>({
      query: () => '/xb/api/v0/config/segment',
      providesTags: [{ type: 'Segment', id: 'LIST' }],
    }),

    // Get individual segment
    getSegment: builder.query<Segment, string>({
      query: (id) => `/xb/api/v0/config/segment/${id}`,
      providesTags: (result, error, id) => [{ type: 'Segment', id }],
    }),

    // Create new segment
    createSegment: builder.mutation<Segment, Partial<Segment>>({
      query: (segment) => ({
        url: '/xb/api/v0/config/segment',
        method: 'POST',
        body: segment,
      }),
      invalidatesTags: [{ type: 'Segment', id: 'LIST' }],
    }),

    // Update segment
    updateSegment: builder.mutation<
      Segment,
      { id: string; changes: Partial<Segment> }
    >({
      query: ({ id, changes }) => ({
        url: `/xb/api/v0/config/segment/${id}`,
        method: 'PATCH',
        body: { ...changes, id },
      }),
      invalidatesTags: (result, error, { id }) => [
        { type: 'Segment', id },
        { type: 'SegmentAutoSave', id },
        { type: 'Segment', id: 'LIST' },
      ],
    }),

    // Delete segment
    deleteSegment: builder.mutation<void, string>({
      query: (id) => ({
        url: `/xb/api/v0/config/segment/${id}`,
        method: 'DELETE',
      }),
      invalidatesTags: (result, error, id) => [
        { type: 'Segment', id: 'LIST' },
        { type: 'SegmentAutoSave', id },
      ],
    }),
  }),
});

export const {
  useGetSegmentsQuery,
  useGetSegmentQuery,
  useCreateSegmentMutation,
  useUpdateSegmentMutation,
  useDeleteSegmentMutation,
} = personalizationApi;
