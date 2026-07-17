import type {
  AdaptedPropSource,
  PropSource,
} from '@/features/layout/layoutModelSlice';

/**
 * Types and pure serialization logic for component prop adapters (no-code
 * value transforms).
 *
 * The adapter configuration panel edits an ordered list of steps. Each step
 * wraps the previous one when serialized: step N's primary input (the first
 * `required` input in declaration order) holds step N-1's serialized source,
 * and the last step is the outermost object.
 *
 * @see \Drupal\canvas\PropSource\AdaptedPropSource
 * @see \Drupal\canvas\ShapeMatcher\PropSourceSuggester
 */

// One selectable field candidate for an adapter input slot. Its `source` is a
// ready-to-use prop source that is written verbatim as the input's value.
export interface SlotCandidate {
  id: string;
  label: string;
  source: PropSource;
}

// A StaticPropSource template used to write literal values into a slot: it is
// cloned with `value` set when the user provides a literal.
export interface StaticSlotTemplate {
  sourceType: string;
  expression: string;
  value: unknown;
}

export interface SlotSchema {
  type?: string;
  enum?: Array<string | number>;
  [key: string]: unknown;
}

// One input slot of an adapter, as delivered by the suggestions endpoint.
export interface AdapterInputSlot {
  name: string;
  required: boolean;
  mirrorsOutput: boolean;
  // `null` means any value is accepted.
  schema: SlotSchema | null;
  candidates: SlotCandidate[];
  // `null` when the slot's shape is not storable as a static value; only
  // field/step binding is offered then.
  static: StaticSlotTemplate | null;
}

export interface AdapterDefinition {
  id: string;
  // Ordered (declaration order).
  inputs: AdapterInputSlot[];
}

// A per-prop suggestion item describing an adapter (it carries an `adapter`
// key instead of the `source` key that field suggestions carry).
export interface AdapterSuggestion {
  id?: string;
  label: string;
  adapter: AdapterDefinition;
}

// One key/value row of the mapping `cases` editor.
export interface MappingRow {
  key: string;
  value: string;
  // The original typed output value this row was parsed from. Kept so an
  // unedited row keeps its type when serialized again (e.g. {"1": 1} must
  // not become {"1": "1"}).
  originalValue?: unknown;
}

export type SlotMode = 'field' | 'literal';

export interface SlotBinding {
  mode: SlotMode;
  // Whether the slot participates in serialization. Required slots are always
  // enabled; optional slots start disabled until revealed by the user.
  enabled: boolean;
  // Field mode: the id of the selected candidate, when the bound source
  // corresponds to one of the slot's candidates.
  candidateId?: string;
  // Field mode: the prop source written verbatim. Preserved even when it does
  // not match any candidate (e.g. the candidate list changed since binding).
  source?: PropSource;
  // Literal mode: the value written into a clone of the slot's static
  // template.
  value?: string | number | boolean | null;
  // Literal mode, mapping-style slots: key/value rows serialized to a JSON
  // object string. Takes precedence over `value` when set.
  rows?: MappingRow[];
}

// One step of the transform chain as edited in the panel.
export interface AdapterStep {
  label: string;
  adapter: AdapterDefinition;
  bindings: Record<string, SlotBinding>;
}

const ADAPTER_SOURCE_TYPE_PREFIX = 'adapter:';

export const isAdaptedSource = (
  source: unknown,
): source is AdaptedPropSource => {
  if (!source || typeof source !== 'object') {
    return false;
  }
  const candidate = source as Partial<AdaptedPropSource>;
  return (
    typeof candidate.sourceType === 'string' &&
    candidate.sourceType.startsWith(ADAPTER_SOURCE_TYPE_PREFIX) &&
    typeof candidate.adapterInputs === 'object' &&
    candidate.adapterInputs !== null
  );
};

// Turns an input machine name into a display label, e.g. "text_1" → "Text 1".
export const humanizeInputName = (name: string): string => {
  const spaced = name.replace(/_+/g, ' ').trim();
  if (spaced === '') {
    return name;
  }
  return spaced.charAt(0).toUpperCase() + spaced.slice(1);
};

// The primary input carries the previous step's output when steps are
// chained: it is the first `required` input in declaration order.
export const getPrimaryInputName = (adapter: AdapterDefinition): string => {
  const required = adapter.inputs.find((input) => input.required);
  return (required ?? adapter.inputs[0])?.name ?? '';
};

// The mapping adapter's `cases` slot is edited as key/value rows rather than
// a raw JSON string.
export const isMappingRowsSlot = (
  adapterId: string,
  slotName: string,
): boolean => adapterId === 'mapping' && slotName === 'cases';

