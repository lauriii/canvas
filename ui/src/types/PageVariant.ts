// A single component instance in a page variant's component tree, in the
// client-side (config sequence) shape the Canvas config HTTP API accepts.
export interface PageVariantComponentTreeItem {
  uuid: string;
  component_id: string;
  component_version: string;
  // Markers carry no inputs, so this is an empty list, but other components
  // store a keyed map of resolved inputs.
  inputs: Record<string, unknown> | unknown[];
}

// The client-side representation of a `page_variant` config entity, as returned
// by `/canvas/api/v0/config/page_variant/{id}`.
export interface PageVariant {
  id: string;
  label: string | null;
  description: string | null;
  status: boolean;
  component_tree: PageVariantComponentTreeItem[];
}

// The list response from `/canvas/api/v0/config/page_variant`, keyed by id.
export type PageVariantsList = Record<string, PageVariant>;

// The body accepted by POST `/canvas/api/v0/config/page_variant`.
export interface CreatePageVariantPayload {
  id: string;
  label: string;
  description: string;
  component_tree: PageVariantComponentTreeItem[];
}

// The body accepted by PATCH `/canvas/api/v0/config/page_variant/{id}` when
// renaming or re-describing a variant. `id` is immutable server-side, so it is
// not part of the patch body.
export interface UpdatePageVariantPayload {
  id: string;
  label?: string;
  description?: string;
}

// The payload for GET/PATCH `/canvas/api/v0/settings/default-page-variant`.
export interface DefaultPageVariantResponse {
  default_page_variant: string | null;
}
