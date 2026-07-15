import { useEffect, useRef, useState } from 'react';

import TextField from '@/components/form/components/TextField';
import { getBaseUrl } from '@/utils/drupal-globals';
import { ENTITY_AUTOCOMPLETE_MATCH } from '@/utils/transforms';

import type { ClientWidgetDefinition, ClientWidgetProps } from '../types';

/**
 * Native counterpart of the Drupal `entity_reference_autocomplete` widget.
 *
 * Suggestions come from the canvas autocomplete endpoint, which scopes and
 * entity-access-filters the results server side from the component prop
 * context alone; the client sends no scoping parameters. The codec mirrors
 * the `entityAutocompleteTargetId` transform: the resolved model value is the
 * entity id as a string.
 */

const SUGGESTION_DEBOUNCE_MS = 300;

interface EntityAutocompleteValue {
  id: string | null;
  label: string;
}

interface Suggestion {
  id: string;
  label: string;
}

const asAutocompleteValue = (value: unknown): EntityAutocompleteValue => {
  const record = (value ?? {}) as Partial<EntityAutocompleteValue>;
  return { id: record.id ?? null, label: record.label ?? '' };
};

/**
 * Mirrors the transforms' `passThroughIfAlreadyExtracted` semantics:
 * `Label (id)` yields the trailing id, anything else is treated as a bare id
 * so re-encoding stays idempotent, and blank input means empty.
 *
 * @see \Drupal\Core\Entity\Element\EntityAutocomplete::extractEntityIdFromAutocompleteInput
 */
const extractEntityId = (text: string): string | null => {
  const match = text.trim().match(ENTITY_AUTOCOMPLETE_MATCH);
  if (match !== null) {
    return match[1];
  }
  const trimmed = text.trim();
  return trimmed === '' ? null : trimmed;
};

/**
 * Formats the widget value the way Drupal's autocomplete displays a
 * selection: `Label (id)`.
 */
const toDisplayText = (value: EntityAutocompleteValue): string => {
  if (value.id === null) {
    return value.label;
  }
  return value.label ? `${value.label} (${value.id})` : value.id;
};

const looksLikeId = (value: unknown): value is string | number =>
  (typeof value === 'string' && value !== '') || typeof value === 'number';

const listStyle: React.CSSProperties = {
  position: 'absolute',
  top: '100%',
  left: 0,
  right: 0,
  zIndex: 10,
  margin: '4px 0 0',
  padding: '4px',
  listStyle: 'none',
  maxHeight: '200px',
  overflowY: 'auto',
  backgroundColor: 'var(--color-panel-solid)',
  border: '1px solid var(--slate-5)',
  borderRadius: '4px',
  boxShadow: 'var(--shadow-3)',
};

const optionStyle = (active: boolean): React.CSSProperties => ({
  display: 'block',
  width: '100%',
  padding: '4px 7px',
  textAlign: 'left',
  border: 'none',
  borderRadius: '3px',
  cursor: 'pointer',
  fontFamily: 'inherit',
  fontSize: 'var(--font-size-2)',
  color: 'var(--slate-12)',
  backgroundColor: active ? 'var(--accent-4)' : 'transparent',
});

