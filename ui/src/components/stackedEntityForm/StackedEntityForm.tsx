import React, { useCallback, useEffect, useRef, useState } from 'react';
import { ArrowLeftIcon } from '@radix-ui/react-icons';
import { Box, Button, Flex, Spinner, Text } from '@radix-ui/themes';

import twigToJSXComponentMap from '@/components/form/twig-to-jsx-component-map';
import { useDrupalBehaviors } from '@/hooks/useDrupalBehaviors';
import hyperscriptify from '@/local_packages/hyperscriptify';
import propsify from '@/local_packages/hyperscriptify/propsify/standard/index.js';
import { usePatchEntityFormFieldsMutation } from '@/services/content';
import { useGetStackedEntityFormQuery } from '@/services/pageDataForm';
import parseHyperscriptifyTemplate from '@/utils/parse-hyperscriptify-template';

interface StackedEntityFormProps {
  entityType: string;
  entityId: string;
  label: string;
  onClose: () => void;
}

const AUTO_SAVE_DEBOUNCE_MS = 800;

/**
 * Edits a referenced entity's fields in a panel stacked over the Content tab.
 *
 * The referenced entity's form is fetched from the same generic entity form
 * endpoint the open entity uses, but rendered without the react-hook-form and
 * Redux wiring (the pageData slice belongs to the open entity): the
 * `data-form-id` marker is stripped so the Drupal form components render
 * uncontrolled, and edits are serialized from the DOM and auto-saved through
 * the entity-form-fields endpoint as the referenced entity's own pending
 * change. The stack is capped at one level; complex AJAX-dependent widgets
 * are best edited on the entity itself.
 */
const StackedEntityForm: React.FC<StackedEntityFormProps> = ({
  entityType,
  entityId,
  label,
  onClose,
}) => {
  const {
    currentData: formTemplate,
    error,
    isFetching,
  } = useGetStackedEntityFormQuery(
    { entityType, entityId },
    // Auto-saved values do not round-trip into this cached form HTML, so a
    // cached copy from an earlier stack would resurrect pre-edit values on
    // the next whole-form serialize.
    { refetchOnMountOrArgChange: true },
  );
  const [patchFields, { isLoading: isSaving, isError: isSaveError }] =
    usePatchEntityFormFieldsMutation();
  const [jsxFormContent, setJsxFormContent] =
    useState<React.ReactElement | null>(null);
  const formRef = useRef<HTMLDivElement>(null);
  // The <form> element itself, captured while mounted: the unmount flush runs
  // after React detached the DOM (formRef is already null there), and
  // FormData still works on a detached form.
  const formElRef = useRef<HTMLFormElement | null>(null);
  const debounceRef = useRef<ReturnType<typeof setTimeout> | null>(null);
  useDrupalBehaviors(formRef, jsxFormContent, isFetching);

  useEffect(() => {
    formElRef.current = formRef.current?.querySelector('form') ?? null;
  }, [jsxFormContent]);

  useEffect(() => {
    if (!formTemplate) {
      return;
    }
    const template = parseHyperscriptifyTemplate(formTemplate as string);
    if (!template) {
      return;
    }
    // Rebrand the form so its fields route to StackedEntityFormField: they
    // stay interactive through react-hook-form but never touch the open
    // entity's pageData Redux state.
    const formElement = template.querySelector('drupal-canvas-form');
    if (formElement) {
      try {
        const attributes = JSON.parse(
          formElement.getAttribute('attributes') || '{}',
        );
        attributes['data-form-id'] = 'stacked_entity_form';
        formElement.setAttribute('attributes', JSON.stringify(attributes));
      } catch {
        // Leave the template untouched; worst case the form stays read-only.
      }
    }
    setJsxFormContent(
      <div data-testid="canvas-stacked-entity-form-content">
        {hyperscriptify(
          template,
          React.createElement,
          React.Fragment,
          twigToJSXComponentMap,
          { propsify },
        )}
      </div>,
    );
  }, [formTemplate]);

  const save = useCallback(() => {
    const formEl = formRef.current?.querySelector('form') ?? formElRef.current;
    if (!formEl) {
      return;
    }
    // CKEditor 5 syncs its source textarea on its own debounce; force the
    // sync so the serialize below cannot read a stale value.
    formEl
      .querySelectorAll<
        HTMLElement & { ckeditorInstance?: { updateSourceElement: () => void } }
      >('.ck-editor__editable')
      .forEach((editable) => {
        try {
          editable.ckeditorInstance?.updateSourceElement();
        } catch {
          // A partially initialized editor keeps its current textarea value.
        }
      });
    const values: Record<string, string> = {};
    const multiValueIndex: Record<string, number> = {};
    new FormData(formEl).forEach((value, key) => {
      // File uploads cannot round-trip through the JSON auto-save payload.
      if (typeof value !== 'string') {
        return;
      }
      if (key.endsWith('[]')) {
        const base = key.slice(0, -2);
        multiValueIndex[base] = (multiValueIndex[base] ?? -1) + 1;
        values[`${base}[${multiValueIndex[base]}]`] = value;
      } else {
        values[key] = value;
      }
    });
    // The server regenerates the token for the current user.
    delete values.form_token;
    patchFields({ entityType, entityId, entityFormFields: values });
  }, [entityType, entityId, patchFields]);

  const scheduleSave = useCallback(() => {
    if (debounceRef.current) {
      clearTimeout(debounceRef.current);
    }
    debounceRef.current = setTimeout(save, AUTO_SAVE_DEBOUNCE_MS);
  }, [save]);

  // Flush a pending save when the panel closes or unmounts.
  useEffect(
    () => () => {
      if (debounceRef.current) {
        clearTimeout(debounceRef.current);
        save();
      }
    },
    [save],
  );

  return (
    <Box data-testid="canvas-stacked-entity-form" my="2">
      <Flex direction="column" gap="2" align="start">
        <Button
          size="1"
          variant="ghost"
          color="gray"
          onClick={onClose}
          data-testid="canvas-stacked-entity-form-back"
        >
          <ArrowLeftIcon />
          Back
        </Button>
        <Text size="2" weight="bold">
          {label}
        </Text>
        <Text size="1" color="gray">
          {isSaveError
            ? 'Saving failed. Edits may not be captured.'
            : isSaving
              ? 'Saving…'
              : 'Edits are saved automatically as pending changes.'}
        </Text>
      </Flex>
      {isFetching && (
        <Spinner size="3" loading={true}>
          <Box mt="9" />
        </Spinner>
      )}
      {!isFetching && !!error && (
        <Text size="1" color="red">
          The form could not be loaded.
        </Text>
      )}
      {!isFetching && !error && (
        <div ref={formRef} onInput={scheduleSave} onChange={scheduleSave}>
          {jsxFormContent}
        </div>
      )}
    </Box>
  );
};

export default StackedEntityForm;
