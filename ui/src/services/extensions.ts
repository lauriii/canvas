// Need to use the React-specific entry point to import createApi
import { createApi } from '@reduxjs/toolkit/query/react';
import { baseQuery } from '@/services/baseQuery';
import type { ExtensionsList } from '@/types/Extensions';

const kittenBase64 =
  /* cspell:disable-next-line */
  'data:image/jpeg;base64, /9j/2wBDAAYEBQYFBAYGBQYHBwYIChAKCgkJChQODwwQFxQYGBcUFhYaHSUfGhsjHBYWICwgIyYnKSopGR8tMC0oMCUoKSj/2wBDAQcHBwoIChMKChMoGhYaKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCj/wAARCAAYABgDASIAAhEBAxEB/8QAGAABAAMBAAAAAAAAAAAAAAAAAAMGBwX/xAAlEAABBAEDBAIDAAAAAAAAAAABAgMEEQAFEiEGBzFBE1EUFSL/xAAYAQADAQEAAAAAAAAAAAAAAAACAwQBBf/EAB4RAAICAgIDAAAAAAAAAAAAAAECAAMRIQQSIlJx/9oADAMBAAIRAxEAPwDPOopUeU604VNEPLKFniwFKJonOXqHS+pIeUzA02VIbcaUW1CI4vddGhQo5pR1TTdPYBg6HCbktuBTTxWtexI9Uu+fP9CssumddCe3KQ18rEptvei3B4FCxfF2br6vA5Nl1ewmvsuTg68jiWruVcHssy2sbVtwWm1JqqIYIIr1z6xmS9R9wP2kB6JqUZJU2C2+x+QfkVzR4Io8349YzKncjJWJaj1Mpz09aydxvn3kAnKaeQ42va6k2D9EYxnRbcvLEjBkM+U5qGoLmzZC35KwApaqs1wPAxjGAABoRagKOqjAn//Z';

const dummyExtensionsList = [
  {
    name: 'Extension 1',
    description: 'a description of extension 1',
    imgSrc: kittenBase64,
    id: 'extension1',
  },
  {
    name: 'Extension with longer name 2',
    description: 'a description of extension 2',
    imgSrc: kittenBase64,
    id: 'extension2',
  },
  {
    name: 'Extension 3',
    description: 'a description of extension 3',
    imgSrc: kittenBase64,
    id: 'extension3',
  },
  {
    name: 'Extension 4',
    description: 'a description of extension 4',
    imgSrc: kittenBase64,
    id: 'extension4',
  },
  {
    name: 'Extension name 5',
    description: 'a description of extension 5',
    imgSrc: kittenBase64,
    id: 'extension5',
  },
];
// Custom baseQuery function to return mock data during development
// @ts-ignore
const customBaseQuery = async (args, api, extraOptions) => {
  if (args === 'xb-extensions') {
    return { data: dummyExtensionsList };
  }
  return baseQuery(args, api, extraOptions);
};

// Define a service using a base URL and expected endpoints
export const extensionsApi = createApi({
  reducerPath: 'extensionsApi',
  baseQuery: customBaseQuery,
  endpoints: (builder) => ({
    getExtensions: builder.query<ExtensionsList, void>({
      query: () => `xb-extensions`,
    }),
  }),
});

// Export hooks for usage in functional extensions, which are
// auto-generated based on the defined endpoints
export const { useGetExtensionsQuery } = extensionsApi;