const EntityReferenceAutocompleteWidget = (props: ClientWidgetProps) => {
  const {
    value,
    onChange,
    disabled,
    required,
    errors,
    inputId,
    inputName,
    propName,
    componentId,
    componentVersion,
  } = props;
  const widgetValue = asAutocompleteValue(value);

  const [suggestions, setSuggestions] = useState<Suggestion[]>([]);
  const [open, setOpen] = useState(false);
  const [activeIndex, setActiveIndex] = useState(-1);
  const debounceTimer = useRef<ReturnType<typeof setTimeout> | null>(null);
  const abortController = useRef<AbortController | null>(null);

  const closeList = () => {
    setOpen(false);
    setActiveIndex(-1);
  };

  useEffect(
    () => () => {
      if (debounceTimer.current) {
        clearTimeout(debounceTimer.current);
      }
      abortController.current?.abort();
    },
    [],
  );

  const fetchSuggestions = async (text: string) => {
    abortController.current?.abort();
    const controller = new AbortController();
    abortController.current = controller;
    const query = new URLSearchParams({
      component: componentId,
      version: componentVersion,
      prop: propName,
      q: text,
    });
    try {
      const response = await fetch(
        `${getBaseUrl()}canvas/api/v0/autocomplete?${query}`,
        { credentials: 'same-origin', signal: controller.signal },
      );
      if (!response.ok) {
        return;
      }
      const data = (await response.json()) as { results?: Suggestion[] };
      // A response that lost the race to an abort must not repopulate the
      // list with stale results.
      if (controller.signal.aborted) {
        return;
      }
      const results = data.results ?? [];
      setSuggestions(results);
      setActiveIndex(-1);
      setOpen(results.length > 0);
    } catch {
      // Aborted or failed requests just leave the list closed; the typed
      // text still commits through the codec.
    }
  };

  const handleInputChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const text = e.target.value;
    // Typed text has no confirmed selection; the codec extracts an id from
    // `Label (id)` input or treats the text as a bare id.
    onChange({ id: null, label: text });
    if (debounceTimer.current) {
      clearTimeout(debounceTimer.current);
    }
    // Drop stale suggestions immediately: abort any in-flight request and
    // clear the open list so Enter or a click cannot select a result for the
    // previous text, and a late response cannot reopen the list, while the
    // new query waits out the debounce.
    abortController.current?.abort();
    setSuggestions([]);
    closeList();
    const trimmed = text.trim();
    if (trimmed === '') {
      return;
    }
    debounceTimer.current = setTimeout(
      () => void fetchSuggestions(trimmed),
      SUGGESTION_DEBOUNCE_MS,
    );
  };

  const selectSuggestion = (suggestion: Suggestion) => {
    onChange({ id: String(suggestion.id), label: suggestion.label });
    closeList();
  };

  const handleKeyDown = (e: React.KeyboardEvent<HTMLInputElement>) => {
    if (e.key === 'Escape') {
      closeList();
      return;
    }
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      if (suggestions.length === 0) {
        return;
      }
      e.preventDefault();
      setOpen(true);
      setActiveIndex((current) => {
        if (current === -1) {
          return e.key === 'ArrowDown' ? 0 : suggestions.length - 1;
        }
        const delta = e.key === 'ArrowDown' ? 1 : -1;
        return (current + delta + suggestions.length) % suggestions.length;
      });
      return;
    }
    if (e.key === 'Enter' && open && activeIndex >= 0) {
      e.preventDefault();
      selectSuggestion(suggestions[activeIndex]);
    }
  };

  const listboxId = `${inputId}--listbox`;
  return (
    <div style={{ position: 'relative' }}>
      <TextField
        attributes={{
          id: inputId,
          name: `${inputName}[0][target_id]`,
          type: 'text',
          role: 'combobox',
          value: toDisplayText(widgetValue),
          required,
          disabled,
          'aria-invalid': errors ? 'true' : undefined,
          'aria-expanded': open ? 'true' : 'false',
          'aria-controls': listboxId,
          'aria-autocomplete': 'list',
          'aria-activedescendant':
            activeIndex >= 0
              ? `${listboxId}--option-${activeIndex}`
              : undefined,
          onChange: handleInputChange,
          onKeyDown: handleKeyDown,
          onBlur: closeList,
        }}
      />
      {open && (
        <ul role="listbox" id={listboxId} style={listStyle}>
          {suggestions.map((suggestion, index) => (
            <li key={`${suggestion.id}-${index}`} role="presentation">
              <button
                type="button"
                role="option"
                id={`${listboxId}--option-${index}`}
                aria-selected={index === activeIndex}
                style={optionStyle(index === activeIndex)}
                // Prevent the input blur so the click handler still runs.
                onMouseDown={(e) => e.preventDefault()}
                onClick={() => selectSuggestion(suggestion)}
                onMouseEnter={() => setActiveIndex(index)}
              >
                {suggestion.label} ({suggestion.id})
              </button>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
};

export const entityReferenceAutocompleteWidget: ClientWidgetDefinition = {
  component: EntityReferenceAutocompleteWidget,
  codec: {
    toModel(widgetValue) {
      const value = asAutocompleteValue(widgetValue);
      if (looksLikeId(value.id)) {
        return { resolved: String(value.id) };
      }
      const id = extractEntityId(value.label);
      return id === null ? null : { resolved: id };
    },
    fromModel(sourceValue, resolvedValue) {
      // Prefer the stored source value: it keeps the target id while
      // `resolved` may hold the evaluated entity object after a server echo.
      const stored = looksLikeId(sourceValue)
        ? sourceValue
        : looksLikeId(resolvedValue)
          ? resolvedValue
          : null;
      // ponytail: label hydration could fetch the entity label from the
      // autocomplete endpoint later; displaying the bare id is acceptable for
      // now.
      return stored === null
        ? { id: null, label: '' }
        : { id: String(stored), label: '' };
    },
  },
};
