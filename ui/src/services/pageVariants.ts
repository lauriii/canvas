import { v4 as uuidv4 } from 'uuid';
import { createApi } from '@reduxjs/toolkit/query/react';

import { FORM_TYPES } from '@/features/form/constants';
import { clearFieldValues } from '@/features/form/formStateSlice';
import { setUpdatePreview } from '@/features/layout/layoutModelSlice';
import {
  selectPageData,
  selectPageDataOwner,
  setInitialPageData,
} from '@/features/pageData/pageDataSlice';
import { baseQuery } from '@/services/baseQuery';
import { componentAndLayoutApi } from '@/services/componentAndLayout';
import { pageDataFormApi } from '@/services/pageDataForm';

import type { Dispatch } from '@reduxjs/toolkit';
import type { RootState } from '@/app/store';
import type { ComponentsList } from '@/types/Component';
import type {
  CreatePageVariantPayload,
  DefaultPageVariantResponse,
  PageVariant,
  PageVariantComponentTreeItem,
  PageVariantsList,
  UpdatePageVariantPayload,
} from '@/types/PageVariant';

const refreshPageDataForms = (dispatch: Dispatch) => {
  dispatch(clearFieldValues(FORM_TYPES.ENTITY_FORM));
  dispatch(
    pageDataFormApi.util.invalidateTags([{ type: 'PageDataForm', id: 'FORM' }]),
  );
};

// The `page_variant` config entity type id, as used in editor routes and the
// layout API.
// @see \Drupal\canvas\Entity\PageVariant::ENTITY_TYPE_ID
export const PAGE_VARIANT_ENTITY_TYPE = 'page_variant';

// The intrinsic "Page content" marker. A valid page variant must contain
// exactly one, marking where the route's main content is injected at render
// time.
// @see \Drupal\canvas\Plugin\Canvas\ComponentSource\Marker::PAGE_CONTENT_COMPONENT_ID
export const PAGE_CONTENT_MARKER_ID = 'marker.page_content';

// The intrinsic "Empty slot" marker. As the sole content of an exposed slot it
// means "this entity's slot renders nothing", as opposed to no content at all,
// which inherits the content template's default.
// @see \Drupal\canvas\Plugin\Canvas\ComponentSource\Marker::EMPTY_SLOT_COMPONENT_ID
export const EMPTY_SLOT_MARKER_ID = 'marker.empty_slot';

// Resolves the page variant shown in the Layers panel. The page data form can
// contain an unsaved selection that is newer than the layout GET response.
// Empty values mean "Site default"; while that setting is loading, retain the
// layout response so the layer does not briefly disappear.
export const resolvePageVariantSelection = (
  resolvedPageVariant: string | null | undefined,
  selectedPageVariant: unknown,
  defaultPageVariant: string | null | undefined,
): string | null | undefined => {
  if (selectedPageVariant === undefined) {
    return resolvedPageVariant;
  }
  if (
    selectedPageVariant === null ||
    selectedPageVariant === '' ||
    selectedPageVariant === '_none'
  ) {
    return defaultPageVariant === undefined
      ? resolvedPageVariant
      : defaultPageVariant;
  }
  return typeof selectedPageVariant === 'string'
    ? selectedPageVariant
    : resolvedPageVariant;
};

// Whether a layout node's component type (`<component id>@<version>`) is a
// marker. Markers are intrinsic placeholders managed by Canvas: they can be
// moved, but never deleted, duplicated, or copied.
// @see \Drupal\canvas\Plugin\Canvas\ComponentSource\Marker
export const isMarkerComponentType = (type?: string): boolean =>
  !!type?.startsWith('marker.');

// Reads a marker's active version hash from the component library. The version
// is a backend detail (it changes only if the marker's definition changes), so
// it is looked up at runtime rather than hard-coded.
export const getMarkerVersion = (
  markerId: string,
  components?: ComponentsList,
): string | undefined => components?.[markerId]?.version;

export const getPageContentMarkerVersion = (
  components?: ComponentsList,
): string | undefined => getMarkerVersion(PAGE_CONTENT_MARKER_ID, components);

