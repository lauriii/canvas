/**
 * @file
 * Pure helpers for the Style tab, copy and paste, and recovery notices.
 *
 * @see docs/adr/0027-component-styles-on-the-core-style-api.md
 */

import type {
  StyleCustom,
  StyleCustomRange,
  StyleDefinition,
  StyleLimit,
  StyleOption,
  StylePart,
  StyleSelection,
} from '@/types/Styles';

export type StyleControlType =
  'switch' | 'toolbar' | 'segmented' | 'select' | 'swatches';

/**
 * Estimated widths for choosing a segmented control. At the Style tab's
 * 12px type, a character averages about 6.6px and each option adds about
 * 12px of padding.
 */
const CHARACTER_PX = 6.6;
const OPTION_PADDING_PX = 12;

/** The control column beside the label, in the about 290px sidebar. */
export const SEGMENTED_MAX_PX = 192;

const segmentedWidth = (options: StyleOption[]) =>
  options.reduce(
    (total, option) =>
      total + option.label.length * CHARACTER_PX + OPTION_PADDING_PX,
    0,
  );

/** Options a control offers: deprecated ones last, hidden unless current. */
export function visibleOptions(
  definition: StyleDefinition,
  current: string | undefined,
): StyleOption[] {
  return [
    ...definition.options.filter((option) => option.status !== 'deprecated'),
    ...definition.options.filter(
      (option) => option.status === 'deprecated' && option.id === current,
    ),
  ];
}

/**
 * Chooses a control by the first rule that fits:
 * 0. swatches when brand kit colors are choices;
 * 1. a switch for one option;
 * 2. an icon toolbar when every option has an icon;
 * 3. a segmented control for two to four options whose labels fit beside
 *    the label (segments size to their labels);
 * 4. a select.
 */
export function chooseControl(options: StyleOption[]): StyleControlType {
  // Brand kit colors are picked from swatches, with fixed options first.
  if (options.some((option) => option.kind === 'token')) {
    return 'swatches';
  }
  if (options.length === 1) {
    return 'switch';
  }
  if (options.length > 0 && options.every((option) => option.icon)) {
    return 'toolbar';
  }
  if (options.length >= 2 && options.length <= 4) {
    if (segmentedWidth(options) <= SEGMENTED_MAX_PX) {
      return 'segmented';
    }
  }
  return 'select';
}

export type DegradedReason =
  | 'fallback'
  | 'not_offered'
  | 'unavailable'
  | 'not_accepted'
  | 'not_allowed_here'
  | 'invalid';

/**
 * Lists stored entries that do not render as stored:
 * - `fallback`: not offered on this site; the brand kit's fallback renders.
 * - `not_offered`: the brand kit does not offer it, and there is no fallback.
 * - `unavailable`: no installed extension provides it.
 * - `not_accepted`: the component no longer accepts the style.
 * - `not_allowed_here`: the component limits the style to other choices.
 * - `invalid`: a custom value outside the style's units or range.
 *
 * @see \Drupal\canvas\Style\ComponentStyles::degraded()
 */
/** Separates a part from the style ID in a selection key. */
export const PART_SEPARATOR = '.';

/**
 * The style ID in a selection key: `heading.text_size` is Text size on the
 * Heading part.
 *
 * @see \Drupal\canvas\Style\ComponentStyles::splitKey()
 */
export const styleIdOf = (key: string): string =>
  key.includes(PART_SEPARATOR)
    ? key.slice(key.indexOf(PART_SEPARATOR) + 1)
    : key;

/** The part ID in a selection key, or undefined for the component itself. */
export const partOf = (key: string): string | undefined =>
  key.includes(PART_SEPARATOR)
    ? key.slice(0, key.indexOf(PART_SEPARATOR))
    : undefined;

