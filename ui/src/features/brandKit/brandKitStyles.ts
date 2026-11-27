import type {
  BrandKitStyleEntry,
  BrandKitStyles,
  StyleDefinition,
} from '@/types/Styles';

/**
 * Editing rules for the brand kit's style options.
 *
 * @see \Drupal\canvas\Entity\BrandKit::validateStyles()
 * @see \Drupal\canvas\Style\ComponentStyles::definitions()
 */

/** A style's fixed options: not brand kit colors. */
export const fixedOptions = (definition: StyleDefinition) =>
  definition.options.filter((option) => option.kind !== 'token');

/** The option IDs a style offers under an entry, in definition order. */
export function offeredOptions(
  definition: StyleDefinition,
  entry: BrandKitStyleEntry | undefined,
): string[] {
  // Brand kit colors are curated in the Colors tab, not per style.
  const all = fixedOptions(definition).map((option) => option.id);
  return entry?.options ? all.filter((id) => entry.options!.includes(id)) : all;
}

/**
 * The offered option nearest to `optionId` in definition order, preferring
 * the one before it: "Large" falls back to "Medium", not "Small".
 */
export function nearestOffered(
  definition: StyleDefinition,
  optionId: string,
  offered: string[],
): string | undefined {
  const order = fixedOptions(definition).map((option) => option.id);
  const index = order.indexOf(optionId);
  for (let distance = 1; distance < order.length; distance++) {
    for (const candidate of [
      order[index - distance],
      order[index + distance],
    ]) {
      if (candidate !== undefined && offered.includes(candidate)) {
        return candidate;
      }
    }
  }
  return undefined;
}

/**
 * Drops what the defaults already say, so exports stay minimal: `options`
 * when every option is offered, empty labels, labels equal to the original,
 * and fallbacks that are offered or point at an option that is not. Names of
 * withdrawn options are kept, so offering one again restores its name.
 *
 * @return The entry, or undefined when nothing differs from the defaults.
 */
export function normalizeEntry(
  definition: StyleDefinition,
  entry: BrandKitStyleEntry,
): BrandKitStyleEntry | undefined {
  const offered = offeredOptions(definition, entry);
  const normalized: BrandKitStyleEntry = {};
  const label = entry.label?.trim();
  if (label && label !== definition.originalLabel) {
    normalized.label = label;
  }
  if (offered.length !== fixedOptions(definition).length) {
    normalized.options = offered;
  }
  const labels = Object.fromEntries(
    Object.entries(entry.labels ?? {})
      .map(([id, value]) => [id, value.trim()] as const)
      .filter(
        ([id, value]) =>
          value !== '' &&
          value !==
            definition.options.find((option) => option.id === id)
              ?.originalLabel,
      ),
  );
  if (Object.keys(labels).length > 0) {
    normalized.labels = labels;
  }
  const fallbacks = Object.fromEntries(
    Object.entries(entry.fallbacks ?? {}).filter(
      ([from, to]) => !offered.includes(from) && offered.includes(to),
    ),
  );
  if (Object.keys(fallbacks).length > 0) {
    normalized.fallbacks = fallbacks;
  }
  // Custom values are on unless the site turns them off.
  if (entry.custom === false && definition.custom !== null) {
    normalized.custom = false;
  }
  return Object.keys(normalized).length > 0 ? normalized : undefined;
}

/** Sets one style's entry, removing it when it matches the defaults. */
export function withEntry(
  styles: BrandKitStyles,
  definition: StyleDefinition,
  entry: BrandKitStyleEntry,
): BrandKitStyles {
  const next = { ...styles };
  const normalized = normalizeEntry(definition, entry);
  if (normalized) {
    next[definition.id] = normalized;
  } else {
    delete next[definition.id];
  }
  return next;
}

/**
 * Offers or withdraws an option. Withdrawing an option that content uses
 * falls it back to the nearest offered option, so pages keep a look; the
 * site builder can change or clear that fallback.
 */
export function toggleOption(
  definition: StyleDefinition,
  entry: BrandKitStyleEntry | undefined,
  optionId: string,
  offer: boolean,
  usage: number,
): BrandKitStyleEntry {
  const current = offeredOptions(definition, entry);
  const offered = offer
    ? [...current, optionId]
    : current.filter((id) => id !== optionId);
  const fallbacks = { ...(entry?.fallbacks ?? {}) };
  delete fallbacks[optionId];
  if (!offer && usage > 0) {
    const nearest = nearestOffered(definition, optionId, offered);
    if (nearest) {
      fallbacks[optionId] = nearest;
    }
  }
  return { ...entry, options: offered, fallbacks };
}
