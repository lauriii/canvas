import type { CanvasComponentTree } from 'drupal-canvas/json-render-utils';

/**
 * A single exposed slot definition.
 *
 * Uses the server's snake_case keys, so it round-trips unchanged.
 */
export interface ExposedSlot {
  component_uuid: string;
  slot_name: string;
  label: string;
}

/**
 * Exposed slot definitions, keyed by the slot's `component_tree` field name.
 */
export type ExposedSlots = Record<string, ExposedSlot>;

export interface ContentTemplate {
  id: string;
  label: string;
  status: boolean;
  entityType: string;
  bundle: string;
  viewMode: string;
  viewModeLabel?: string;
  suggestedPreviewEntityId?: number | string | null;
  pageVariant?: string | null;
  component_tree: CanvasComponentTree;
  exposed_slots?: ExposedSlots;
}

export type ContentTemplateListItem = Omit<ContentTemplate, 'component_tree'>;
