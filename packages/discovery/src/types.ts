import type { CodeComponentSerialized } from '@drupal-canvas/ui/types/CodeComponent';

export type DiscoveryWarningCode =
  | 'missing_js_entry'
  | 'duplicate_definition'
  | 'conflicting_metadata'
  | 'duplicate_machine_name';

export interface DiscoveryOptions {
  componentRoot?: string;
  pagesRoot?: string;
  contentTemplatesRoot?: string;
  regionsRoot?: string;
  projectRoot?: string;
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
  projectRelativeDirectory: string;
  metadataPath: string;
  jsEntryPath: string | null;
  cssEntryPath: string | null;
}

export interface DiscoveredPage {
  name: string;
  slug: string;
  uuid: string | null;
  path: string;
  relativePath: string;
}

export interface DiscoveredContentTemplate {
  name: string;
  slug: string;
  label: string | null;
  entityTypeId: string | null;
  bundle: string | null;
  viewMode: string | null;
  path: string;
  relativePath: string;
}

export interface DiscoveredRegion {
  region: string;
  path: string;
  relativePath: string;
}

export interface DiscoveryResult {
  componentRoot: string;
  projectRoot: string;
  components: DiscoveredComponent[];
  pages: DiscoveredPage[];
  contentTemplates: DiscoveredContentTemplate[];
  regions: DiscoveredRegion[];
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

export interface CanvasSyncConfig {
  pages: boolean;
  contentTemplates: boolean;
  regions: boolean;
}

export interface CanvasConfig {
  aliasBaseDir: string;
  outputDir: string;
  componentDir: string;
  pagesDir: string;
  contentTemplatesDir: string;
  regionsDir: string;
  deprecatedComponentDir: string;
  globalCssPath: string;
  layoutPath: string;
  sync: CanvasSyncConfig;
}
