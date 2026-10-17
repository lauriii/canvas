import { readFileSync } from 'node:fs';
import fs from 'node:fs/promises';
import path from 'node:path';
import { createUnifont, providers } from 'unifont';

import {
  BRAND_KIT_CONFIG_FILENAME,
  buildBrandKitColorCss,
  readBrandKitColors,
} from './brand-kit-colors';

import type { FontFaceData, ResolveFontOptions, Unifont } from 'unifont';

/** Font providers accepted in a `fonts.families` entry. */
export const FONT_PROVIDERS = [
  'google',
  'bunny',
  'fontshare',
  'fontsource',
  'npm',
  'adobe',
] as const;

export type FontProvider = (typeof FONT_PROVIDERS)[number];

/** Defaults for provider-based font resolution (weights, styles, subsets). */
export interface FontDefaults {
  weights?: string[];
  styles?: string[];
  subsets?: string[];
}

/** Provider-specific options (e.g. Adobe kit ID). */
export interface FontProviderOptions {
  adobe?: { id: string[] };
}

/** Per-axis default value override (axis tag -> number). Used for variable fonts. */
export type FontAxisDefaults = Record<string, number>;

/** Shared fields for all font family entries. */
interface FontFamilyEntryBase {
  name: string;
  weights?: string[];
  styles?: string[];
  /** Optional axis default overrides for variable fonts (e.g. { "wght": 500 }). Clamped to axis min/max. */
  axisDefaults?: FontAxisDefaults;
}

/** Font family entry for a local file. */
export interface LocalFontFamilyEntry extends FontFamilyEntryBase {
  src: string;
  provider?: never;
  subsets?: never;
}

/** Font family entry for a provider-based font. */
export interface ProviderFontFamilyEntry extends FontFamilyEntryBase {
  provider?: FontProvider;
  src?: never;
  /** Subsets to request from the provider (e.g. ['latin', 'cyrillic']). */
  subsets?: string[];
}

/** A single font family entry in canvas.brand-kit.json families. */
export type FontFamilyEntry = LocalFontFamilyEntry | ProviderFontFamilyEntry;

/** The `fonts` key of canvas.brand-kit.json. */
export interface FontsConfig {
  defaults?: FontDefaults;
  families: FontFamilyEntry[];
  providers?: FontProviderOptions;
}

/**
 * One `@font-face` rule to render: a local file (`path`, absolute) or a
 * provider-hosted file (`url`).
 */
export interface BrandKitFontFace {
  family: string;
  weight: string;
  style: string;
  /** Font file extension or provider format, such as `woff2` or `ttf`. */
  format: string;
  path?: string;
  url?: string;
}

/**
 * Normalizes family, weight, and style into a single comparable key.
 */
export function fontVariantKey(
  family: string,
  weight: string | number,
  style: string | number,
): string {
  const f = String(family || '')
    .trim()
    .toLowerCase();
  const w =
    String(weight ?? '400')
      .trim()
      .toLowerCase() || '400';
  const s =
    String(style ?? 'normal')
      .trim()
      .toLowerCase() || 'normal';
  return `${f}\0${w}\0${s}`;
}

/**
 * Resolve options for a provider-based family: its own weights and styles,
 * else the `defaults`, else 400 normal. Subsets fall back to `latin` only,
 * since provider defaults can pull in many subsets (e.g. 100+ for CJK).
 */
export function brandKitFontResolveOptions(
  defaults: FontDefaults | undefined,
  entry: FontFamilyEntry,
): ResolveFontOptions {
  const weights = (
    entry.weights?.length ? entry.weights : (defaults?.weights ?? ['400'])
  ).map((w) => String(w).trim().toLowerCase());
  const styles = (
    entry.styles?.length ? entry.styles : (defaults?.styles ?? ['normal'])
  ) as ResolveFontOptions['styles'];
  const subsets =
    entry.subsets && entry.subsets.length > 0 ? entry.subsets : ['latin'];
  // Pass only the options needed; unifont merges with its defaults. This
  // avoids requesting unnecessary variants.
  return { weights, styles, subsets, formats: ['woff2'] };
}

/**
 * Builds a unifont instance with all providers enabled from config.
 * Resolves font families to FontFaceData (with URLs).
 */
