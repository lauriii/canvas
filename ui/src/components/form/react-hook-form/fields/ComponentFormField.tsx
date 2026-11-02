import React, { useMemo } from 'react';
import { Controller } from 'react-hook-form';

import { useAppSelector } from '@/app/hooks';
import { FieldContextProvider } from '@/components/form/contexts/FieldContext';
import {
  useSafeFormContext,
  useSafeRHFContext,
} from '@/components/form/contexts/FormContext';
import { useComponentFormInputInfo } from '@/components/form/react-hook-form/hooks/useComponentFormInputInfo';
import { useComponentInitialValue } from '@/components/form/react-hook-form/hooks/useComponentInitialValue';
import { useDebouncedFormState } from '@/components/form/react-hook-form/hooks/useDebouncedFormState';
import { useUndoRedoSync } from '@/components/form/react-hook-form/hooks/useUndoRedoSync';
import {
  applyEnhancedProps,
  comparePropsWithAttributes,
  getCurrentValueFromProps,
} from '@/components/form/react-hook-form/utils';
import { FORM_TYPES } from '@/features/form/constants';
import {
  selectFieldError,
  selectFormErrors,
  selectFormValues,
} from '@/features/form/formStateSlice';

import {
  createJsonSchemaValidator,
  resolveOptionsOverrides,
} from './componentFieldValidation';
import { propInputData } from './componentFormData';
import { createComponentFormStateHandler } from './componentFormHandlers';
import { FieldErrorDisplay } from './FieldErrorDisplay';
import { useComponentFieldHandlers } from './useComponentFieldHandlers';

import type { ComponentType } from 'react';
import type { PropsValues } from '@drupal-canvas/types';
import type { FormId } from '@/features/form/formStateSlice';

interface ComponentFormFieldProps<P> {
  props: P;
  WrappedComponent: ComponentType<P>;
  fieldName: string;
}

/**
 * Wires a single Drupal form element into the component instance form.
 */
export const ComponentFormField = <P extends Record<string, any>>({
  props,
  WrappedComponent,
  fieldName,
}: ComponentFormFieldProps<P>) => {
  // Information about the form overall, such as form id.
  const formContext = useSafeFormContext();
  // Provides access to RHF internals.
  const rhfContext = useSafeRHFContext();

  const componentFormInputInfo = useComponentFormInputInfo(fieldName);
  const {
    dispatch,
    components,
    transforms,
    inputAndUiData,
    selectedComponentType,
    selectedComponent,
    propName,
    isScalarProp,
  } = componentFormInputInfo;

  const formState = useAppSelector((state) =>
    selectFormValues(state, FORM_TYPES.COMPONENT_INSTANCE_FORM),
  );
  const formErrors = useAppSelector((state) =>
    selectFormErrors(state, FORM_TYPES.COMPONENT_INSTANCE_FORM),
  );

  // Memo attributes to prevent re-renders when only the object
  // reference changes but the values are identical.
  const MemoizedWrappedComponent = useMemo(
    () => React.memo(WrappedComponent, comparePropsWithAttributes),
    [WrappedComponent],
  ) as unknown as React.ComponentType<P>;

  // Store update handler.
  const updateLayoutModelStore = (() => {
    const formStateToLayoutModelStore = createComponentFormStateHandler(
      componentFormInputInfo,
    );
    const debounceFormStateToLayoutModelStore = useDebouncedFormState(
      formStateToLayoutModelStore,
    );
    // A change to one field updates all props from the whole form state. Leave
    // out the other fields whose current value was rejected, so their last
    // valid value stays in use instead of being sent anyway.
    const withoutRejectedValues = (values: PropsValues): PropsValues =>
      Object.fromEntries(
        Object.entries(values).filter(
          ([name]) => name === fieldName || !(name in formErrors),
        ),
      );
    return (changedFormState: PropsValues) => {
      const newFormState = withoutRejectedValues(changedFormState);
      const validFormState = withoutRejectedValues(formState);
      const elementType =
        props.attributes?.type || props.attributes?.['data-canvas-type'];
      // Debounce isn't needed for elements that only have an on/off state.
      const shouldDebounce =
        !isScalarProp ||
        components?.[selectedComponentType]?.source !== 'Code component' ||
        !['checkbox', 'radio'].includes(elementType as string);
      if (shouldDebounce) {
        debounceFormStateToLayoutModelStore(
          newFormState,
          inputAndUiData,
          validFormState,
        );
      } else {
        formStateToLayoutModelStore(
          newFormState,
          inputAndUiData,
          validFormState,
        );
      }
    };
  })();

  // Adjusts select options for _none handling and identifies multi-input props.
  const propsOverrides = resolveOptionsOverrides(
    props,
    inputAndUiData,
    selectedComponent,
    propName,
  );
  const { multipleInputsSingleValue } = propInputData(
    formState,
    inputAndUiData,
  );

  const { stableOnChange, stableOnBlur, fieldContextValue, latestPropsRef } =
    useComponentFieldHandlers({
      fieldName,
      props,
      formState,
      dispatch,
      formContext,
      inputAndUiData,
      propName,
      selectedComponent,
      transforms,
      multipleInputsSingleValue,
      updateLayoutModelStore,
    });

  useUndoRedoSync(
    fieldName,
    () => getCurrentValueFromProps(props),
    rhfContext,
    'layoutModel',
  );
  useComponentInitialValue(
    dispatch,
    fieldName,
    formContext?.formId ?? null,
    () => getCurrentValueFromProps(props),
    propName,
  );

  if (!rhfContext) {
    return <MemoizedWrappedComponent {...props} />;
  }

  const jsonSchemaValidator = createJsonSchemaValidator({
    fieldName,
    selectedComponent,
    inputAndUiData,
    required: !!props.attributes?.required,
  });

  return (
    <Controller
      name={fieldName}
      control={rhfContext.control}
      defaultValue={getCurrentValueFromProps(props)}
      rules={{ validate: { jsonSchemaValidation: jsonSchemaValidator } }}
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
              { ...props, ...propsOverrides },
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