/** Selection keys a component accepts: its own styles, then its parts'. */
export const acceptedKeys = (
  styles: string[],
  parts: Record<string, StylePart> | undefined,
): string[] => [
  ...styles,
  ...Object.entries(parts ?? {}).flatMap(([part, { styles: partStyles }]) =>
    partStyles.map((styleId) => `${part}${PART_SEPARATOR}${styleId}`),
  ),
];

export function degradedEntries(
  selection: StyleSelection | undefined,
  definitions: Record<string, StyleDefinition>,
  accepted: string[] | undefined,
  hiddenStyles: Record<string, string> = {},
  limits: Record<string, StyleLimit> = {},
): Record<string, DegradedReason> {
  const degraded: Record<string, DegradedReason> = {};
  for (const [key, optionId] of Object.entries(selection ?? {})) {
    const styleId = styleIdOf(key);
    const definition = definitions[styleId];
    // Limits are keyed by selection key: each element has its own.
    const limit = limits[key];
    const option = definition?.options.find((o) => o.id === optionId);
    if (!definition) {
      degraded[key] = styleId in hiddenStyles ? 'not_offered' : 'unavailable';
    } else if (accepted && !accepted.includes(key)) {
      degraded[key] = 'not_accepted';
    } else if (isCustomValue(optionId)) {
      if (!customAllowed(definition, limit)) {
        degraded[key] =
          definition.custom === null ? 'not_offered' : 'not_allowed_here';
      } else if (!parseCustomValue(optionId, definition.custom!)) {
        degraded[key] = 'invalid';
      }
    } else if (option && !allowedHere(option, limit)) {
      degraded[key] = 'not_allowed_here';
    } else if (!option) {
      degraded[key] =
        optionId in definition.fallbacks
          ? 'fallback'
          : optionId in definition.notOffered
            ? 'not_offered'
            : 'unavailable';
    }
  }
  return degraded;
}

/**
 * Whether an entry needs attention. Fallbacks render as the site intends, so
 * they are explained in the Style tab but not flagged elsewhere.
 */
export const needsAttention = (reason: DegradedReason): boolean =>
  reason !== 'fallback';

/** Whether a style matches a search: its label, description, or options. */
export function matchesFilter(
  definition: StyleDefinition,
  filter: string,
): boolean {
  const needle = filter.trim().toLowerCase();
  if (needle === '') {
    return true;
  }
  return [
    definition.label,
    definition.description,
    ...definition.options.map((option) => option.label),
  ].some((text) => text.toLowerCase().includes(needle));
}

export const OTHER_CATEGORY = 'Other';

/**
 * Groups styles by category in order of first appearance, with styles
 * without a category last under "Other".
 */
export function groupByCategory(
  definitions: StyleDefinition[],
): Array<{ category: string; styles: StyleDefinition[] }> {
  const groups = new Map<string, StyleDefinition[]>();
  for (const definition of definitions) {
    const category = definition.category || OTHER_CATEGORY;
    groups.set(category, [...(groups.get(category) ?? []), definition]);
  }
  const ordered = [...groups.entries()].map(([category, styles]) => ({
    category,
    styles,
  }));
  return [
    ...ordered.filter(({ category }) => category !== OTHER_CATEGORY),
    ...ordered.filter(({ category }) => category === OTHER_CATEGORY),
  ];
}

export interface PasteResult {
  /** The new selection for each target instance. */
  changes: Array<{ uuid: string; styles: StyleSelection }>;
  /** Style IDs that were skipped, with the number of targets skipping them. */
  skipped: Record<string, number>;
}

/**
 * Pastes copied styles onto targets.
 *
 * Replace (the default) drops the target's styles; merge keeps target styles
 * the source does not set. Only accepted styles are pasted.
 */
