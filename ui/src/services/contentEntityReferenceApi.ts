import { createApi } from '@reduxjs/toolkit/query/react';

import { baseQuery } from '@/services/baseQuery';

const TYPED_DATA_BROWSER_LINK_REL =
  'https://drupal.org/project/canvas#link-rel-typed-data-browser';

type Links = Partial<
  Record<typeof TYPED_DATA_BROWSER_LINK_REL, { href: string }>
>;

export type ContentEntityReferenceEntityTypes = Record<
  string,
  {
    label: string;
    bundles: Record<string, { label: string; links?: Links }>;
  }
>;

export interface ContentEntityReferenceFieldProperty {
  name: string;
  label: string;
  expression: string;
}

interface ContentEntityReferenceFieldTargetBundle {
  label: string;
  labelExpression: string;
  links?: Links;
}

export interface ContentEntityReferenceField {
  name: string;
  label: string;
  hasChildren: boolean;
  targetEntityType?: string;
  targetBundles?: Record<string, ContentEntityReferenceFieldTargetBundle>;
  properties: ContentEntityReferenceFieldProperty[];
}

export function getFieldsHref(
  source: { links?: Links } | undefined,
): string | undefined {
  return source?.links?.[TYPED_DATA_BROWSER_LINK_REL]?.href;
}

export const contentEntityReferenceApi = createApi({
  reducerPath: 'contentEntityReferenceApi',
  baseQuery,
  endpoints: (builder) => ({
    listEntityTypes: builder.query<ContentEntityReferenceEntityTypes, void>({
      query: () => ({
        url: `/canvas/api/v0/ui/content-entity-reference`,
      }),
      transformResponse: (response: {
        data: ContentEntityReferenceEntityTypes;
      }) => response.data,
    }),
    listFields: builder.query<ContentEntityReferenceField[], { href: string }>({
      query: ({ href }) => ({ url: href }),
      transformResponse: (response: { data: ContentEntityReferenceField[] }) =>
        response.data,
    }),
  }),
});

export const { useListEntityTypesQuery, useListFieldsQuery } =
  contentEntityReferenceApi;
