/**
 * Supported asset file extensions for component builds.
 * These files are copied to the dist output and included in the manifest.
 */

export const IMAGE_EXTENSIONS = [
  '.jpg',
  '.jpeg',
  '.png',
  '.gif',
  '.webp',
  '.avif',
  '.ico',
] as const;

export const SVG_EXTENSIONS = ['.svg'] as const;

export const AUDIO_EXTENSIONS = [
  '.mp3',
  '.wav',
  '.ogg',
  '.flac',
  '.aac',
  '.m4a',
] as const;

export const VIDEO_EXTENSIONS = ['.mp4', '.webm', '.mov', '.avi'] as const;

export const FONT_EXTENSIONS = [
  '.woff',
  '.woff2',
  '.ttf',
  '.otf',
  '.eot',
] as const;

export const ASSET_EXTENSIONS = [
  ...IMAGE_EXTENSIONS,
  ...SVG_EXTENSIONS,
  ...AUDIO_EXTENSIONS,
  ...VIDEO_EXTENSIONS,
  ...FONT_EXTENSIONS,
] as const;