export function pasteStyles(
  copied: StyleSelection,
  targets: Array<{
    uuid: string;
    styles: StyleSelection | undefined;
    accepted: string[];
    limits?: Record<string, StyleLimit>;
  }>,
  mode: 'replace' | 'merge',
  definitions?: Record<string, StyleDefinition>,
): PasteResult {
  const skipped: Record<string, number> = {};
  // Options this site does not offer, and choices a target component limits
  // out, are never pasted.
  const offered = (
    styleId: string,
    optionId: string,
    limit: StyleLimit | undefined,
  ) => {
    if (!definitions) return true;
    const definition = definitions[styleIdOf(styleId)];
    if (!definition) return false;
    if (isCustomValue(optionId)) {
      return (
        customAllowed(definition, limit) &&
        parseCustomValue(optionId, definition.custom!) !== undefined
      );
    }
    const option = definition.options.find((o) => o.id === optionId);
    return Boolean(option) && allowedHere(option!, limit);
  };
  const changes = targets.map(({ uuid, styles, accepted, limits }) => {
    const next: StyleSelection = mode === 'merge' ? { ...(styles ?? {}) } : {};
    for (const [styleId, optionId] of Object.entries(copied)) {
      if (
        accepted.includes(styleId) &&
        offered(styleId, optionId, limits?.[styleId])
      ) {
        next[styleId] = optionId;
      } else {
        skipped[styleId] = (skipped[styleId] ?? 0) + 1;
      }
    }
    return { uuid, styles: next };
  });
  return { changes, skipped };
}

/**
 * Describes skipped styles: "Columns wasn't pasted on 1 section: it doesn't
 * support it."
 */
export function describeSkipped(
  skipped: Record<string, number>,
  definitions: Record<string, StyleDefinition>,
  noun: (count: number) => string,
): string | null {
  const parts = Object.entries(skipped).map(([styleId, count]) => {
    const label = definitions[styleIdOf(styleId)]?.label ?? styleId;
    return `${label} wasn't pasted on ${count} ${noun(count)}: ${count === 1 ? "it doesn't" : "they don't"} support it.`;
  });
  return parts.length === 0 ? null : parts.join(' ');
}

/** The accessible name of a style control: "Spacing, Large, set". */
export function accessibleName(
  definition: StyleDefinition,
  current: string | undefined,
): string {
  const option = definition.options.find(({ id }) => id === current);
  if (option) {
    return `${definition.label}, ${option.label}, set`;
  }
  if (isCustomValue(current)) {
    return `${definition.label}, ${current.slice(1)}, set`;
  }
  return `${definition.label}, not set`;
}

const CUSTOM_VALUE = /^=(-?\d+(?:\.\d+)?)([a-z%]*)$/;

/** Whether a stored value is a custom value (`=24px`). */
export const isCustomValue = (value: string | undefined): value is string =>
  Boolean(value?.startsWith('='));

/**
 * Parses a custom value against a style's rules.
 *
 * @see \Drupal\canvas\Style\StyleValues::parseCustom()
 */
export function parseCustomValue(
  value: string,
  custom: StyleCustom,
): { number: number; unit: string } | undefined {
  const match = CUSTOM_VALUE.exec(value);
  if (!match) return undefined;
  const number = Number(match[1]);
  const unit = match[2];
  if (!custom.units.includes(unit)) return undefined;
  if (custom.type === 'integer' && !Number.isInteger(number)) return undefined;
  const range = rangeOf(custom, unit);
  if (range.min != null && number < range.min) return undefined;
  if (range.max != null && number > range.max) return undefined;
  return { number, unit };
}

/** A unit's bounds; older definitions without `ranges` share one. */
export const rangeOf = (custom: StyleCustom, unit: string): StyleCustomRange =>
  custom.ranges?.[unit] ?? custom;

/** Builds the stored form of a custom value. */
export const customValue = (number: number, unit: string): string =>
  `=${Number(number.toFixed(4))}${unit}`;

/** The increment for arrow keys, scrubbing, and the slider, in a unit. */
export const stepOf = (custom: StyleCustom, unit: string) =>
  rangeOf(custom, unit).step ?? (custom.type === 'integer' ? 1 : 0.1);

