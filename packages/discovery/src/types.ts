import type { CodeComponentSerialized } from '@drupal-canvas/ui/types/CodeComponent';

export type DiscoveryWarningCode =
  | 'missing_js_entry'
  | 'duplicate_definition'
  | 'conflicting_metadata';

export interface DiscoveryOptions {
  scanRoot?: string;
}

export interface DiscoveryWarning {
  code: DiscoveryWarningCode;
  message: string;
  path?: string;
}

export interface DiscoveredComponent {
  id: string;
  kind: 'named' | 'index';
  name: string;
  directory: string;
  relativeDirectory: string;
  metadataPath: string;
  jsEntryPath: string | null;
  cssEntryPath: string | null;
}

export interface DiscoveredPage {
  name: string;
  slug: string;
  path: string;
  relativePath: string;
}

export interface DiscoveryResult {
  scanRoot: string;
  components: DiscoveredComponent[];
  pages: DiscoveredPage[];
  warnings: DiscoveryWarning[];
  stats: {
    scannedFiles: number;
    ignoredFiles: number;
  };
}

export interface ComponentMetadata extends Pick<
  CodeComponentSerialized,
  'name' | 'machineName' | 'status' | 'required' | 'slots'
> {
  props: {
    properties: CodeComponentSerialized['props'];
  };
}
