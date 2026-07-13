import { createApi } from '@reduxjs/toolkit/query/react';

import { baseQueryWithAutoSaves } from '@/services/baseQuery';

/**
 * The `component_tree` fields backing a content template's exposed slots.
 *
 * Each exposed slot IS a `component_tree` field on the template's bundle. The
 * expose dialog uses these endpoints to create a new backing field (the "create
 * new slot" path) and to list the bundle's existing fields (the "use existing
 * slot" path).
 *
 * @see \Drupal\canvas\Controller\ApiContentTemplateSlotFieldController
 */
export interface SlotFieldCandidate {
  fieldName: string;
  label: string;
}

interface CandidatesResponse {
  fields: SlotFieldCandidate[];
}

interface CreateSlotFieldRequest {
  /** Content template config id, e.g. `node.article.full`. */
  contentTemplateId: string;
  fieldName: string;
  label: string;
}

export const slotFieldsApi = createApi({
  reducerPath: 'slotFieldsApi',
  baseQuery: baseQueryWithAutoSaves,
  tagTypes: ['SlotFields'],
  endpoints: (builder) => ({
    getSlotFieldCandidates: builder.query<SlotFieldCandidate[], string>({
      query: (contentTemplateId) => ({
        url: `/canvas/api/v0/config/content_template/${contentTemplateId}/slot-fields`,
      }),
      transformResponse: (response: CandidatesResponse) => response.fields,
      providesTags: (_result, _error, contentTemplateId) => [
        { type: 'SlotFields', id: contentTemplateId },
      ],
    }),
    createSlotField: builder.mutation<
      SlotFieldCandidate,
      CreateSlotFieldRequest
    >({
      query: ({ contentTemplateId, fieldName, label }) => ({
        url: `/canvas/api/v0/config/content_template/${contentTemplateId}/slot-fields`,
        method: 'POST',
        body: { fieldName, label },
      }),
      invalidatesTags: (_result, _error, { contentTemplateId }) => [
        { type: 'SlotFields', id: contentTemplateId },
      ],
    }),
  }),
});

export const { useGetSlotFieldCandidatesQuery, useCreateSlotFieldMutation } =
  slotFieldsApi;
