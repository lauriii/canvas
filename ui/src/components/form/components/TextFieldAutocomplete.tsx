// cspell:ignore autocompleteclose
import { useRef } from 'react';
import clsx from 'clsx';

import { useAppStore } from '@/app/hooks';
import { useFieldContext } from '@/components/form/contexts/FieldContext';
import { FORM_TYPES } from '@/features/form/constants';
import { selectLayout } from '@/features/layout/layoutModelSlice';
import {
  findComponentByUuid,
  getDisplayNameForNode,
} from '@/features/layout/layoutUtils';
import useMutationObserver from '@/hooks/useMutationObserver';
import { a2p } from '@/local_packages/utils';
import { useGetComponentsQuery } from '@/services/componentAndLayout';
import {
  collectPageAnchors,
  filterPageAnchors,
  getPreviewDocument,
  toAutocompleteItems,
} from '@/utils/pageAnchors';
import { resolveEntityUri } from '@/utils/transforms';

import type { Attributes } from '@/types/DrupalAttribute';

import styles from './TextField.module.css';

const { jQuery } = window;

const TextFieldAutocomplete = ({
  className = '',
  attributes = {},
}: {
  className?: string;
  attributes?: Attributes;
}) => {
  const formId = attributes['data-form-id'];
  // This attribute prevents the input from updating the store on change.
  // Without this, autocomplete search results will disappear moments after
  // they appear due to the component rerendering on value change.
  // The attribute is removed when a suggestion is picked, or the input is
  // blurred.
  attributes['data-canvas-no-update'] = '';

  const inputRef = useRef<HTMLInputElement>(null);
  // Whether the input holds typed text that is not stored yet.
  const typedRef = useRef(false);
  // Whether the input is listing anchors because it got focus.
  const openingOnFocusRef = useRef(false);
  const fieldContext = useFieldContext();
  const store = useAppStore();
  const { data: components } = useGetComponentsQuery();
  // The autocomplete options are patched once, so read the latest list.
  const componentsRef = useRef(components);
  componentsRef.current = components;
  // Link inputs that accept internal links (`format: uri-reference`) also
  // accept `#anchor` fragments. Inputs that only accept external URIs
  // (`format: uri`) render as `type="url"` without autocomplete.
  // @see \Drupal\canvas\JsonSchemaInterpreter\JsonSchemaStringFormat
  const suggestsAnchors =
    formId === FORM_TYPES.COMPONENT_INSTANCE_FORM &&
    attributes['data-canvas-resolve-entity-uri'] === 'true';

  const getAnchors = (term: string) =>
    filterPageAnchors(
      collectPageAnchors(getPreviewDocument(), (uuid) =>
        getDisplayNameForNode(
          findComponentByUuid(selectLayout(store.getState()), uuid),
          null,
          componentsRef.current,
        ),
      ),
      term,
    );

  // Stores the typed text. A lone `#` is the start of an anchor, and not a
  // valid link yet.
  const commitTyped = () => {
    const input = inputRef.current;
    if (!input || !typedRef.current || input.value === '#') {
      return;
    }
    typedRef.current = false;
    input.removeAttribute('data-canvas-no-update');
    // The `link` transform resolves typed entity labels, such as
    // `Example (3)`, to URIs.
    // @see ui/src/utils/transforms.ts
    fieldContext?.triggerChange(input.value, input);
  };

  // Offers anchors on the current page when the input is empty or starts
  // with `#`, and defers to core's entity autocomplete otherwise.
  const addAnchorSuggestions = (input: HTMLInputElement) => {
    // While anchors are listed, typed text is not stored: store it when the
    // list closes without a pick, such as on blur or Escape. Bound to the
    // element, so it outlives the autocomplete instance.
    jQuery(input)
      .off('autocompleteclose.canvasAnchors')
      .on('autocompleteclose.canvasAnchors', () => {
        // A pick is stored by the mutation observer below.
        if (!input.hasAttribute('data-canvas-autocomplete-selected')) {
          commitTyped();
        }
      });
    // Drupal behaviors replace the autocomplete instance when the form is
    // rebuilt, such as after a stored change, so check the instance.
    const instance = jQuery.data(input, 'ui-autocomplete');
    if (!instance || instance.canvasAnchorSuggestions) {
      return;
    }
    instance.canvasAnchorSuggestions = true;
    const { source, search } = instance.options;
    instance.option({
      // Let an empty input open the suggestions.
      minLength: 0,
      search(event: Event, ui: unknown) {
        const { value } = input;
        // An empty input lists anchors on focus only: once the author deleted
        // the text, the empty value must be stored.
        if (
          (value !== '' || openingOnFocusRef.current) &&
          getAnchors(value).length > 0
        ) {
          return true;
        }
        // Core rejects empty input and input that starts with `/`, `#` or `?`,
        // but leaves an open list open, which would block storing the value.
        const searching = value !== '' && search.call(this, event, ui);
        if (!searching) {
          instance.close();
        }
        return searching;
      },
      source(
        request: { term: string },
        response: (items: Array<{ value: string; label: string }>) => void,
      ) {
        const anchors = getAnchors(request.term);
        if (anchors.length === 0) {
          return source.call(this, request, response);
        }
        response(toAutocompleteItems(anchors));
      },
    });
  };

  // This mutation observer responds to the addition of a
  // 'data-canvas-autocomplete-selected' attribute, which will have the value of the
  // chosen autocomplete suggestion.
  useMutationObserver(
    inputRef,
    (mutations) => {
      mutations.forEach((record: MutationRecord) => {
        if (record?.attributeName === 'data-canvas-autocomplete-selected') {
          if (
            record.target instanceof HTMLInputElement &&
            record.target.getAttribute('data-canvas-autocomplete-selected')
          ) {
            const selection = record.target.getAttribute(
              'data-canvas-autocomplete-selected',
            );
            if (selection) {
              typedRef.current = false;
              // @see \Drupal\canvas\Hook\ReduxIntegratedFieldWidgetsHooks::fieldWidgetSingleElementLinkDefaultWidgetFormAlter()
              fieldContext?.triggerChange(selection);

              if (record.target.dataset.canvasResolveEntityUri === 'true') {
                // Use setAttribute instead of .value. This means the input will
                // still *display* the suggestion label, but the stored value
                // will be the schema-required URI.
                record.target.setAttribute(
                  'value',
                  resolveEntityUri(selection),
                );
              }
            }

            // Remove the attribute to prevent multiple attempts to update the
            // store with the same value.
            record.target.removeAttribute('data-canvas-autocomplete-selected');
          }
        }
      });
    },
    { attributes: true },
  );

  return (
    <div className={clsx(styles.wrap, styles.autocompleteWrap)}>
      <input
        autoComplete="off"
        defaultValue={attributes.value}
        {...a2p(
          attributes,
          {},
          { skipAttributes: ['onBlur', 'onChange', 'value'] },
        )}
        className={clsx(styles.root, styles.autocomplete, className)}
        onFocus={(event) => {
          const input = event.currentTarget;
          if (!suggestsAnchors) {
            return;
          }
          addAnchorSuggestions(input);
          if (input.value === '' && jQuery.data(input, 'ui-autocomplete')) {
            openingOnFocusRef.current = true;
            jQuery(input).autocomplete('search', '');
            openingOnFocusRef.current = false;
          }
        }}
        ref={(node) => {
          if (node) {
            // @ts-ignore
            inputRef.current = node;
          }
        }}
        onChange={() => {
          typedRef.current = true;
          // Default to setting the attribute that prevents preview and store
          // updates.
          if (inputRef.current) {
            inputRef.current.setAttribute('data-canvas-no-update', 'true');
            if (suggestsAnchors) {
              addAnchorSuggestions(inputRef.current);
            }
          }

          const autocompleteDelay =
            inputRef.current &&
            !!jQuery.data(inputRef.current, 'ui-autocomplete')
              ? jQuery(inputRef.current).autocomplete('option', 'delay')
              : 400;

          // Include a delayed change event that will fire after the event
          // listeners in autocomplete.extend.js have had a chance to
          // determine if suggestions are available and prevent store/preview
          // updates or if they aren't it updates the store/preview with what
          // has been typed.
          setTimeout(() => {
            const suggestionsOpen =
              inputRef.current &&
              jQuery(inputRef.current).data('ui-autocomplete') &&
              jQuery(inputRef.current).autocomplete('widget').is(':visible');
            if (!suggestionsOpen) {
              commitTyped();
            }
          }, autocompleteDelay * 2);
        }}
      />
    </div>
  );
};

export default TextFieldAutocomplete;