/** Snaps a number to a unit's step and clamps it to its bounds. */
const fit = (number: number, custom: StyleCustom, unit: string) => {
  const { min, max } = rangeOf(custom, unit);
  const step = stepOf(custom, unit);
  let fitted = Math.round(number / step) * step;
  if (min !== null) fitted = Math.max(min, fitted);
  if (max !== null) fitted = Math.min(max, fitted);
  return customValue(fitted, unit);
};

/**
 * Moves a custom value by a number of steps, within its unit's bounds. An
 * option or no value starts from the minimum, or zero, in the first unit.
 */
export const stepCustom = (
  value: string | undefined,
  custom: StyleCustom,
  steps: number,
): string => {
  const parsed = isCustomValue(value)
    ? parseCustomValue(value, custom)
    : undefined;
  const unit = parsed?.unit ?? custom.units[0];
  const start = parsed?.number ?? rangeOf(custom, unit).min ?? 0;
  return fit(start + steps * stepOf(custom, unit), custom, unit);
};

/**
 * How far to drag a label for one step: a whole range takes about 240px, so
 * 1 to 10 rem in quarter steps and 240 to 1600 px in 8px steps feel alike.
 * Unbounded values move a step every 2px.
 */
export const scrubPixelsPerStep = (
  custom: StyleCustom,
  value: string | undefined,
): number => {
  const parsed = isCustomValue(value)
    ? parseCustomValue(value, custom)
    : undefined;
  const unit = parsed?.unit ?? custom.units[0];
  const { min, max } = rangeOf(custom, unit);
  if (min === null || max === null) return 2;
  const steps = (max - min) / stepOf(custom, unit);
  return Math.min(8, Math.max(1, 240 / steps));
};

/** Pixels per unit, used to keep a value's size when switching units. */
const PIXELS_PER_UNIT: Record<string, number> = { px: 1, rem: 16, em: 16 };

/**
 * Converts a number to another unit, as Webflow does: 4rem becomes 64px.
 * Units that don't convert keep the number's place within the bounds.
 *
 * @see \Drupal\canvas\Style\StyleValues::PIXELS_PER_UNIT
 */
export const convertCustom = (
  number: number,
  from: string,
  to: string,
  custom: StyleCustom,
): string => {
  if (PIXELS_PER_UNIT[from] && PIXELS_PER_UNIT[to]) {
    return fit(
      (number * PIXELS_PER_UNIT[from]) / PIXELS_PER_UNIT[to],
      custom,
      to,
    );
  }
  const source = rangeOf(custom, from);
  const target = rangeOf(custom, to);
  if (
    source.min === null ||
    source.max === null ||
    target.min === null ||
    target.max === null ||
    source.max === source.min
  ) {
    return fit(number, custom, to);
  }
  const position = (number - source.min) / (source.max - source.min);
  return fit(target.min + position * (target.max - target.min), custom, to);
};

/**
 * Whether a component's limit allows an option. A limit narrows only what
 * it names: `options` for fixed options, `folders` and `colors` for brand
 * kit colors.
 *
 * @see \Drupal\canvas\Style\ComponentStyles::allowedHere()
 */
export function allowedHere(
  option: StyleOption,
  limit: StyleLimit | undefined,
): boolean {
  if (!limit) return true;
  if (option.kind === 'token') {
    if (!limit.folders && !limit.colors) return true;
    const lower = (list: string[] | undefined) =>
      (list ?? []).map((item) => item.toLowerCase());
    return (
      (option.folder !== null &&
        lower(limit.folders).includes(option.folder.toLowerCase())) ||
      lower(limit.colors).includes(option.label.toLowerCase())
    );
  }
  return !limit.options || limit.options.includes(option.id);
}

/** Whether custom values are allowed for a style on a component. */
export const customAllowed = (
  definition: StyleDefinition,
  limit: StyleLimit | undefined,
): boolean => definition.custom !== null && limit?.custom !== false;
