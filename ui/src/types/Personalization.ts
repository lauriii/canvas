/**
 * Condition plugin IDs the dashboard ships a dedicated editor for.
 *
 * A segment can hold at most one instance of each condition type. Any other
 * condition type — one provided by a third-party segmentation provider module,
 * for instance — is still discovered, listed and summarised; it is just edited
 * through its Drupal plugin form rather than in here.
 */
export type EditableConditionId =
  | 'query_parameter'
  | 'utm_parameters'
  | 'geolocation'
  | 'day_of_week';

/**
 * Any condition plugin ID, including ones this client knows nothing about.
 */
export type ConditionId = EditableConditionId | (string & {});

/**
 * A segment condition type as the server reports it.
 */
export interface ConditionDefinition {
  id: ConditionId;
  label: string;
  provider: string;
}

export type QueryParameterMatching = 'exact' | 'starts_with' | 'present';

export interface QueryParameterCondition {
  id: 'query_parameter';
  negate: boolean;
  parameter: string;
  value: string;
  matching: QueryParameterMatching;
}

export type UtmParameterMatching = 'exact' | 'starts_with';

export interface UtmParameter {
  // One of the standard utm_* keys, or a custom parameter name.
  key: string;
  value: string;
  matching: UtmParameterMatching;
}

export interface UtmParametersCondition {
  id: 'utm_parameters';
  negate: boolean;
  // When true, all parameters must match; otherwise any single match counts.
  all: boolean;
  parameters: UtmParameter[];
}

export interface GeolocationCondition {
  id: 'geolocation';
  negate: boolean;
  // ISO 3166-1 alpha-2 codes, uppercase.
  countries: string[];
  // Optional region codes, uppercase, 1-3 alphanumeric characters.
  regions?: string[];
}

export type DayOfWeek =
  | 'monday'
  | 'tuesday'
  | 'wednesday'
  | 'thursday'
  | 'friday'
  | 'saturday'
  | 'sunday';

export interface DayOfWeekCondition {
  id: 'day_of_week';
  negate: boolean;
  days: DayOfWeek[];
}

/**
 * A rule of a condition type this client has no editor for.
 *
 * Its settings are opaque here on purpose: only the server's plugin knows
 * their shape, and inventing one would corrupt them on save.
 */
export interface UnknownCondition {
  id: string;
  negate: boolean;
  [setting: string]: unknown;
}

export type EditableSegmentRule =
  | QueryParameterCondition
  | UtmParametersCondition
  | GeolocationCondition
  | DayOfWeekCondition;

export type SegmentRule = EditableSegmentRule | UnknownCondition;

/**
 * Rules keyed by condition plugin ID, at most one instance per type.
 *
 * Deliberately open: a segment may carry rules of types provided by other
 * modules, and they must survive a round trip through this client untouched.
 */
export type SegmentRules = Partial<{
  query_parameter: QueryParameterCondition;
  utm_parameters: UtmParametersCondition;
  geolocation: GeolocationCondition;
  day_of_week: DayOfWeekCondition;
}> &
  Record<string, SegmentRule | undefined>;

export interface Segment {
  id: string;
  label: string;
  description?: string;
  status: boolean;
  weight: number;
  rules?: SegmentRules;
}
