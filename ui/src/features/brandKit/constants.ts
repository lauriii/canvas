import type { AssetLibraryFont } from '@/types/CodeComponent';

export const BRAND_KIT_ID = 'global';

/** The staged update ID prefix of style guide drafts. */
export const DESIGN_TOKEN_DRAFT_PREFIX = 'canvas_design_token__';

export const BRAND_KIT_ACCEPTED_FILE_TYPES = [
  'woff2',
  'woff',
  'ttf',
  'otf',
] as const satisfies AssetLibraryFont['format'][];
