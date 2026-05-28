import React, { useMemo } from 'react';
import { Controller } from 'react-hook-form';

import { useAppSelector } from '@/app/hooks';
import { FieldContextProvider } from '@/components/form/contexts/FieldContext';
import {
  useSafeFormContext,
  useSafeRHFContext,
} from '@/components/form/contexts/FormContext';
import { useDebouncedFormState } from '@/components/form/react-hook-form/hooks/useDebouncedFormState';
import { usePageDataFormInputInfo } from '@/components/form/react-hook-form/hooks/usePageDataFormInputInfo';
import { usePageDataInitialValue } from '@/components/form/react-hook-form/hooks/usePageDataInitialValue';
import { useUndoRedoSync } from '@/components/form/react-hook-form/hooks/useUndoRedoSync';
import {
  applyEnhancedProps,
  comparePropsWithAttributes,
  getCurrentValueFromProps,
} from '@/components/form/react-hook-form/utils';
import { selectFieldError } from '@/features/form/formStateSlice';

import { FieldErrorDisplay } from './FieldErrorDisplay';
import { createHtml5Validator } from './pageDataFieldValidation';
import {
  createPageDataFormStateHandler,
  useRespondToPageDataStoreUpdates,
} from './pageDataFormHandlers';
import { usePageDataFieldHandlers } from './usePageDataFieldHandlers';

import type { ComponentType } from 'react';
import type { PropsValues } from '@drupal-canvas/types';
import type { FormId } from '@/features/form/formStateSlice';

interface PageDataFormFieldProps<P> {
  props: P;
  WrappedComponent: ComponentType<P>;
  fieldName: string;
}

/**
 * Wires a single Drupal form element into the page data (entity) form.
 */
export const PageDataFormField = <P extends Record<string, any>>({
  props,
  WrappedComponent,
  fieldName,
}: PageDataFormFieldProps<P>) => {
  const { dispatch, pageData, formState } = usePageDataFormInputInfo();

  // Information about the form overall, such as form id.
  const formContext = useSafeFormContext();
  // Provides access to RHF internals.
  const rhfContext = useSafeRHFContext();

  // Memo attributes to prevent re-renders when only the object
  // reference changes but the values are identical.
  const MemoizedWrappedComponent = useMemo(
    () => React.memo(WrappedComponent, comparePropsWithAttributes),
    [WrappedComponent],
  ) as unknown as React.ComponentType<P>;

  const getCurrentValueFromRedux = () =>
    pageData && fieldName in pageData
      ? pageData[fieldName]
      : getCurrentValueFromProps(props);

  const updatePageDataStore = (() => {
    const formStateToPageDataStore = createPageDataFormStateHandler(dispatch);
    const debounceFormStateToPageDataStore = useDebouncedFormState(
      formStateToPageDataStore,
    );
    return (newFormState: PropsValues) => {
      const elementType =
        props.attributes?.type || props.attributes?.['data-canvas-type'];
      if (['checkbox', 'radio'].includes(elementType as string)) {
        formStateToPageDataStore(newFormState);
      } else {
        debounceFormStateToPageDataStore(newFormState);
      }
    };
  })();

  // Create event handlers.
  const { stableOnChange, stableOnBlur, fieldContextValue, latestPropsRef } =
    usePageDataFieldHandlers({
      fieldName,
      props,
      formState,
      dispatch,
      formContext,
      updatePageDataStore,
    });

  // Integrate with undo/redo system.
  useUndoRedoSync(fieldName, getCurrentValueFromRedux, rhfContext, 'pageData');

  // Set the field value on load.
  usePageDataInitialValue(
    dispatch,
    fieldName,
    formContext,
    getCurrentValueFromRedux,
    WrappedComponent,
  );

  // Special handling to update the form values if the store is updated
  // programmatically.
  useRespondToPageDataStoreUpdates(fieldName, pageData, rhfContext, dispatch);

  if (!rhfContext) {
    return <MemoizedWrappedComponent {...(props as any)} />;
  }

  const html5Validator = createHtml5Validator(props.attributes || {});

  return (
    <Controller
      name={fieldName}
      control={rhfContext.control}
      defaultValue={getCurrentValueFromRedux()}
      rules={{ validate: { html5Validation: html5Validator } }}
      render={({ field, fieldState }) => {
        // Expose the current RHF field object to the stable callbacks via ref.
        latestPropsRef.current.field = field;

        // Redux blocking errors take priority over RHF display errors.
        const blockingError = useAppSelector((state) =>
          selectFieldError(state, {
            formId: formContext?.formId as FormId,
            fieldName,
          }),
        );
        const displayError = blockingError || fieldState.error;

        const enhancedProps = useMemo(
          () =>
            applyEnhancedProps(
              props,
              field,
              stableOnChange,
              stableOnBlur,
              !!displayError,
            ),
          // eslint-disable-next-line react-hooks/exhaustive-deps
          [field, field.value, stableOnChange, stableOnBlur, !!displayError],
        );

        return (
          <FieldContextProvider value={fieldContextValue}>
            <MemoizedWrappedComponent {...(enhancedProps as any)} />
            <FieldErrorDisplay
              blockingError={blockingError}
              fieldState={fieldState}
            />
          </FieldContextProvider>
        );
      }}
    />
  );
};
