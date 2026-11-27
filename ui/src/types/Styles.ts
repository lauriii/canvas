/**
 * Component styles on the core Style API (experimental).
 *
 * @see \Drupal\canvas\Style\ComponentStyles
 * @see openapi.yml `StyleDefinition`
 */

export interface StyleOption {
  id: string;
  /** The label, after any brand kit override. */
  label: string;
  /** The label the providing extension defines. */
  originalLabel: string;
  description: string;
  value: string;
  attribute: string;
  status: 'experimental' | 'stable' | 'deprecated' | 'obsolete' | string;
  icon: string | null;
  /** A fixed option, or a brand kit color whose ID is its reference. */
  kind: 'option' | 'token';
  /** For brand kit colors: the color's CSS value. */
  swatch: string | null;
  cssVariable: string | null;
  /** For brand kit colors: the color's folder name. */
  folder: string | null;
}

/** Rules for custom values, stored as `=` plus a number and unit. */
export interface StyleCustom {
  type: 'length' | 'number' | 'integer' | 'percentage';
  units: string[];
  min: number | null;
  max: number | null;
  step: number | null;
  /** Bounds for each unit; the top-level bounds are in the first unit. */
  ranges: Record<string, StyleCustomRange>;
  value: string;
  property: string;
}

/** A custom value's bounds in one unit. */
export interface StyleCustomRange {
  min: number | null;
  max: number | null;
  step: number | null;
}

/** A named part inside a component, with the styles it accepts. */
export interface StylePart {
  label: string;
  styles: string[];
  /** Per-style limits on this part, keyed by style ID. */
  limits: Record<string, StyleLimit>;
}

/** A component's limit on one style's choices. */
export interface StyleLimit {
  folders?: string[];
  colors?: string[];
  options?: string[];
  custom?: boolean;
}

export interface StyleDefinition {
  id: string;
  /** The label, after any brand kit override. */
  label: string;
  /** The label the providing extension defines. */
  originalLabel: string;
  description: string;
  category: string;
  weight: number;
  status: string;
  provider: string;
  /** The module or theme name, or the style library label. */
  providerLabel: string;
  /** Where to change the style: a site library, the theme, or a module. */
  providerType: 'site' | 'theme' | 'module';
  library: string;
  /** The options this site offers. */
  options: StyleOption[];
  /** Options this site does not offer, mapped to the one that renders. */
  fallbacks: Record<string, string>;
  /** Options the brand kit does not offer, with their labels. */
  notOffered: Record<string, string>;
  /** When brand kit colors are choices: the class and custom property. */
  tokens: {
    type: string;
    folders: string[];
    value: string;
    property: string;
  } | null;
  /** When editors may enter their own value: its rules. */
  custom: StyleCustom | null;
}

export interface StylesResponse {
  active: boolean;
  styles: StyleDefinition[];
  /** Styles the brand kit does not offer at all, with their labels. */
  hiddenStyles: Record<string, string>;
  permissions: { use: boolean; administer: boolean; custom: boolean };
}

/**
 * Which options this site offers for one style, stored in the brand kit.
 *
 * @see \Drupal\canvas\Entity\BrandKit::getStyleOptions()
 */
export interface BrandKitStyleEntry {
  label?: string;
  /** Offered option IDs. Omitted: every option. Empty: not offered. */
  options?: string[];
  labels?: Record<string, string>;
  fallbacks?: Record<string, string>;
  /** False turns off custom values for the style on this site. */
  custom?: boolean;
}

export type BrandKitStyles = Record<string, BrandKitStyleEntry>;

/** Instances per option: style ID, then option ID. */
export interface StyleUsage {
  usage: Record<string, Record<string, number>>;
}

/** A selection: style ID to option ID. Unset styles have no entry. */
export type StyleSelection = Record<string, string>;
