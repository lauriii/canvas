import {
  DEFAULT_VARIANT_ID,
  findSwitchNodes,
  getCaseSegmentIds,
  getCaseVariantId,
  getSwitchCases,
  getSwitchVariants,
  isCaseDisabled,
} from './personalizationUtils';

import type {
  DayOfWeek,
  DayOfWeekCondition,
  GeolocationCondition,
  QueryParameterCondition,
  Segment,
  SegmentRule,
  UtmParametersCondition,
} from '@/types/Personalization';
import type { ComponentModels, RegionNode } from './layoutModelSlice';

// The server is authoritative for segment evaluation. Everything in this
// module mirrors modules/canvas_personalization/src/Plugin/SegmentCondition
// and SegmentEvaluator.php for authoring-time simulation only; it must never
// drive what real visitors see.

/**
 * The request context of a simulated visitor. An absent field simulates
 * absent request data: no such query parameter, an unknown country or
 * region, no particular day. Rules fail closed on absent data, exactly like
 * the server, and `negate` is applied after that.
 */
export interface SimulatedVisitor {
  query: Record<string, string>;
  country?: string;
  region?: string;
  day?: DayOfWeek;
}

const evaluateQueryParameter = (
  rule: QueryParameterCondition,
  visitor: SimulatedVisitor,
): boolean => {
  const actual = visitor.query[rule.parameter];
  // An absent parameter never matches, regardless of the matching mode.
  if (actual === undefined) {
    return false;
  }
  switch (rule.matching) {
    case 'present':
      return true;
    case 'exact':
      return actual === rule.value;
    case 'starts_with':
      return actual.startsWith(rule.value);
  }
};

const evaluateUtmParameters = (
  rule: UtmParametersCondition,
  visitor: SimulatedVisitor,
): boolean => {
  if (rule.parameters.length === 0) {
    return true;
  }
  const matches = rule.parameters.map((parameter) => {
    // The server reads an absent parameter as the empty string here, so an
    // exact match against a configured empty value succeeds while
    // starts_with explicitly rejects the empty actual value.
    const actual = visitor.query[parameter.key] ?? '';
    switch (parameter.matching) {
      case 'exact':
        return parameter.value === actual;
      case 'starts_with':
        return actual !== '' && actual.startsWith(parameter.value);
    }
  });
  return rule.all ? !matches.includes(false) : matches.includes(true);
};

const evaluateGeolocation = (
  rule: GeolocationCondition,
  visitor: SimulatedVisitor,
): boolean => {
  const country = (visitor.country ?? '').toUpperCase();
  if (country === '' || !rule.countries.includes(country)) {
    return false;
  }
  const regions = rule.regions ?? [];
  if (regions.length > 0) {
    const region = (visitor.region ?? '').toUpperCase();
    return region !== '' && regions.includes(region);
  }
  return true;
};

const evaluateDayOfWeek = (
  rule: DayOfWeekCondition,
  visitor: SimulatedVisitor,
): boolean => {
  return visitor.day !== undefined && rule.days.includes(visitor.day);
};

const evaluateRule = (
  rule: SegmentRule,
  visitor: SimulatedVisitor,
): boolean => {
  switch (rule.id) {
    case 'query_parameter':
      return evaluateQueryParameter(rule, visitor);
    case 'utm_parameters':
      return evaluateUtmParameters(rule, visitor);
    case 'geolocation':
      return evaluateGeolocation(rule, visitor);
    case 'day_of_week':
      return evaluateDayOfWeek(rule, visitor);
  }
};

/**
 * Whether a segment matches a simulated visitor: every rule must match, a
 * segment with zero rules matches everyone, and a missing or disabled
 * segment never matches (fail closed, like the server).
 */
export function evaluateSegmentForVisitor(
  segment: Segment | undefined,
  visitor: SimulatedVisitor,
): boolean {
  if (!segment || !segment.status) {
    return false;
  }
  const rules: SegmentRule[] = Object.values(segment.rules ?? {});
  return rules.every((rule) => {
    const result = evaluateRule(rule, visitor);
    return rule.negate ? !result : result;
  });
}

/**
 * Resolves the variant each switch would serve to a simulated visitor:
 * cases are walked in the switch's variant order, a case matches when all
 * its segments match, disabled cases are skipped, and the first match wins.
 * No match falls back to the default variant.
 */
export function resolveVisitorVariants(
  layout: RegionNode[],
  model: ComponentModels,
  segments: Record<string, Segment> | undefined,
  visitor: SimulatedVisitor,
): Record<string, string> {
  const result: Record<string, string> = {};
  for (const switchNode of findSwitchNodes(layout)) {
    const caseByVariantId = new Map(
      getSwitchCases(switchNode).map((caseNode) => [
        getCaseVariantId(model, caseNode),
        caseNode,
      ]),
    );
    let matched: string | null = null;
    for (const variantId of getSwitchVariants(model, switchNode.uuid)) {
      const caseNode = caseByVariantId.get(variantId);
      if (!caseNode || isCaseDisabled(model, caseNode)) {
        continue;
      }
      const segmentIds = getCaseSegmentIds(model, caseNode);
      if (
        segmentIds.every((segmentId) =>
          evaluateSegmentForVisitor(segments?.[segmentId], visitor),
        )
      ) {
        matched = variantId;
        break;
      }
    }
    result[switchNode.uuid] = matched ?? DEFAULT_VARIANT_ID;
  }
  return result;
}

/**
 * The simulation inputs worth offering for a set of referenced segments.
 */
export interface SimulationInputs {
  // Query parameter names any query or UTM rule reads, sorted.
  queryParameters: string[];
  // Country codes any geolocation rule targets, uppercase and sorted.
  countries: string[];
  // Whether any day of week rule exists.
  days: boolean;
}

/**
 * Derives which visitor inputs can influence the outcome, from the rules
 * the referenced segments actually use. Missing and disabled segments never
 * match regardless of input, so their rules are not considered.
 */
export function collectSimulationInputs(
  segments: Record<string, Segment> | undefined,
  referencedSegmentIds: string[],
): SimulationInputs {
  const queryParameters = new Set<string>();
  const countries = new Set<string>();
  let days = false;
  for (const segmentId of new Set(referencedSegmentIds)) {
    const segment = segments?.[segmentId];
    if (!segment || !segment.status) {
      continue;
    }
    const rules = segment.rules ?? {};
    if (rules.query_parameter && rules.query_parameter.parameter !== '') {
      queryParameters.add(rules.query_parameter.parameter);
    }
    for (const parameter of rules.utm_parameters?.parameters ?? []) {
      if (parameter.key !== '') {
        queryParameters.add(parameter.key);
      }
    }
    for (const country of rules.geolocation?.countries ?? []) {
      countries.add(country.toUpperCase());
    }
    if (rules.day_of_week) {
      days = true;
    }
  }
  return {
    queryParameters: [...queryParameters].sort(),
    countries: [...countries].sort(),
    days,
  };
}
