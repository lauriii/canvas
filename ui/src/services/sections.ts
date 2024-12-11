// cspell:ignore abcde, fghij, klmno

// Need to use the React-specific entry point to import createApi
import { createApi } from '@reduxjs/toolkit/query/react';
import { baseQuery } from '@/services/baseQuery';
import type { LayoutModelPiece } from '@/features/layout/layoutModelSlice';

interface SaveSectionData extends LayoutModelPiece {
  name: string;
}

const mockSections = {
  fakeSection2: {
    id: 'fakeSection2',
    name: 'Fake Section 2',
    css: '',
    js_footer: '',
    js_header: '',
    layoutModel: {
      layout: [
        {
          uuid: 'abcde',
          nodeType: 'component',
          type: 'sdc.experience_builder.two_column',
          slots: [
            {
              id: 'abcde/column_one',
              name: 'column_one',
              nodeType: 'slot',
              components: [
                {
                  uuid: 'fghij',
                  nodeType: 'component',
                  type: 'sdc.experience_builder.my-hero',
                  slots: [],
                },
              ],
            },
            {
              id: 'abcde/column_two',
              name: 'column_two',
              nodeType: 'slot',
              components: [
                {
                  uuid: 'klmno',
                  nodeType: 'component',
                  type: 'sdc.experience_builder.my-hero',
                  slots: [],
                },
              ],
            },
          ],
        },
      ],
      model: {
        abcde: {
          width: 50,
          name: 'Two Column',
        },
        fghij: {
          heading: 'A hero in slot 1!',
          subheading: 'This text was defined in the section.',
          cta1: 'Yes',
          cta2: 'No',
          cta1href: 'https://drupal.org',
          cta2href: 'https://google.com',
          name: 'Hero',
        },
        klmno: {
          heading: 'A hero in slot 2!',
          subheading: 'Text saved in the section',
          cta1: 'Up',
          cta2: 'Down',
          cta1href: 'https://drupal.org',
          cta2href: 'https://google.com',
          name: 'Hero',
        },
      },
    },
    default_markup: '<h1 style="background: black; color: white;">TODO</h1>',
  },
};
// Custom baseQuery function to return mock data during development
// @ts-ignore
const customBaseQuery = async (args, api, extraOptions) => {
  if (args === 'xb-sections') {
    return { data: mockSections };
  }
  return baseQuery(args, api, extraOptions);
};

// Define a service using a base URL and expected endpoints
export const sectionApi = createApi({
  reducerPath: 'sectionsApi',
  baseQuery: customBaseQuery,
  endpoints: (builder) => ({
    getSectionById: builder.query<any, string>({
      query: (id) => `xb-section/${id}`,
    }),
    getDummySections: builder.query<any, void>({
      query: () => `xb-sections`,
    }),
    getSections: builder.query<any, void>({
      query: () => `/xb/api/config/pattern`,
    }),
    saveSection: builder.mutation<{ html: string }, SaveSectionData>({
      query: (body) => ({
        url: '/xb/api/config/pattern',
        method: 'POST',
        body,
      }),
    }),
  }),
});

// Export hooks for usage in functional sections, which are
// auto-generated based on the defined endpoints
export const {
  useGetSectionByIdQuery,
  useGetSectionsQuery,
  useGetDummySectionsQuery,
  useSaveSectionMutation,
} = sectionApi;
