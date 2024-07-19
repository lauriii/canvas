// Need to use the React-specific entry point to import createApi
import { createApi, fetchBaseQuery } from '@reduxjs/toolkit/query/react';

// Define a service using a base URL and expected endpoints
const { drupalSettings } = window as any;

export const dummyPropsFormApi = createApi({
  reducerPath: 'dummyPropsFormApi',
  baseQuery: fetchBaseQuery({
    baseUrl: `${drupalSettings?.path?.baseUrl || '/'}xb-field-form`,
  }),
  endpoints: (builder) => ({
    getDummyPropsForm: builder.query<string, string>({
      query: (queryString) => ({
        url: `${queryString}`,
        // fetchBaseQuery assumes every Response will get parsed by JSON so we need to add the below.
        responseHandler: 'text',
      }),
    }),
  }),
});

// Export hooks for usage in functional layout, which are
// auto-generated based on the defined endpoints
export const { useGetDummyPropsFormQuery } = dummyPropsFormApi;
