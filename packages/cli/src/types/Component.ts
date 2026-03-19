import type {
  AssetLibrary,
  CodeComponentSerialized as Component,
  DataDependencies,
} from '@drupal-canvas/ui/types/CodeComponent';

export { AssetLibrary, Component, DataDependencies };

/**
 * A server-side uploaded artifact reference tracked in the asset library manifest.
 */
export interface UploadedArtifact {
  /** Import specifier or package name. */
  name: string;
  /** Opaque server-assigned file identifier. */
  uri: string;
}

/**
 * Build manifest produced by the build command (from #3571534).
 */
export interface BuildManifest {
  vendor: Record<string, string>;
  local: Record<string, string>;
  shared?: string[];
}

/**
 * Response from the artifact upload endpoint.
 */
export interface UploadedArtifactResult {
  uri: string;
  fid: number;
}
