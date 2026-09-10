/**
 * An icon within an installed icon pack.
 *
 * Either `svg` (inline markup) or `url` (asset URL) is present for renderable
 * icons; both are absent when the icon could not be resolved server-side.
 *
 * @see \Drupal\canvas\Controller\ApiIconsController
 */
export interface PackIcon {
  /** Full icon id in `pack_id:icon_id` format. This is the stored prop value. */
  id: string;
  /** The icon id within its pack, e.g. `arrow-up`. */
  name: string;
  /** Human-readable label derived from the icon id, e.g. `Arrow Up`. */
  label: string;
  /** Inline SVG markup for the icon. */
  svg?: string;
  /** Asset URL for icons served as files. */
  url?: string;
}

/**
 * An installed icon pack, as returned by `GET /canvas/api/v0/icons`.
 */
export interface IconPack {
  id: string;
  label: string;
  description: string;
  iconCount: number;
  icons: PackIcon[];
}
