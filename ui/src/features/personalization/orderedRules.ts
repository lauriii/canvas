import { EDITABLE_CONDITION_IDS } from '@/features/personalization/rules';

import type { SegmentRule, SegmentRules } from '@/types/Personalization';

/**
 * Every rule on a segment, in a stable order.
 *
 * Editable conditions come first in the dashboard's own order, then any other
 * condition type — one contributed by a third-party segmentation provider, for
 * instance — alphabetically. Iterating the rules themselves rather than a
 * hardcoded list of plugin IDs is what keeps a provider's rule visible instead
 * of silently absent from the segment it is actually part of.
 */
export function orderedRules(rules: SegmentRules | undefined): SegmentRule[] {
  const all = rules ?? {};
  const known = EDITABLE_CONDITION_IDS.filter((id) => all[id]);
  const rest = Object.keys(all)
    .filter(
      (id) => all[id] && !(EDITABLE_CONDITION_IDS as string[]).includes(id),
    )
    .sort();
  return [...known, ...rest].map((id) => all[id] as SegmentRule);
}
