import type { CanvasComponentTree } from 'drupal-canvas/json-render-utils';

export interface Page {
  id: number;
  uuid: string;
  title: string;
  status: boolean;
  path: string;
  internalPath: string;
  autoSaveLabel: string | null;
  autoSavePath: string | null;
  links: Record<string, string>;
  components: CanvasComponentTree;
}

export type PageListItem = Omit<Page, 'components'>;