export async function createBrandKitFontResolver(
  config: FontsConfig,
  projectRoot: string,
): Promise<Unifont<any>> {
  const list = [
    providers.google(),
    providers.bunny(),
    providers.fontshare(),
    providers.fontsource(),
    providers.npm({
      readFile: (p: string) =>
        fs.readFile(path.resolve(projectRoot, p), 'utf-8').catch(() => null),
      root: projectRoot,
    }),
    ...(config.providers?.adobe?.id?.length
      ? [providers.adobe({ id: config.providers.adobe.id })]
      : []),
  ];
  return createUnifont(list as [(typeof list)[0], ...typeof list]);
}

/** The weight a resolved face reports, as a config weight string. */
export function resolvedFontFaceWeight(face: FontFaceData): string {
  return face.weight === undefined
    ? '400'
    : Array.isArray(face.weight)
      ? face.weight.join(' ')
      : String(face.weight);
}

/**
 * Keeps one resolved face per requested (weight, style). unifont may return
 * many per variant (e.g. one per subset).
 */
export function selectResolvedFontFaces(
  entry: FontFamilyEntry,
  options: ResolveFontOptions,
  faces: FontFaceData[],
): FontFaceData[] {
  const requested = new Set<string>();
  for (const w of options.weights) {
    for (const s of options.styles) {
      requested.add(fontVariantKey(entry.name, w, s));
      // Variable faces (e.g. npm @fontsource-variable) report weight as
      // [min, max]; a config range "100 900" must also match weight "100".
      if (typeof w === 'string' && w.includes(' ')) {
        const rangeMin = w.split(/\s+/)[0];
        if (rangeMin) {
          requested.add(fontVariantKey(entry.name, rangeMin, s));
        }
      }
    }
  }
  const selected = new Map<string, FontFaceData>();
  for (const face of faces) {
    const key = fontVariantKey(
      entry.name,
      resolvedFontFaceWeight(face),
      face.style ?? 'normal',
    );
    if (requested.has(key) && !selected.has(key)) {
      selected.set(key, face);
    }
  }
  return [...selected.values()];
}

/**
 * Leniently normalizes a raw `fonts` value: entries without a usable name
 * are skipped; a non-object value or missing `families` array yields null.
 * Strict validation is the CLI's job.
 */
export function normalizeBrandKitFonts(fonts: unknown): FontsConfig | null {
  if (!fonts || typeof fonts !== 'object' || Array.isArray(fonts)) {
    return null;
  }
  const raw = fonts as Partial<FontsConfig>;
  if (!Array.isArray(raw.families)) {
    return null;
  }
  return {
    ...raw,
    families: raw.families.filter(
      (entry): entry is FontFamilyEntry =>
        !!entry &&
        typeof entry === 'object' &&
        typeof entry.name === 'string' &&
        entry.name.trim() !== '',
    ),
  };
}

/**
 * Leniently reads the `fonts` key from canvas.brand-kit.json; a missing
 * file, invalid JSON, or unusable `fonts` value yields null.
 */
export function readBrandKitFonts(hostRoot: string): FontsConfig | null {
  let parsed: unknown;
  try {
    parsed = JSON.parse(
      readFileSync(path.resolve(hostRoot, BRAND_KIT_CONFIG_FILENAME), 'utf-8'),
    );
  } catch {
    return null;
  }
  if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed)) {
    return null;
  }
  return normalizeBrandKitFonts((parsed as { fonts?: unknown }).fonts);
}

/**
 * Resolves every family to the faces the site would serve after
 * `canvas push`: a local `src` is one face (resolved against the project
 * root, like the CLI does), a provider family is resolved through unifont.
 * A provider family is resolved only through its `provider` when one is
 * set. Families that fail to resolve (offline, unknown family) are skipped
 * and reported through `onError`.
 */