// Derives a config-entity-safe machine name from a human label. Config entity
// ids allow lowercase letters, digits and underscores.
export const slugifyVariantId = (label: string): string => {
  const slug = label
    .toLowerCase()
    .trim()
    .replace(/[^a-z0-9]+/g, '_')
    .replace(/_{2,}/g, '_')
    .replace(/^_+|_+$/g, '');
  return slug || 'variant';
};

// Derives a machine name from a label that does not collide with an existing
// variant id, appending a numeric suffix when needed.
export const generateUniqueVariantId = (
  label: string,
  existingIds: string[],
): string => {
  const base = slugifyVariantId(label);
  if (!existingIds.includes(base)) {
    return base;
  }
  let suffix = 2;
  while (existingIds.includes(`${base}_${suffix}`)) {
    suffix += 1;
  }
  return `${base}_${suffix}`;
};

// Builds a marker instance: the "Page content" one seeds every new variant, the
// "Empty slot" one records an empty per-entity override of an exposed slot.
export const buildMarkerTreeItem = (
  markerVersion: string,
  uuid: string = uuidv4(),
  componentId: string = PAGE_CONTENT_MARKER_ID,
): PageVariantComponentTreeItem => ({
  uuid,
  component_id: componentId,
  component_version: markerVersion,
  inputs: [],
});

interface BuildCreateVariantArgs {
  label: string;
  description?: string;
  existingIds: string[];
  markerVersion: string;
  uuid?: string;
}

// Builds the POST body for a brand-new variant, seeded with a single "Page
// content" marker so the server-side "exactly one marker" constraint passes.
export const buildCreateVariantPayload = ({
  label,
  description = '',
  existingIds,
  markerVersion,
  uuid,
}: BuildCreateVariantArgs): CreatePageVariantPayload => ({
  id: generateUniqueVariantId(label, existingIds),
  label: label.trim(),
  description: description.trim(),
  component_tree: [buildMarkerTreeItem(markerVersion, uuid)],
});

interface BuildDuplicateVariantArgs {
  source: PageVariant;
  existingIds: string[];
  label?: string;
}

// Builds the POST body that duplicates an existing variant. The source tree
// already contains a valid marker, so this never needs the marker version, but
// every component instance is given a fresh UUID: a duplicate that reused the
// source's instance UUIDs (especially the "Page content" marker's) would share
// identity with its source, and a save or patch mis-routed between the two
// variants — the editor model store is shared across entities — could then
// write one variant's content into the other. The server's marker-identity
// guard relies on each variant's marker UUID being unique.
// @see \Drupal\canvas\Controller\ApiLayoutController::post()
export const buildDuplicateVariantPayload = ({
  source,
  existingIds,
  label,
}: BuildDuplicateVariantArgs): CreatePageVariantPayload => {
  const newLabel = (label ?? `${source.label ?? source.id} (copy)`).trim();
  const freshUuids = new Map(
    source.component_tree.map((item) => [item.uuid, uuidv4()]),
  );
  const component_tree = source.component_tree.map((item) => ({
    ...item,
    uuid: freshUuids.get(item.uuid) ?? uuidv4(),
    // Keep the tree's nesting intact by pointing each remapped child at its
    // parent's new UUID.
    ...(item.parent_uuid != null
      ? { parent_uuid: freshUuids.get(item.parent_uuid) ?? item.parent_uuid }
      : {}),
  }));
  return {
    id: generateUniqueVariantId(newLabel, existingIds),
    label: newLabel,
    description: source.description ?? '',
    component_tree,
  };
};

