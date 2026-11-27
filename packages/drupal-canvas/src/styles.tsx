/**
 * @file
 * Component styles: the core Style API selection a code component receives.
 *
 * Canvas passes a component that accepts styles React's standard `className`
 * and `style` props. The selection itself (option IDs) is read with
 * `useStyleOptions()`, never passed as a prop, so a props spread can never
 * leak it into the DOM.
 *
 * Experimental: the shapes here follow Drupal core's Style API, which is not
 * committed yet.
 *
 * @see docs/adr/0027-component-styles-on-the-core-style-api.md
 * @see \Drupal\canvas\Style\ComponentStyles
 */

import { createContext, useContext } from 'react';
import { clsx } from 'clsx';
import { extendTailwindMerge } from 'tailwind-merge';

import type { CSSProperties, ReactNode } from 'react';
import type { ClassValue } from 'clsx';

/**
 * Generated per component by `canvas pull` into `.canvas/styles.d.ts`:
 *
 * ```ts
 * declare module 'drupal-canvas' {
 *   interface CanvasStyleRegistry {
 *     hero: { tone?: 'light' | 'dark' };
 *   }
 * }
 * ```
 */

export interface CanvasStyleRegistry {}

/**
 * A stored style value: an option ID for all screen sizes, or (reserved for
 * per-breakpoint styles) a map of breakpoint ID to option ID with a `base`.
 */
export type StyleValue = string | ({ base: string } & Record<string, string>);

/** A selection: style ID to value. */
export type StyleSelection = Record<string, StyleValue>;

/** Option IDs, keyed by style ID. */
export type StyleOptions = Record<string, string | undefined>;

/** A style option, as listed by Canvas and `.canvas/styles.json`. */
export interface StyleOptionDefinition {
  id: string;
  label: string;
  description?: string;
  value: string;
  attribute: string;
  status?: string;
  icon?: string | null;
  /** A fixed option, or a brand kit color whose ID is its reference. */
  kind?: 'option' | 'token';
  /** For brand kit colors: the color's CSS value, used as fallback. */
  swatch?: string | null;
  cssVariable?: string | null;
}

/** Rules for custom values, stored as `=` plus a number and unit. */
export interface StyleCustomDefinition {
  type: 'length' | 'number' | 'integer' | 'percentage';
  units: string[];
  min: number | null;
  max: number | null;
  step?: number | null;
  /** Bounds for each unit; the top-level bounds are in the first unit. */
  ranges?: Record<
    string,
    { min: number | null; max: number | null; step?: number | null }
  >;
  value: string;
  property: string;
}

/** A style definition, as listed by Canvas and `.canvas/styles.json`. */
export interface StyleDefinition {
  id: string;
  label: string;
  description?: string;
  category?: string;
  weight?: number;
  status?: string;
  provider?: string;
  providerType?: string;
  options: Record<string, StyleOptionDefinition>;
  /** When brand kit colors are choices: the class and custom property. */
  tokens?: { value: string; property: string } | null;
  custom?: StyleCustomDefinition | null;
}

const CUSTOM_VALUE = /^=(-?\d+(?:\.\d+)?)([a-z%]*)$/;

/**
 * Parses a custom value (`=24px`) against a style's rules.
 *
 * @returns The CSS value, rebuilt from the parsed number, or undefined when
 *   the value breaks the rules.
 *
 * @see \Drupal\canvas\Style\StyleValues::parseCustom()
 */
export function parseCustomStyleValue(
  value: string,
  custom: StyleCustomDefinition,
): string | undefined {
  const match = CUSTOM_VALUE.exec(value);
  if (!match) return undefined;
  const number = Number(match[1]);
  const unit = match[2];
  if (!custom.units.includes(unit)) return undefined;
  if (custom.type === 'integer' && !Number.isInteger(number)) return undefined;
  const range = custom.ranges?.[unit] ?? custom;
  if (range.min != null && number < range.min) return undefined;
  if (range.max != null && number > range.max) return undefined;
  return `${Number(number.toFixed(4))}${unit}`;
}

/**
 * What a code component with named parts receives in its `styleParts` prop:
 * each part's `className` and `style`, keyed by part ID. Parts with nothing
 * set are omitted.
 *
 * @example
 *   <h2 className={clsx('title', styleParts?.heading?.className)}
 *     style={styleParts?.heading?.style}>
 */
export type StyleParts = Record<string, ResolvedStyles>;

/** What a code component receives for a selection. */
export interface ResolvedStyles {
  className?: string;
  style?: CSSProperties;
}

/**
 * Reduces stored values to option IDs: per-breakpoint values return `base`.
 */
export function styleOptionsFromSelection(
  selection: StyleSelection | null | undefined,
): StyleOptions {
  const options: StyleOptions = {};
  for (const [id, value] of Object.entries(selection ?? {})) {
    options[id] = typeof value === 'string' ? value : value?.base;
  }
  return options;
}

const StyleOptionsContext = createContext<StyleOptions>({});
StyleOptionsContext.displayName = 'CanvasStyleOptions';

export interface StyleOptionsProviderProps {
  /** The instance's selection. Breakpoint values are reduced to `base`. */
  styles: StyleSelection | null | undefined;
  children?: ReactNode;
}

