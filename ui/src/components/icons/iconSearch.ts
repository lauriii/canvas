/**
 * Shared icon search used by the icon picker and the Brand Kit section.
 *
 * Filtering matches an icon's machine name and human label only — never the
 * pack id, so a query like "font" does not match every icon of a pack whose id
 * happens to contain it. Within each pack, results are ranked exact match
 * first, then prefix matches, then substring matches, so short queries surface
 * the most likely icons first while the grid stays grouped by pack.
 */

import type { IconPack } from '@/types/Icons';

/**
 * Filters packs to the icons matching a search term, ranked within each pack.
 *
 * Packs left with no matching icons are dropped. An empty term returns the
 * packs unchanged.
 */
export function filterAndRankPacks(
  packs: IconPack[],
  searchTerm: string,
): IconPack[] {
  const term = searchTerm.trim().toLowerCase();
  if (!term) {
    return packs;
  }
  return packs
    .map((pack) => {
      const exact: IconPack['icons'] = [];
      const prefix: IconPack['icons'] = [];
      const contains: IconPack['icons'] = [];
      for (const icon of pack.icons) {
        const name = icon.name.toLowerCase();
        const label = icon.label.toLowerCase();
        if (name === term || label === term) {
          exact.push(icon);
        } else if (name.startsWith(term) || label.startsWith(term)) {
          prefix.push(icon);
        } else if (name.includes(term) || label.includes(term)) {
          contains.push(icon);
        }
      }
      return { ...pack, icons: [...exact, ...prefix, ...contains] };
    })
    .filter((pack) => pack.icons.length > 0);
}
