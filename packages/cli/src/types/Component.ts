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
  url?: string;
}

/** Axis entry for variable fonts (Brand Kit schema). */
export interface BrandKitFontAxis {
  tag: string;
  name?: string;
  min?: number;
  max?: number;
  default?: number;
}

/** Font entry stored on Brand Kit (matches backend FontEntry). */
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

/** Brand Kit config entity (subset used for font sync). */
export interface BrandKit {
  id: string;
  label?: string;
  fonts?: BrandKitFontEntryWithUrl[] | null;
}