/**
 * Provides the selection to `useStyleOptions()`. Rendering integrations (the
 * Drupal island renderer, the headless React adapter, Workbench) mount it
 * around each instance. Adds no DOM wrapper.
 */
export function StyleOptionsProvider({
  styles,
  children,
}: StyleOptionsProviderProps) {
  return (
    <StyleOptionsContext.Provider value={styleOptionsFromSelection(styles)}>
      {children}
    </StyleOptionsContext.Provider>
  );
}

/**
 * Returns the instance's selected option IDs, keyed by style ID.
 *
 * Typed per component after `canvas pull`:
 * `useStyleOptions<'hero'>().tone` is `'light' | 'dark' | undefined`.
 * Unset styles are `undefined`. Outside a provider, returns `{}`.
 */
export function useStyleOptions<
  Name extends keyof CanvasStyleRegistry | undefined = undefined,
>(): Name extends keyof CanvasStyleRegistry
  ? CanvasStyleRegistry[Name]
  : StyleOptions {
  return useContext(StyleOptionsContext) as never;
}

/**
 * Parses an inline declaration list into a React style object. Custom
 * properties keep their names; other properties are camelCased.
 *
 * Ponytail: splits on `;` and the first `:`, like the server, so a `;`
 * inside `url()` breaks a declaration.
 *
 * @see \Drupal\canvas\Style\ComponentStyles::parseInlineStyle()
 */
export function parseInlineStyle(value: string): Record<string, string> {
  const parsed: Record<string, string> = {};
  for (const declaration of value.split(';')) {
    const index = declaration.indexOf(':');
    if (index === -1) continue;
    let property = declaration.slice(0, index).trim();
    const propertyValue = declaration.slice(index + 1).trim();
    if (property === '' || propertyValue === '') continue;
    if (!property.startsWith('--')) {
      property = property
        .toLowerCase()
        .replace(/-([a-z])/g, (_, letter: string) => letter.toUpperCase());
    }
    parsed[property] = propertyValue;
  }
  return parsed;
}

/**
 * Resolves a selection to `className` and `style`, exactly as Drupal does:
 * class values joined in style weight order, then style ID order; empty
 * results are `undefined`. Logic-only options (`value: ''`) add nothing.
 *
 * @see \Drupal\canvas\Style\ComponentStyles::resolveWithDefinitions()
 */
export function resolveStyles(
  selection: StyleSelection | null | undefined,
  definitions: Record<string, StyleDefinition> | StyleDefinition[],
): ResolvedStyles {
  const options = styleOptionsFromSelection(selection);
  const list = (
    Array.isArray(definitions) ? definitions : Object.values(definitions)
  )
    .slice()
    .sort(
      (a, b) =>
        (a.weight ?? 0) - (b.weight ?? 0) ||
        (a.id < b.id ? -1 : a.id > b.id ? 1 : 0),
    );
  const classes: string[] = [];
  const style: Record<string, string> = {};
  // The first style to set a property wins, like the server.
  const declare = (property: string, value: string) => {
    if (!(property in style)) style[property] = value;
  };
  for (const definition of list) {
    const selected = options[definition.id];
    if (!selected) continue;
    if (selected.startsWith('=')) {
      const css = definition.custom
        ? parseCustomStyleValue(selected, definition.custom)
        : undefined;
      if (!css || !definition.custom) continue;
      classes.push(definition.custom.value);
      declare(definition.custom.property, css);
      continue;
    }
    const option = definition.options[selected];
    if (!option) continue;
    if (option.kind === 'token' && definition.tokens) {
      classes.push(definition.tokens.value);
      declare(
        definition.tokens.property,
        `var(${option.cssVariable}, ${option.swatch})`,
      );
      continue;
    }
    const value = option.value.trim();
    if (!value) continue;
    if (option.attribute === 'class') {
      classes.push(value);
    } else if (option.attribute === 'style') {
      for (const [property, propertyValue] of Object.entries(
        parseInlineStyle(value),
      )) {
        declare(property, propertyValue);
      }
    }
  }
  const className = classes.join(' ').trim();
  return {
    className: className === '' ? undefined : className,
    style:
      Object.keys(style).length === 0 ? undefined : (style as CSSProperties),
  };
}

/**
 * Creates a `cn()` with a custom tailwind-merge configuration, for example
 * when a theme defines `text-hero` as a font size, which tailwind-merge
 * would otherwise read as a color and merge away.
 *
 * ```ts
 * export const cn = extendCn({
 *   extend: { classGroups: { 'font-size': [{ text: ['hero'] }] } },
 * });
 * ```
 */
export function extendCn(
  config: Parameters<typeof extendTailwindMerge>[0],
): (...inputs: ClassValue[]) => string {
  const merge = extendTailwindMerge(config);
  return (...inputs: ClassValue[]) => merge(clsx(inputs));
}

/**
 * The internal island prop that carries the selection to the island
 * renderer, which removes it before the component receives its props.
 *
 * @see \Drupal\canvas\Plugin\Canvas\ComponentSource\JsComponent::applyStyles()
 */
export const STYLE_OPTIONS_ISLAND_PROP = '__canvasStyleOptions';
