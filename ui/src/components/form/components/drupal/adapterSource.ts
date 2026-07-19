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

// One part of the combine pill editor's ordered content: a literal text run,
// an inline field reference (pill), or — in a later chain step — the movable
// pill holding the previous step's output.
export type CombinePart =
  | { kind: 'text'; text: string }
  | { kind: 'previous' }
  | {
      kind: 'field';
      // Display label for the pill (short field name).
      label: string;
      // The prop source written verbatim for this field.
      source: PropSource;
      // The matching candidate id, when the source corresponds to one of the
      // slot's candidates.
      candidateId?: string;
    };

// One step of the transform chain as edited in the panel.
export interface AdapterStep {
  label: string;
  adapter: AdapterDefinition;
  bindings: Record<string, SlotBinding>;
  // Only the combine adapter uses this: the ordered text/field parts edited in
  // the pill editor. When present it is the source of truth for the step's
  // serialization (its `bindings` are unused).
  parts?: CombinePart[];
}

const ADAPTER_SOURCE_TYPE_PREFIX = 'adapter:';

// combine exposes text_1…text_10, so a combined value can hold at most 10
// parts.
export const COMBINE_MAX_PARTS = 10;

const COMBINE_TEXT_SLOT_PATTERN = /^text_\d+$/;

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

// The backend suggester joins the segments of a candidate's hierarchical
// label with this exact separator (space, arrow, space), e.g.
// "Image → Alternative text".
// @see \Drupal\canvas\ShapeMatcher\PropSourceSuggester::enrichSuggestion()
export const CANDIDATE_LABEL_SEPARATOR = ' → ';

// One node of the field-candidate menu tree. A node can be a selectable leaf
// (has `candidate`), a submenu (has `children`), or both (a field that is
// itself selectable and also has nested fields beneath it).
export interface CandidateTreeNode {
  label: string;
  candidate?: SlotCandidate;
  children?: CandidateTreeNode[];
}

// Builds a nested menu tree from flat candidates by splitting each candidate's
// label on the path separator. Candidates that share a path prefix merge into
// the same submenus rather than repeating the prefix.
export const buildCandidateTree = (
  candidates: SlotCandidate[],
): CandidateTreeNode[] => {
  const roots: CandidateTreeNode[] = [];
  candidates.forEach((candidate) => {
    const segments = candidate.label.split(CANDIDATE_LABEL_SEPARATOR);
    let level = roots;
    segments.forEach((segment, index) => {
      let node = level.find((existing) => existing.label === segment);
      if (!node) {
        node = { label: segment };
        level.push(node);
      }
      if (index === segments.length - 1) {
        node.candidate = candidate;
      } else {
        if (!node.children) {
          node.children = [];
        }
        level = node.children;
      }
    });
  });
  return roots;
};

