// Need to use the React-specific entry point to import createApi
import { createApi, fetchBaseQuery } from '@reduxjs/toolkit/query/react';
import type { Component } from '../types/Component';

// Define a service using a base URL and expected endpoints
export const componentApi = createApi({
  reducerPath: 'componentsApi',
  baseQuery: fetchBaseQuery({ baseUrl: '/' }),
  endpoints: (builder) => ({
    getComponentById: builder.query<Component, string>({
      query: (id) => ['', '80'].includes(window.location.port) ? `xb-component/${id}` : `api/components${id}`,
    }),
    getComponents: builder.query<Component[], void>({
      query: () =>
        ['', '80'].includes(window.location.port) ? `xb-components` : 'api/components'
      ,
    }),
  }),
});

// Export hooks for usage in functional components, which are
// auto-generated based on the defined endpoints
export const { useGetComponentByIdQuery, useGetComponentsQuery } = componentApi;
