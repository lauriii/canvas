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
  /**
   * Extensions accepted for a component's entry file, in precedence order.
   * Default: the JavaScript extensions (JS_EXTENSIONS). Consumers that only
   * read component metadata — not the entry's code — widen this to
   * framework single-file components (.astro, .vue, .svelte), whose entries
   * the Canvas build pipeline could not compile.
   */
  entryExtensions?: readonly string[];
  /**
   * Whether a component must have a JavaScript entry file to be discovered.
   * Default: true. When false, components without an entry are still
   * discovered (with `jsEntryPath: null`) and no `missing_js_entry` warning is
   * emitted. Consumers that push metadata only — for example when the Canvas
   * Headless SDK renders every component in a decoupled app — set this false so
   * framework single-file components (.vue, .astro, .svelte) are not dropped.
   */
  requireJsEntry?: boolean;
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
  type?: CodeComponentSerialized['type'];
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
  'name' | 'machineName' | 'status' | 'required' | 'slots' | 'type'
> {
  props: {
    properties: CodeComponentSerialized['props'];
  };
  dataDependencies?: CodeComponentSerialized['dataDependencies'];
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
  globalCssPath: string;
  layoutPath: string;
  sync: CanvasSyncConfig;
}
