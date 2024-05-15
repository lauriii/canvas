// Need to use the React-specific entry point to import createApi
import { createApi, fetchBaseQuery } from '@reduxjs/toolkit/query/react'
import type { Component } from '../types/Component';

// Define a service using a base URL and expected endpoints
export const componentApi = createApi({
  reducerPath: 'componentsApi',
  baseQuery: fetchBaseQuery({ baseUrl: '/api' }),
  endpoints: (builder) => ({
    getComponentById: builder.query<Component, string>({
      query: (id) => `components/${id}`,
    }),
    getComponents: builder.query<Component[], void>({
      query:() => `components`,
    })
  }),
})

// Export hooks for usage in functional components, which are
// auto-generated based on the defined endpoints
export const { useGetComponentByIdQuery, useGetComponentsQuery } = componentApi
