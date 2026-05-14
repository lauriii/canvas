import type { CanvasComponentTree } from 'drupal-canvas/json-render-utils';

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
}

export type ContentTemplateListItem = Omit<ContentTemplate, 'component_tree'>;
