import type { AssetLibraryFont } from '@/types/CodeComponent';

export const BRAND_KIT_ID = 'global';

/**
 * Shared cache key for the delete-color mutation.
 *
 * Deleting a color removes its row optimistically, which unmounts the row and
 * the delete popover inside it. A fixed cache key keeps the mutation's error
 * reachable from the colors section, so a rejected delete is still reported
 * after its popover has gone.
 */
export const DELETE_COLOR_CACHE_KEY = 'brand-kit-delete-color';

/**
 * Shared cache key for the edit-color mutation.
 *
 * Editing a color applies optimistically and closes its popover straight away,
 * so a rejected edit has no form left to report itself in. A fixed cache key
 * keeps the error reachable from the colors section.
 */
export const UPDATE_COLOR_CACHE_KEY = 'brand-kit-update-color';

export const BRAND_KIT_ACCEPTED_FILE_TYPES = [
  'woff2',
  'woff',
  'ttf',
  'otf',
] as const satisfies AssetLibraryFont['format'][];