// Whether a slot can be bound to a literal value. Slots without a static
// template, and slots whose shape is an object or array (a plain text input
// cannot produce those), are field-bound only.
export const supportsLiteralBinding = (slot: AdapterInputSlot): boolean => {
  if (slot.static === null) {
    return false;
  }
  const { schema } = slot;
  if (!schema) {
    // `null` schema means any value is accepted: a text literal works.
    return true;
  }
  if (schema.type === 'object' || schema.type === 'array') {
    return false;
  }
  // A $ref without an explicit primitive type denotes an object-like shape.
  if (schema.$ref !== undefined && schema.type === undefined) {
    return false;
  }
  return true;
};

// The output value type of an adapter, derived from any input that mirrors
// the target prop shape (e.g. mapping's `default`). Falls back to string.
export const getMirroredOutputType = (adapter: AdapterDefinition): string => {
  const mirrored = adapter.inputs.find(
    (input) => input.mirrorsOutput && typeof input.schema?.type === 'string',
  );
  return mirrored?.schema?.type ?? 'string';
};

// The canonical string representation of a mapping case output, used both to
// populate the rows editor and to detect whether a row has been edited.
const caseOutputToString = (value: unknown): string =>
  typeof value === 'string' ? value : (JSON.stringify(value) ?? '');

const coerceByType = (value: string, type: string): unknown => {
  switch (type) {
    case 'integer': {
      const parsed = parseInt(value, 10);
      return Number.isNaN(parsed) ? value : parsed;
    }
    case 'number': {
      const parsed = parseFloat(value);
      return Number.isNaN(parsed) ? value : parsed;
    }
    case 'boolean':
      return value === 'true' ? true : value === 'false' ? false : value;
    default:
      return value;
  }
};

// The typed output value of one mapping row: an unedited row keeps its
// original typed value; edited or new rows are coerced to the adapter's
// mirrored output type.
const coerceCaseOutput = (row: MappingRow, outputType: string): unknown => {
  if (
    row.originalValue !== undefined &&
    caseOutputToString(row.originalValue) === row.value
  ) {
    return row.originalValue;
  }
  return coerceByType(row.value, outputType);
};

export const serializeMappingRows = (
  rows: MappingRow[],
  outputType: string = 'string',
): string => {
  const populated = rows.filter((row) => row.key !== '');
  return JSON.stringify(
    Object.fromEntries(
      populated.map((row) => [row.key, coerceCaseOutput(row, outputType)]),
    ),
  );
};

export const parseMappingRows = (value: unknown): MappingRow[] => {
  if (typeof value === 'string' && value !== '') {
    try {
      const parsed = JSON.parse(value);
      if (parsed && typeof parsed === 'object' && !Array.isArray(parsed)) {
        const rows = Object.entries(parsed).map(([key, rowValue]) => ({
          key,
          value: caseOutputToString(rowValue),
          originalValue: rowValue,
        }));
        if (rows.length > 0) {
          return rows;
        }
      }
    } catch {
      // Not valid JSON: fall through to the empty-row default.
    }
  }
  return [{ key: '', value: '' }];
};

export const defaultBindingForSlot = (
  adapterId: string,
  slot: AdapterInputSlot,
): SlotBinding => {
  const binding: SlotBinding = { mode: 'field', enabled: slot.required };
  if (isMappingRowsSlot(adapterId, slot.name)) {
    binding.mode = 'literal';
    binding.rows = [{ key: '', value: '' }];
    return binding;
  }
  if (supportsLiteralBinding(slot)) {
    if (slot.schema?.type === 'boolean') {
      binding.mode = 'literal';
      binding.value = false;
    } else if (slot.candidates.length === 0) {
      binding.mode = 'literal';
    }
  }
  return binding;
};

export const createStep = (suggestion: AdapterSuggestion): AdapterStep => ({
  label: suggestion.label,
  adapter: suggestion.adapter,
  bindings: Object.fromEntries(
    suggestion.adapter.inputs.map((slot) => [
      slot.name,
      defaultBindingForSlot(suggestion.adapter.id, slot),
    ]),
  ),
});

// Serializes one configured slot, or null when it is not (completely) bound.
// Optional inputs that are not configured are omitted from the serialized
// source entirely, so the server defaults apply (e.g. combine's separator
// defaults to a single space).
export const serializeSlot = (
  adapter: AdapterDefinition,
  slot: AdapterInputSlot,
  binding: SlotBinding | undefined,
): PropSource | null => {
  if (!binding || !binding.enabled) {
    return null;
  }
  if (binding.mode === 'field') {
    return binding.source ?? null;
  }
  if (slot.static === null || !supportsLiteralBinding(slot)) {
    return null;
  }
  if (binding.rows !== undefined) {
    const populated = binding.rows.filter((row) => row.key !== '');
    if (populated.length === 0) {
      return null;
    }
    return {
      ...slot.static,
      value: serializeMappingRows(binding.rows, getMirroredOutputType(adapter)),
    };
  }
  const { value } = binding;
  if (value === undefined || value === null || value === '') {
    return null;
  }
  return { ...slot.static, value };
};

