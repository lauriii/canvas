import { createApi } from '@reduxjs/toolkit/query/react';

import { baseQuery } from '@/services/baseQuery';

import type { IconPack } from '@/types/Icons';

export const iconsApi = createApi({
  reducerPath: 'iconsApi',
  baseQuery,
  tagTypes: ['Icons'],
  endpoints: (builder) => ({
    getIconPacks: builder.query<IconPack[], void>({
      query: () => 'canvas/api/v0/icons',
      providesTags: () => [{ type: 'Icons', id: 'LIST' }],
      transformResponse: (response: { packs: Record<string, IconPack> }) => {
        // Sort packs alphabetically by label.
        const packs = Object.values(response.packs);
        packs.sort((a, b) => a.label.localeCompare(b.label));
        return packs;
      },
    }),
  }),
});

export const { useGetIconPacksQuery } = iconsApi;
