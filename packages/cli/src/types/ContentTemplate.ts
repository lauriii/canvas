import type { CanvasComponentTree } from 'drupal-canvas/json-render-utils';

/**
 * A single exposed-slot definition, keyed by a stable machine-name alias.
 *
 * The wire format (server ContentTemplate payload) uses snake_case keys, so
 * these are kept snake_case here to round-trip verbatim.
 */
export interface ExposedSlot {
  component_uuid: string;
  slot_name: string;
  label: string;
}

/**
 * Map of exposed-slot definitions keyed by machine-name alias.
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
  suggestedPreviewEntityId?: number | null;
  component_tree: CanvasComponentTree;
  exposed_slots?: ExposedSlots;
}

export type ContentTemplateListItem = Omit<ContentTemplate, 'component_tree'>;