// A service for the `page_variant` config entity plus the site default page
// variant setting. Uses the plain (non-auto-save) base query because these are
// direct config entity writes, matching the pattern service.
export const pageVariantsApi = createApi({
  reducerPath: 'pageVariantsApi',
  baseQuery,
  tagTypes: ['PageVariants', 'DefaultPageVariant'],
  endpoints: (builder) => ({
    getPageVariants: builder.query<PageVariantsList, void>({
      query: () => '/canvas/api/v0/config/page_variant',
      providesTags: () => [{ type: 'PageVariants', id: 'LIST' }],
    }),
    getPageVariant: builder.query<PageVariant, string>({
      query: (id) => `/canvas/api/v0/config/page_variant/${id}`,
      providesTags: (result, error, id) => [{ type: 'PageVariants', id }],
    }),
    createPageVariant: builder.mutation<PageVariant, CreatePageVariantPayload>({
      query: (body) => ({
        url: '/canvas/api/v0/config/page_variant',
        method: 'POST',
        body,
      }),
      async onQueryStarted(_body, { dispatch, queryFulfilled }) {
        try {
          await queryFulfilled;
          refreshPageDataForms(dispatch);
        } catch {
          // The mutation error is surfaced by its caller. Keep the current
          // form when the template was not created.
        }
      },
      invalidatesTags: () => [{ type: 'PageVariants', id: 'LIST' }],
    }),
    updatePageVariant: builder.mutation<PageVariant, UpdatePageVariantPayload>({
      query: ({ id, ...body }) => ({
        url: `/canvas/api/v0/config/page_variant/${id}`,
        method: 'PATCH',
        body,
      }),
      async onQueryStarted(
        { id, status },
        { dispatch, getState, queryFulfilled },
      ) {
        try {
          await queryFulfilled;
          refreshPageDataForms(dispatch);

          if (status === false) {
            dispatch(
              componentAndLayoutApi.util.invalidateTags([{ type: 'Layout' }]),
            );
          }

          // A stale form could submit this selection while the template was
          // disabled. Drupal stores that form violation until the page data is
          // processed successfully again. Re-submit a matching active page
          // selection after enabling the template so publishing can recover
          // without requiring another field edit.
          const state = getState() as RootState;
          const pageData = selectPageData(state);
          const pageDataOwner = selectPageDataOwner(state);
          if (status === true && pageData.page_variant === id) {
            dispatch(
              setInitialPageData({
                values: { ...pageData },
                owner: pageDataOwner,
              }),
            );
            dispatch(setUpdatePreview(true));
          }
        } catch {
          // The mutation error is surfaced by its caller. Keep the current
          // form and validation state when the template was not updated.
        }
      },
      invalidatesTags: (result, error, { id }) => [
        { type: 'PageVariants', id },
        { type: 'PageVariants', id: 'LIST' },
      ],
    }),
    deletePageVariant: builder.mutation<void, string>({
      query: (id) => ({
        url: `/canvas/api/v0/config/page_variant/${id}`,
        method: 'DELETE',
      }),
      async onQueryStarted(_id, { dispatch, queryFulfilled }) {
        try {
          await queryFulfilled;
          refreshPageDataForms(dispatch);
          dispatch(
            componentAndLayoutApi.util.invalidateTags([{ type: 'Layout' }]),
          );
        } catch {
          // The mutation error is surfaced by its caller. Keep the current
          // form when the template was not deleted.
        }
      },
      invalidatesTags: () => [{ type: 'PageVariants', id: 'LIST' }],
    }),
    getDefaultPageVariant: builder.query<DefaultPageVariantResponse, void>({
      query: () => '/canvas/api/v0/settings/default-page-variant',
      providesTags: () => [{ type: 'DefaultPageVariant', id: 'DEFAULT' }],
    }),
    setDefaultPageVariant: builder.mutation<
      DefaultPageVariantResponse,
      string | null
    >({
      query: (id) => ({
        url: '/canvas/api/v0/settings/default-page-variant',
        method: 'PATCH',
        body: { default_page_variant: id },
      }),
      async onQueryStarted(_id, { dispatch, queryFulfilled }) {
        try {
          await queryFulfilled;
          // Active page and content-template previews may inherit the site
          // default. Refetch their rendered HTML after the setting changes.
          dispatch(
            componentAndLayoutApi.util.invalidateTags([{ type: 'Layout' }]),
          );
        } catch {
          // The mutation error is surfaced by its caller. Keep the current
          // preview when the setting was not saved.
        }
      },
      invalidatesTags: () => [{ type: 'DefaultPageVariant', id: 'DEFAULT' }],
    }),
  }),
});

export const {
  useGetPageVariantsQuery,
  useGetPageVariantQuery,
  useCreatePageVariantMutation,
  useUpdatePageVariantMutation,
  useDeletePageVariantMutation,
  useGetDefaultPageVariantQuery,
  useSetDefaultPageVariantMutation,
} = pageVariantsApi;
