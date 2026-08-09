/**
 * Condition plugin IDs supported by segment rules.
 *
 * A segment can hold at most one instance of each condition type.
 */
export type ConditionId =
  | 'query_parameter'
  | 'utm_parameters'
  | 'geolocation'
  | 'day_of_week';

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

export type SegmentRule =
  | QueryParameterCondition
  | UtmParametersCondition
  | GeolocationCondition
  | DayOfWeekCondition;

/**
 * Rules keyed by condition plugin ID, at most one instance per type.
 */
export type SegmentRules = Partial<{
  query_parameter: QueryParameterCondition;
  utm_parameters: UtmParametersCondition;
  geolocation: GeolocationCondition;
  day_of_week: DayOfWeekCondition;
}>;

export interface Segment {
  id: string;
  label: string;
  description?: string;
  status: boolean;
  weight: number;
  rules?: SegmentRules;
}
