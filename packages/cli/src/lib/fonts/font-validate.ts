import fs from 'fs/promises';
import path from 'path';

import type { FontFamilyEntry, FontsConfig } from '../../config.js';

const VALID_PROVIDERS: ReadonlySet<string> = new Set([
  'google',
  'bunny',
  'fontshare',
  'fontsource',
  'npm',
  'adobe',
]);

/**
 * Validates font config before push: required name, local vs provider rules,
 * local file existence, and provider enum when present. Throws with all
 * errors listed so the user can fix the config in one go.
 */
export async function validateFontsConfig(
  config: FontsConfig,
  projectRoot: string,
): Promise<void> {
  const families = config.families;
  if (!Array.isArray(families) || families.length === 0) {
    return;
  }

  const errors: string[] = [];

  for (let i = 0; i < families.length; i++) {
    const entry = families[i] as FontFamilyEntry | undefined;
    const nameRaw = entry?.name;
    const name = typeof nameRaw === 'string' ? nameRaw.trim() : '';
    const label =
      name !== ''
        ? `Font family "${name}" (index ${i})`
        : `Font family at index ${i}`;

    if (name === '') {
      errors.push(`${label}: missing or empty "name".`);
      continue;
    }

    const src =
      typeof entry?.src === 'string' ? (entry.src as string).trim() : '';
    const isLocal = src !== '';

    if (isLocal) {
      const absolutePath = path.resolve(projectRoot, src);
      try {
        await fs.access(absolutePath);
      } catch {
        errors.push(`${label}: file not found: ${src}`);
      }
      continue;
    }

    const provider = entry?.provider;
    if (provider !== undefined && provider !== null) {
      const p = String(provider).toLowerCase();
      if (!VALID_PROVIDERS.has(p)) {
        errors.push(
          `${label}: invalid "provider": "${provider}". Expected one of: ${Array.from(VALID_PROVIDERS).join(', ')}.`,
        );
      }
    }
  }

  if (errors.length > 0) {
    throw new Error(`Font config validation failed:\n${errors.join('\n')}`);
  }
}