// The last path segment of a candidate's label, used as the compact trigger
// text while the full path is shown as the trigger's tooltip.
export const candidateShortLabel = (label: string): string => {
  const segments = label.split(CANDIDATE_LABEL_SEPARATOR);
  return segments[segments.length - 1];
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

export const createStep = (suggestion: AdapterSuggestion): AdapterStep => {
  const step: AdapterStep = {
    label: suggestion.label,
    adapter: suggestion.adapter,
    bindings: Object.fromEntries(
      suggestion.adapter.inputs.map((slot) => [
        slot.name,
        defaultBindingForSlot(suggestion.adapter.id, slot),
      ]),
    ),
  };
  // The combine adapter is edited as a pill editor rather than per-slot rows;
  // it starts with a single empty text run to type into.
  if (suggestion.adapter.id === 'combine') {
    step.parts = [{ kind: 'text', text: '' }];
  }
  return step;
};

// Restores the combine `previous`-pill invariants after a structural chain
// change (step added, removed, or reordered): a combine step that became the
// first step loses its `previous` pill, and one that became a later step
// gains a leading one. Part edits within a step are NOT normalized — the
// author may remove the pill to reposition it, and Apply stays disabled
// until it is re-inserted (see isStepComplete).
export const normalizeChainSteps = (steps: AdapterStep[]): AdapterStep[] =>
  steps.map((step, index) => {
    if (step.adapter.id !== 'combine') {
      return step;
    }
    const parts = step.parts ?? [{ kind: 'text', text: '' }];
    const hasPrevious = parts.some((part) => part.kind === 'previous');
    if (index === 0 && hasPrevious) {
      const stripped = parts.filter((part) => part.kind !== 'previous');
      return {
        ...step,
        parts: stripped.length > 0 ? stripped : [{ kind: 'text', text: '' }],
      };
    }
    if (index > 0 && !hasPrevious) {
      return { ...step, parts: [{ kind: 'previous' }, ...parts] };
    }
    return step;
  });

// The static template shared by combine's text_1…text_10 inputs, used to write
// literal text runs.
const combineTextStatic = (
  slots: AdapterInputSlot[],
): StaticSlotTemplate | null =>
  slots.find((slot) => COMBINE_TEXT_SLOT_PATTERN.test(slot.name))?.static ??
  null;

// Maps the ordered pill-editor parts to combine's text_1…text_10 inputs plus
// an empty separator (so the parts concatenate directly). Empty text runs are
// skipped, and no more than the 10 available slots are emitted. When
// `previousSource` is provided (combine used as a later step in a chain), it
// is written at the position of the `previous` part — the author decides
// where the previous step's output lands. A missing `previous` part falls
// back to the leading position so the chain link is never dropped.
export const combinePartsToInputs = (
  parts: CombinePart[],
  slots: AdapterInputSlot[],
  previousSource?: PropSource,
): Record<string, PropSource> => {
  const textStatic = combineTextStatic(slots);
  const ordered: PropSource[] = [];
  if (previousSource && !parts.some((part) => part.kind === 'previous')) {
    ordered.push(previousSource);
  }
  parts.forEach((part) => {
    if (part.kind === 'previous') {
      if (previousSource) {
        ordered.push(previousSource);
      }
      return;
    }
    if (part.kind === 'text') {
      if (part.text === '' || textStatic === null) {
        return;
      }
      ordered.push({ ...textStatic, value: part.text });
    } else {
      ordered.push(part.source);
    }
  });
  const inputs: Record<string, PropSource> = {};
  ordered.slice(0, COMBINE_MAX_PARTS).forEach((source, index) => {
    inputs[`text_${index + 1}`] = source;
  });
  // An explicit empty separator so the parts concatenate directly (the server
  // default would otherwise insert a single space).
  const separatorStatic = slots.find(
    (slot) => slot.name === 'separator',
  )?.static;
  if (separatorStatic) {
    inputs.separator = { ...separatorStatic, value: '' };
  }
  return inputs;
};

// The union of field candidates across all text slots, deduplicated by id.
// text_1 is restricted to required fields when the targeted prop is required,
// while the later slots offer the full set (including followed entity
// references), so the pill editor must offer, and resolve labels against,
// all of them.
export const combineTextCandidates = (
  slots: AdapterInputSlot[],
): SlotCandidate[] => {
  const byId = new Map<string, SlotCandidate>();
  slots
    .filter((slot) => COMBINE_TEXT_SLOT_PATTERN.test(slot.name))
    .flatMap((slot) => slot.candidates)
    .forEach((candidate) => {
      if (!byId.has(candidate.id)) {
        byId.set(candidate.id, candidate);
      }
    });
  return [...byId.values()];
};

// The name of the text input holding the previous step's output in a stored
// combine source: the first one holding a nested adapter source. NULL when
// none does (combine as the first step of a chain).
export const combineChainInputName = (
  source: AdaptedPropSource,
): string | null => {
  for (let index = 1; index <= COMBINE_MAX_PARTS; index++) {
    if (isAdaptedSource(source.adapterInputs?.[`text_${index}`])) {
      return `text_${index}`;
    }
  }
  return null;
};

// Reconstructs the ordered pill-editor parts from a stored combine source:
// text_1…text_10 read in order, a static input becomes a literal text run and
// any other source becomes a field pill (its label resolved from the text
// slots' combined candidates, falling back to a generic label while
// preserving the source). When `chainInputName` is given (combine used as a
// later chain step), that input holds the previous step's output and becomes
// the movable `previous` part.
export const combineSourceToParts = (
  source: AdaptedPropSource,
  slots: AdapterInputSlot[],
  chainInputName: string | null = null,
): CombinePart[] => {
  const candidates = combineTextCandidates(slots);
  const parts: CombinePart[] = [];
  for (let index = 1; index <= COMBINE_MAX_PARTS; index++) {
    const inputName = `text_${index}`;
    const input = source.adapterInputs?.[inputName];
    if (input === undefined) {
      continue;
    }
    if (inputName === chainInputName) {
      parts.push({ kind: 'previous' });
      continue;
    }
    const sourceType =
      typeof input.sourceType === 'string' ? input.sourceType : '';
    if (sourceType.startsWith('static:')) {
      parts.push({ kind: 'text', text: String(input.value ?? '') });
    } else {
      const inputJson = JSON.stringify(input);
      const candidate = candidates.find(
        (item) => JSON.stringify(item.source) === inputJson,
      );
      parts.push({
        kind: 'field',
        label: candidate ? candidateShortLabel(candidate.label) : 'Field',
        source: input,
        candidateId: candidate?.id,
      });
    }
  }
  return parts;
};

// The curated set of adapters offered inline as transform-enabled field
// suggestions (each bridges an otherwise-incompatible field to the prop:
// `fallback` lets an optional field satisfy a required prop, `format_date`
// lets a datetime field satisfy a text prop). Kept small on purpose so the
// linker dropdown is not flooded with every adapter.
export const BRIDGING_ADAPTER_IDS = ['fallback', 'format_date'];

// Builds a single-step chain for an adapter with its primary input (the first
// required input) pre-bound to the chosen field candidate, all other inputs
// at their defaults. Used by the inline bridging field suggestions: the user
// picks a field, and the panel opens to collect only the adapter's remaining
// required inputs (e.g. fallback's `default`, format_date's `format`).
export const createStepWithPrimaryField = (
  suggestion: AdapterSuggestion,
  candidate: SlotCandidate,
): AdapterStep => {
  const step = createStep(suggestion);
  const primary = getPrimaryInputName(suggestion.adapter);
  if (primary) {
    step.bindings[primary] = {
      mode: 'field',
      enabled: true,
      candidateId: candidate.id,
      source: candidate.source,
    };
  }
  return step;
};

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

// Whether a combine step has content: at least one non-empty part so text_1
// resolves to something non-empty (a later chain step already has text_1 fed
// by the previous step).
export const combineHasContent = (parts: CombinePart[]): boolean =>
  parts.some((part) => (part.kind === 'text' ? part.text !== '' : true));

export const isStepComplete = (
  step: AdapterStep,
  stepIndex: number,
): boolean => {
  if (step.adapter.id === 'combine') {
    // A later chain step must place the previous step's output somewhere
    // (the pill can be moved, but removing it breaks the chain); the first
    // step needs at least one non-empty part.
    return stepIndex > 0
      ? (step.parts ?? []).some((part) => part.kind === 'previous')
      : combineHasContent(step.parts ?? []);
  }
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
    let adapterInputs: Record<string, PropSource>;
    if (step.adapter.id === 'combine') {
      // Combine is serialized from its pill-editor parts. As a later chain
      // step, the previous step's output lands at the `previous` part's
      // position.
      const previousSource = index > 0 ? (current ?? undefined) : undefined;
      adapterInputs = combinePartsToInputs(
        step.parts ?? [],
        step.adapter.inputs,
        previousSource,
      );
    } else {
      adapterInputs = {};
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
    }
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
    // Combine's chain link is the (movable) text input holding a nested
    // adapter source; every other adapter chains through its primary input.
    current =
      suggestion.adapter.id === 'combine'
        ? current.adapterInputs?.[combineChainInputName(current) ?? '']
        : current.adapterInputs?.[getPrimaryInputName(suggestion.adapter)];
  }
  if (layers.length === 0) {
    return null;
  }

  // The outermost layer is the last step.
  layers.reverse();
  return layers.map(({ suggestion, source: layerSource }, index) => {
    const step = createStep(suggestion);
    // Combine reconstructs its pill-editor parts rather than per-slot
    // bindings. As a later chain step, the input holding the previous step's
    // output becomes the movable `previous` part.
    if (suggestion.adapter.id === 'combine') {
      step.parts = combineSourceToParts(
        layerSource,
        suggestion.adapter.inputs,
        index > 0 ? combineChainInputName(layerSource) : null,
      );
      return step;
    }
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
