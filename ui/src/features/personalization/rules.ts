import type {
  DayOfWeek,
  EditableConditionId,
  EditableSegmentRule,
  SegmentRule,
  UtmParametersCondition,
} from '@/types/Personalization';

/**
 * The condition types this client ships a dedicated editor for.
 *
 * Not the list of condition types that exist: the server discovers those, and
 * a third-party segmentation provider can add more. Use it only to decide
 * whether a rule can be edited in the dashboard.
 */
export const EDITABLE_CONDITION_IDS: EditableConditionId[] = [
  'query_parameter',
  'utm_parameters',
  'geolocation',
  'day_of_week',
];

export function isEditableCondition(
  conditionId: string,
): conditionId is EditableConditionId {
  return (EDITABLE_CONDITION_IDS as string[]).includes(conditionId);
}

/**
 * Narrows a rule to one this client can render an editor for.
 *
 * A guard on the rule rather than on its ID: an unknown rule's settings are
 * typed as `unknown`, and only narrowing the rule itself keeps the editors'
 * field access type-safe.
 */
export function isEditableRule(rule: SegmentRule): rule is EditableSegmentRule {
  return isEditableCondition(rule.id);
}

export const CONDITION_LABELS: Record<EditableConditionId, string> = {
  query_parameter: 'Query parameter',
  utm_parameters: 'UTM parameters',
  geolocation: 'Location',
  day_of_week: 'Day of week',
};

export const CONDITION_DESCRIPTIONS: Record<EditableConditionId, string> = {
  query_parameter: 'Match a parameter in the page URL',
  utm_parameters: 'Match UTM tracking parameters in the page URL',
  geolocation: 'Match the visitor country or region',
  day_of_week: 'Match the day of the visit',
};

export const UTM_KEYS = [
  'utm_id',
  'utm_source',
  'utm_medium',
  'utm_campaign',
  'utm_term',
  'utm_content',
] as const;

export const DAYS_OF_WEEK: DayOfWeek[] = [
  'monday',
  'tuesday',
  'wednesday',
  'thursday',
  'friday',
  'saturday',
  'sunday',
];

export const capitalize = (value: string): string =>
  value.charAt(0).toUpperCase() + value.slice(1);

/**
 * Creates the initial settings for a newly added rule.
 */
export function createDefaultRule(
  conditionId: EditableConditionId,
): EditableSegmentRule {
  switch (conditionId) {
    case 'query_parameter':
      return {
        id: 'query_parameter',
        negate: false,
        parameter: '',
        value: '',
        matching: 'exact',
      };
    case 'utm_parameters':
      return {
        id: 'utm_parameters',
        negate: false,
        all: true,
        parameters: [{ key: 'utm_source', value: '', matching: 'exact' }],
      };
    case 'geolocation':
      return {
        id: 'geolocation',
        negate: false,
        countries: [],
        regions: [],
      };
    case 'day_of_week':
      return {
        id: 'day_of_week',
        negate: false,
        days: [],
      };
  }
}

const utmParametersSummary = (rule: UtmParametersCondition): string => {
  if (rule.parameters.length === 0) {
    return 'No UTM parameters added yet';
  }
  const parts = rule.parameters.map(
    (parameter) =>
      `${parameter.key || 'a UTM parameter'} ${parameter.matching === 'exact' ? 'equals' : 'starts with'} "${parameter.value}"`,
  );
  return `The URL matches ${parts.join(rule.all ? ' and ' : ' or ')}`;
};

/**
 * Builds a plain-language, one-line summary of a rule.
 */
export function ruleSummary(rule: SegmentRule): string {
  if (!isEditableRule(rule)) {
    // The server owns this rule's settings, so the only honest summary is the
    // one its own plugin produces; say where it is edited rather than guess at
    // fields this client knows nothing about.
    return rule.negate
      ? 'Everyone except: visitors this rule matches (edited outside the dashboard)'
      : 'Visitors this rule matches (edited outside the dashboard)';
  }
  let summary = '';
  switch (rule.id) {
    case 'query_parameter':
      if (!rule.parameter) {
        summary = 'No query parameter set yet';
      } else if (rule.matching === 'present') {
        summary = `The URL includes the "${rule.parameter}" query parameter`;
      } else {
        summary = `The "${rule.parameter}" query parameter ${rule.matching === 'exact' ? 'equals' : 'starts with'} "${rule.value}"`;
      }
      break;
    case 'utm_parameters':
      summary = utmParametersSummary(rule);
      break;
    case 'geolocation': {
      if (rule.countries.length === 0) {
        summary = 'No countries selected yet';
      } else {
        summary = `The visitor is in ${rule.countries.join(', ')}`;
        if (rule.regions && rule.regions.length > 0) {
          summary += ` (regions: ${rule.regions.join(', ')})`;
        }
      }
      break;
    }
    case 'day_of_week':
      summary =
        rule.days.length === 0
          ? 'No days selected yet'
          : `The visit happens on ${rule.days.map(capitalize).join(', ')}`;
      break;
  }
  return rule.negate
    ? `Everyone except: ${summary.charAt(0).toLowerCase()}${summary.slice(1)}`
    : summary;
}
