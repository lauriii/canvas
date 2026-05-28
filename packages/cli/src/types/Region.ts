import type { CanvasComponentTree } from 'drupal-canvas/json-render-utils';

export interface Region {
  id: string;
  theme: string;
  region: string;
  status: boolean;
  component_tree: CanvasComponentTree;
}

export type RegionListItem = Omit<Region, 'component_tree'>;