export const isStepComplete = (
  step: AdapterStep,
  stepIndex: number,
): boolean => {
  const primary = getPrimaryInputName(step.adapter);
  return step.adapter.inputs.every((slot) => {
    if (!slot.required) {
      return true;
    }
    // The primary input of steps after the first is fed by the previous step.
    if (stepIndex > 0 && slot.name === primary) {
      return true;
    }
    return serializeSlot(step.adapter, slot, step.bindings[slot.name]) !== null;
  });
};

export const isChainComplete = (steps: AdapterStep[]): boolean =>
  steps.length > 0 && steps.every((step, index) => isStepComplete(step, index));

// Serializes the step chain into a nested AdaptedPropSource. Step N's primary
// input holds step N-1's serialized source; the last step is the outermost
// object.
export const stepsToSource = (
  steps: AdapterStep[],
): AdaptedPropSource | null => {
  if (steps.length === 0) {
    return null;
  }
  let current: AdaptedPropSource | null = null;
  steps.forEach((step, index) => {
    const primary = getPrimaryInputName(step.adapter);
    const adapterInputs: Record<string, PropSource> = {};
    step.adapter.inputs.forEach((slot) => {
      if (index > 0 && slot.name === primary) {
        if (current) {
          adapterInputs[slot.name] = current;
        }
        return;
      }
      const serialized = serializeSlot(
        step.adapter,
        slot,
        step.bindings[slot.name],
      );
      if (serialized !== null) {
        adapterInputs[slot.name] = serialized;
      }
    });
    current = {
      sourceType: `${ADAPTER_SOURCE_TYPE_PREFIX}${step.adapter.id}`,
      adapterInputs,
    };
  });
  return current;
};

const bindingFromSource = (
  adapterId: string,
  slot: AdapterInputSlot,
  source: PropSource,
): SlotBinding => {
  const sourceJson = JSON.stringify(source);
  const candidate = slot.candidates.find(
    (item) => JSON.stringify(item.source) === sourceJson,
  );
  if (candidate) {
    return {
      mode: 'field',
      enabled: true,
      candidateId: candidate.id,
      source: candidate.source,
    };
  }
  // Only decode a literal when the stored source was written from this
  // slot's own static template: with a template mismatch (e.g. an "any" slot
  // holding a static integer while the template is a string), writing the
  // value back would silently change its type.
  const expression = 'expression' in source ? source.expression : undefined;
  if (
    slot.static !== null &&
    supportsLiteralBinding(slot) &&
    source.sourceType === slot.static.sourceType &&
    expression === slot.static.expression
  ) {
    const binding: SlotBinding = {
      mode: 'literal',
      enabled: true,
      value: (source.value ?? null) as SlotBinding['value'],
    };
    if (isMappingRowsSlot(adapterId, slot.name)) {
      binding.rows = parseMappingRows(binding.value);
    }
    return binding;
  }
  // A bound source that matches no candidate (e.g. the candidate list changed
  // since it was written): preserved verbatim so applying does not lose it.
  return { mode: 'field', enabled: true, source };
};

// Unwraps an adapter source (chain) back into an ordered step list, innermost
// step first. Unwrapping follows each step's primary input while it holds a
// nested adapter source with a matching suggestion; anything else — including
// an adapter without a matching suggestion — is treated as an opaque bound
// value. Returns null when the source is not an adapter source or when the
// outermost adapter has no matching suggestion (it cannot be edited then).
export const sourceToSteps = (
  source: unknown,
  suggestions: AdapterSuggestion[],
): AdapterStep[] | null => {
  if (!isAdaptedSource(source)) {
    return null;
  }

  // Collect chain layers, outermost first.
  const layers: Array<{
    suggestion: AdapterSuggestion;
    source: AdaptedPropSource;
  }> = [];
  let current: unknown = source;
  while (isAdaptedSource(current)) {
    const adapterId = current.sourceType.slice(
      ADAPTER_SOURCE_TYPE_PREFIX.length,
    );
    const suggestion = suggestions.find(
      (item) => item.adapter.id === adapterId,
    );
    if (!suggestion) {
      break;
    }
    layers.push({ suggestion, source: current });
    current = current.adapterInputs?.[getPrimaryInputName(suggestion.adapter)];
  }
  if (layers.length === 0) {
    return null;
  }

  // The outermost layer is the last step.
  layers.reverse();
  return layers.map(({ suggestion, source: layerSource }, index) => {
    const step = createStep(suggestion);
    const primary = getPrimaryInputName(suggestion.adapter);
    suggestion.adapter.inputs.forEach((slot) => {
      // Primary inputs of steps after the first hold the previous step's
      // output and are not user-bound.
      if (index > 0 && slot.name === primary) {
        return;
      }
      const boundSource = layerSource.adapterInputs?.[slot.name];
      if (boundSource !== undefined) {
        step.bindings[slot.name] = bindingFromSource(
          suggestion.adapter.id,
          slot,
          boundSource,
        );
      }
    });
    return step;
  });
};
