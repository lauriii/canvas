// Need to use the React-specific entry point to import createApi
import { createApi } from '@reduxjs/toolkit/query/react';
import { baseQuery } from '@/services/baseQuery';
import type { ExtensionsList } from '@/types/Extensions';
const { drupalSettings } = window;
const kittenBase64 =
  /* cspell:disable-next-line */
  'data:image/jpeg;base64, /9j/2wBDAAYEBQYFBAYGBQYHBwYIChAKCgkJChQODwwQFxQYGBcUFhYaHSUfGhsjHBYWICwgIyYnKSopGR8tMC0oMCUoKSj/2wBDAQcHBwoIChMKChMoGhYaKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCj/wAARCAAYABgDASIAAhEBAxEB/8QAGAABAAMBAAAAAAAAAAAAAAAAAAMGBwX/xAAlEAABBAEDBAIDAAAAAAAAAAABAgMEEQAFEiEGBzFBE1EUFSL/xAAYAQADAQEAAAAAAAAAAAAAAAACAwQBBf/EAB4RAAICAgIDAAAAAAAAAAAAAAECAAMRIQQSIlJx/9oADAMBAAIRAxEAPwDPOopUeU604VNEPLKFniwFKJonOXqHS+pIeUzA02VIbcaUW1CI4vddGhQo5pR1TTdPYBg6HCbktuBTTxWtexI9Uu+fP9CssumddCe3KQ18rEptvei3B4FCxfF2br6vA5Nl1ewmvsuTg68jiWruVcHssy2sbVtwWm1JqqIYIIr1z6xmS9R9wP2kB6JqUZJU2C2+x+QfkVzR4Io8349YzKncjJWJaj1Mpz09aydxvn3kAnKaeQ42va6k2D9EYxnRbcvLEjBkM+U5qGoLmzZC35KwApaqs1wPAxjGAABoRagKOqjAn//Z';

// @todo stop hardcoding this list - https://www.drupal.org/i/3509080
let dummyExtensionsList = [
  {
    name: 'Extension proof of concept',
    description:
      'Enable the xb_test_extension module to make this extension appear',
    imgSrc: kittenBase64,
    id: 'experience-builder-test-extension',
  },
  {
    name: 'Extension with longer name 2',
    description: "A dummy extension. It doesn't do anything.",
    imgSrc: kittenBase64,
    id: 'extension2',
  },
  {
    name: 'Extension 3',
    description: 'Another dummy extension. It does nothing.',
    imgSrc: kittenBase64,
    id: 'extension3',
  },
  {
    name: 'Extension 4',
    description: 'This is a dummy extension that does nothing.',
    imgSrc: kittenBase64,
    id: 'extension4',
  },
];

if (drupalSettings && drupalSettings.xbExtension) {
  Object.entries(drupalSettings.xbExtension).forEach(([key, value]) => {
    dummyExtensionsList = dummyExtensionsList.map((item) => {
      if (item.id === value.id) {
        return { ...item, ...value };
      }
      return item;
    });
  });
}

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