export async function resolveBrandKitFontFaces(
  config: FontsConfig,
  projectRoot: string,
  options: {
    resolver?: Pick<Unifont<any>, 'resolveFont'>;
    onError?: (family: string, error: unknown) => void;
  } = {},
): Promise<BrandKitFontFace[]> {
  const faces: BrandKitFontFace[] = [];
  let resolver = options.resolver;
  for (const entry of config.families) {
    if (typeof entry.src === 'string') {
      faces.push({
        family: entry.name,
        weight: entry.weights?.[0] ?? '400',
        style: entry.styles?.[0] ?? 'normal',
        format: path.extname(entry.src).slice(1).toLowerCase() || 'woff2',
        path: path.resolve(projectRoot, entry.src),
      });
      continue;
    }
    try {
      resolver ??= await createBrandKitFontResolver(config, projectRoot);
      const resolveOptions = brandKitFontResolveOptions(config.defaults, entry);
      const resolved = await resolver.resolveFont(
        entry.name,
        resolveOptions,
        entry.provider ? [entry.provider] : undefined,
      );
      for (const face of selectResolvedFontFaces(
        entry,
        resolveOptions,
        resolved?.fonts ?? [],
      )) {
        const remote = face.src.find(
          (src): src is { url: string; format?: string } => 'url' in src,
        );
        if (!remote) {
          continue;
        }
        faces.push({
          family: entry.name,
          weight: resolvedFontFaceWeight(face),
          style: face.style ?? 'normal',
          format: remote.format ?? 'woff2',
          url: remote.url,
        });
      }
    } catch (error) {
      options.onError?.(entry.name, error);
    }
  }
  return faces;
}

/** Escapes a string for use inside single-quoted CSS values. */
function escapeCssString(value: string): string {
  return value.replaceAll('\\', '\\\\').replaceAll("'", "\\'");
}

/** Maps a font file extension to its CSS `format()` label. */
export function fontFormatLabel(format: string): string {
  return (
    { ttf: 'truetype', otf: 'opentype' }[format.toLowerCase()] ??
    format.toLowerCase()
  );
}

/** URL for a local font face; may read or copy the file. */
export type BrandKitLocalFontUrl = (
  face: BrandKitFontFace & { path: string },
) => string | Promise<string>;

/**
 * Builds `@font-face` rules in family order, in the same shape as the
 * site's generated brand kit CSS (`BrandKit::buildFontFaceRule()`). Local
 * faces get their URL from `localUrl`: a dev server path, a path relative to
 * a generated stylesheet, or a data URL. Faces whose `localUrl` throws (e.g.
 * an unreadable file) are skipped; an empty result is the empty string.
 */
export async function buildBrandKitFontCss(
  faces: BrandKitFontFace[],
  localUrl: BrandKitLocalFontUrl,
): Promise<string> {
  const rules = await Promise.all(
    faces.map(async (face) => {
      let url = face.url ?? '';
      if (face.path !== undefined) {
        try {
          url = await localUrl(face as BrandKitFontFace & { path: string });
        } catch {
          return null;
        }
      }
      return [
        '@font-face {',
        `  font-family: '${escapeCssString(face.family)}';`,
        `  src: url('${escapeCssString(url)}') format('${fontFormatLabel(face.format)}');`,
        `  font-weight: ${face.weight};`,
        `  font-style: ${face.style};`,
        '  font-display: swap;',
        '}',
      ].join('\n');
    }),
  );
  return rules.filter((rule) => rule !== null).join('\n\n');
}

/**
 * Reads a local font face into a `data:font/<format>;base64,` URL, for
 * self-contained HTML. Throws when the file cannot be read.
 */
export async function fontFileDataUrl(
  face: BrandKitFontFace & { path: string },
): Promise<string> {
  const data = await fs.readFile(face.path);
  return `data:font/${face.format};base64,${data.toString('base64')}`;
}

/**
 * Builds the brand kit stylesheet for a project from canvas.brand-kit.json:
 * `@font-face` rules, then the colors `:root` block, the order of the site's
 * generated brand kit CSS. Framework-agnostic (Node only): callers choose how
 * local font files are referenced through `localFontUrl`. Provider families
 * need network access; families that fail to resolve are skipped and reported
 * through `onFontError`. Lenient: a missing or malformed file yields `''`.
 */
export async function buildBrandKitCss(
  projectRoot: string,
  options: {
    localFontUrl: BrandKitLocalFontUrl;
    onFontError?: (family: string, error: unknown) => void;
    resolver?: Pick<Unifont<any>, 'resolveFont'>;
  },
): Promise<string> {
  const fonts = readBrandKitFonts(projectRoot);
  const fontCss = fonts
    ? await buildBrandKitFontCss(
        await resolveBrandKitFontFaces(fonts, projectRoot, {
          resolver: options.resolver,
          onError: options.onFontError,
        }),
        options.localFontUrl,
      )
    : '';
  return [fontCss, buildBrandKitColorCss(readBrandKitColors(projectRoot))]
    .filter((css) => css !== '')
    .join('\n\n');
}
