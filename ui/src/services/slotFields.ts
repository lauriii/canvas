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
  /**
   * Whether the field already has a config on this template's bundle. False for
   * a slot field defined only on another bundle (shared storage): selecting it
   * attaches the storage to this bundle first.
   */
  onThisBundle: boolean;
  /**
   * How many of this bundle's entities already hold content in the field (what
   * reusing it would restore). Candidates are returned sorted content-first.
   */
  contentCount: number;
}

interface CandidatesResponse {
  fields: SlotFieldCandidate[];
}

/**
 * What creating a slot field returns: the created field's identity only.
 *
 * Narrower than `SlotFieldCandidate` on purpose — `onThisBundle` and
 * `contentCount` describe a *candidate* for reuse and are meaningless for a
 * field that was just created on this bundle with no content.
 *
 * @see \Drupal\canvas\Controller\ApiContentTemplateSlotFieldController::create()
 */
export type CreatedSlotField = Pick<SlotFieldCandidate, 'fieldName' | 'label'>;

interface CreateSlotFieldRequest {
  /** Content template config id, e.g. `node.article.full`. */
  contentTemplateId: string;
  fieldName: string;
  label: string;
}

/** How many of a bundle's entities have overridden an exposed slot. */
export interface SlotUsage {
  /** Entities whose backing field holds content (filled or deliberately empty). */
  overridden: number;
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
    createSlotField: builder.mutation<CreatedSlotField, CreateSlotFieldRequest>(
      {
        query: ({ contentTemplateId, fieldName, label }) => ({
          url: `/canvas/api/v0/config/content_template/${contentTemplateId}/slot-fields`,
          method: 'POST',
          body: { fieldName, label },
        }),
        invalidatesTags: (_result, _error, { contentTemplateId }) => [
          { type: 'SlotFields', id: contentTemplateId },
        ],
      },
    ),
    getSlotFieldUsage: builder.query<
      SlotUsage,
      { contentTemplateId: string; fieldName: string }
    >({
      query: ({ contentTemplateId, fieldName }) => ({
        url: `/canvas/api/v0/config/content_template/${contentTemplateId}/slot-fields/${fieldName}/usage`,
      }),
    }),
  }),
});

export const {
  useGetSlotFieldCandidatesQuery,
  useCreateSlotFieldMutation,
  useGetSlotFieldUsageQuery,
} = slotFieldsApi;
