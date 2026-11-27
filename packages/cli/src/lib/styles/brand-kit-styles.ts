import fs from 'fs/promises';
import path from 'path';
import {
  BRAND_KIT_CONFIG_FILENAME,
  BRAND_KIT_SCHEMA_URL,
} from '@drupal-canvas/discovery';

/**
 * Which style options this site offers, as stored in the brand kit and in
 * `canvas.brand-kit.json` under `styles`.
 *
 * @see \Drupal\canvas\Entity\BrandKit::getStyleOptions()
 */
export interface BrandKitStyleEntry {
  label?: string;
  options?: string[];
  labels?: Record<string, string>;
  fallbacks?: Record<string, string>;
}

export type BrandKitStyles = Record<string, BrandKitStyleEntry>;

/**
 * Checks what the JSON Schema cannot: each fallback must point at an option
 * the style offers. Mirrors the server's BrandKit::validateStyles().
 */
export function collectBrandKitStyleErrors(styles: unknown): string[] {
  if (!styles || typeof styles !== 'object' || Array.isArray(styles)) {
    return [];
  }
  const errors: string[] = [];
  for (const [styleId, entry] of Object.entries(
    styles as Record<string, BrandKitStyleEntry>,
  )) {
    if (!entry || !Array.isArray(entry.options)) {
      continue;
    }
    for (const [from, to] of Object.entries(entry.fallbacks ?? {})) {
      if (!entry.options.includes(to)) {
        errors.push(
          `${styleId}: the fallback for "${from}" is "${to}", which is not in options. Fall back to an option this site offers.`,
        );
      }
    }
  }
  return errors;
}

/**
 * Writes the site's style options into canvas.brand-kit.json, keeping every
 * other key. Removes `styles` when the site offers every option.
 */
export async function writeBrandKitStylesConfig(
  projectRoot: string,
  styles: BrandKitStyles | null | undefined,
): Promise<boolean> {
  const configPath = path.resolve(projectRoot, BRAND_KIT_CONFIG_FILENAME);
  let fileContent: Record<string, unknown> = { $schema: BRAND_KIT_SCHEMA_URL };
  let exists = true;
  try {
    const parsed = JSON.parse(await fs.readFile(configPath, 'utf-8'));
    if (parsed && typeof parsed === 'object' && !Array.isArray(parsed)) {
      fileContent = parsed as Record<string, unknown>;
    }
  } catch (error) {
    if ((error as NodeJS.ErrnoException).code !== 'ENOENT') {
      throw error;
    }
    exists = false;
  }
  const hasStyles = styles != null && Object.keys(styles).length > 0;
  if (
    JSON.stringify(fileContent.styles ?? null) ===
    JSON.stringify(hasStyles ? styles : null)
  ) {
    return false;
  }
  if (!hasStyles && !exists) {
    return false;
  }
  // eslint-disable-next-line @typescript-eslint/no-unused-vars
  const { styles: _previous, ...rest } = fileContent;
  await fs.writeFile(
    configPath,
    `${JSON.stringify(hasStyles ? { ...rest, styles } : rest, null, 2)}\n`,
    'utf-8',
  );
  return true;
}
