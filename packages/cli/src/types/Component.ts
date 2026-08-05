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
  /** Original relative disk path of the source file. */
  path?: string;
  /** Original source code of a module. */
  source?: string;
}

/**
 * Build manifest produced by the build command (from #3571534).
 */
export interface BuildManifest {
  vendor: Record<string, string>;
  local: Record<string, string>;
  shared?: string[];
  /**
   * Original sources for local artifacts: relative disk path and, for text
   * modules, the verbatim original source. Keyed by the same specifier used in
   * `local`. Absent entries fall back to no path/source.
   */
  localSources?: Record<string, { path: string; source?: string }>;
  /**
   * Original sources of local modules bundled into other artifacts, kept so a
   * pull can restore the editable file. No `uri`; never part of the import map.
   */
  bundledSources?: Array<{ path: string; source: string }>;
}

/**
 * Response from the artifact upload endpoint.
 */
export interface UploadedArtifactResult {
  uri: string;
  fid: number;
  url?: string;
}

/** Axis entry for variable fonts (brand kit schema). */
export interface BrandKitFontAxis {
  tag: string;
  name?: string;
  min?: number;
  max?: number;
  default?: number;
}

/** Font entry stored on brand kit (matches backend FontEntry). */
export interface BrandKitFontEntry {
  id: string;
  family: string;
  uri: string;
  format: string;
  weight: string;
  style: string;
  axes?: BrandKitFontAxis[] | null;
}

export interface BrandKitFontEntryWithUrl extends BrandKitFontEntry {
  url: string;
}

/** Brand kit config entity (subset used for font sync). */
export interface BrandKit {
  id: string;
  label?: string;
  fonts?: BrandKitFontEntryWithUrl[] | null;
}
