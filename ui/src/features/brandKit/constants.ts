import type { AssetLibraryFont } from '@/types/CodeComponent';

export const BRAND_KIT_ID = 'global';

export const BRAND_KIT_ACCEPTED_FILE_TYPES = [
  'woff2',
  'woff',
  'ttf',
  'otf',
] as const satisfies AssetLibraryFont['format'][];
