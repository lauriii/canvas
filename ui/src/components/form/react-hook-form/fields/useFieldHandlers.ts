import { useCallback, useMemo, useRef } from 'react';

import {
  createSyntheticBlurEvent,
  createSyntheticChangeEvent,
} from '@/components/form/contexts/FieldContext';
import {
  createEnhancedOnBlur,
  createEnhancedOnChange,
} from '@/components/form/react-hook-form/utils';
import { clearFieldError } from '@/features/form/formStateSlice';

import type { MutableRefObject } from 'react';
import type * as ReactType from 'react';
import type { PropsValues } from '@drupal-canvas/types';
import type { useAppDispatch } from '@/app/hooks';
import type { FieldContextValue } from '@/components/form/contexts/FieldContext';
import type { ValidationResult } from '@/components/form/react-hook-form/utils';
import type { FormId } from '@/features/form/formStateSlice';

/**
 * The base set of fields always present in the mutable ref.
 */
interface BaseLatestPropsRef {
  props: Record<string, any>;
  formState: PropsValues;
  dispatch: ReturnType<typeof useAppDispatch>;
  formContext: { formId: string } | null;
  updateStore: (newFormState: PropsValues) => void;
  field: any;
}

export interface UseFieldHandlersParams<
  TExtraRef extends Record<string, any> = Record<string, any>,
> {
  fieldName: string;
  props: Record<string, any>;
  formState: PropsValues;
  dispatch: ReturnType<typeof useAppDispatch>;
  formContext: { formId: string } | null;
  updateStore: (newFormState: PropsValues) => void;
  /** Domain-specific values that should be kept fresh in the stable ref. */
  extraRefData?: TExtraRef;
  /**
   * Extracts the new value from a change event.
   */
  extractNewValue: (
    e: ReactType.ChangeEvent,
    latest?: BaseLatestPropsRef & TExtraRef,
  ) => any;
  // Validates the new value extracted from a change or blur event.
  validateNewValue: (
    e: ReactType.ChangeEvent,
    newValue: any,
    latest: BaseLatestPropsRef & TExtraRef,
  ) => ValidationResult;
  errorsText: (errors?: any) => string;
}

export interface UseFieldHandlersResult<
  TExtraRef extends Record<string, any> = Record<string, any>,
> {
  stableOnChange: (e: any) => void;
  stableOnBlur: (e: any) => void;
  fieldContextValue: FieldContextValue;
  latestPropsRef: MutableRefObject<BaseLatestPropsRef & TExtraRef>;
}

/**
 * Shared implementation of the stable onChange/onBlur callbacks and
 * FieldContext value used by both component and page-data form fields.
 */
export function useFieldHandlers<
  TExtraRef extends Record<string, any> = Record<string, any>,
>({
  fieldName,
  props,
  formState,
  dispatch,
  formContext,
  updateStore,
  extraRefData,
  extractNewValue,
  validateNewValue,
  errorsText,
}: UseFieldHandlersParams<TExtraRef>): UseFieldHandlersResult<TExtraRef> {
  // Keep a ref that always holds the latest render's values. The stable
  // callbacks below close over this ref rather than the values directly,
  // so they never go stale without needing to be recreated.
  const latestPropsRef = useRef<BaseLatestPropsRef & TExtraRef>({
    props,
    formState,
    dispatch,
    formContext,
    updateStore,
    field: null,
    ...(extraRefData as TExtraRef),
  } as BaseLatestPropsRef & TExtraRef);

  latestPropsRef.current = {
    ...latestPropsRef.current,
    props,
    formState,
    dispatch,
    formContext,
    updateStore,
    ...(extraRefData as TExtraRef),
  };

  // Stable onChange — created once, reads latest values through the ref.
  const stableOnChange = useCallback(
    (e: any) => {
      const latest = latestPropsRef.current;
      // Clear any Redux-stored field error before processing the new value.
      if (latest.dispatch && latest.formContext) {
        latest.dispatch(
          clearFieldError({
            formId: latest.formContext.formId as FormId,
            fieldName,
          }),
        );
      }
      const handler = createEnhancedOnChange({
        field: latest.field,
        props: latest.props,
        dispatch: latest.dispatch,
        formId: latest.formContext?.formId,
        fieldName,
        formState: latest.formState,
        updateStore: latest.updateStore,
        extractNewValue: (e: ReactType.ChangeEvent) =>
          extractNewValue(e, latest),
        validateNewValue: (e: ReactType.ChangeEvent, newValue: any) =>
          validateNewValue(e, newValue, latest),
        errorsText,
      });
      handler(e);
    },
    // fieldName is stable for the lifetime of this field instance.
    // extractNewValue, validateNewValue, and errorsText are expected to be
    // stable references (module-level functions or stable closures).
    [fieldName, extractNewValue, validateNewValue, errorsText],
  );

  // Stable onBlur — same pattern as stableOnChange.
  const stableOnBlur = useCallback(
    (e: any) => {
      const latest = latestPropsRef.current;
      const handler = createEnhancedOnBlur({
        field: latest.field,
        props: latest.props,
        dispatch: latest.dispatch,
        formId: latest.formContext?.formId,
        fieldName,
        validateNewValue: (e: ReactType.ChangeEvent, newValue: any) =>
          validateNewValue(e, newValue, latest),
        errorsText,
      });
      handler(e);
    },
    [fieldName, validateNewValue, errorsText],
  );

  // FieldContext value lets child components trigger form updates without
  // prop-drilling onChange/onBlur through the component tree.
  const fieldContextValue = useMemo<FieldContextValue>(
    () => ({
      fieldName,
      triggerChange: (valueOrEvent: any, target?: HTMLElement) => {
        if (
          valueOrEvent &&
          typeof valueOrEvent === 'object' &&
          'target' in valueOrEvent
        ) {
          // Already an event — pass through directly.
          stableOnChange(valueOrEvent);
        } else {
          // A plain value — wrap in a synthetic change event.
          const event = createSyntheticChangeEvent(valueOrEvent, target);
          stableOnChange(event);
        }
      },
      triggerBlur: (target?: HTMLElement) => {
        const event = createSyntheticBlurEvent(target);
        stableOnBlur(event);
      },
    }),
    [fieldName, stableOnChange, stableOnBlur],
  );

  return {
    stableOnChange,
    stableOnBlur,
    fieldContextValue,
    latestPropsRef,
  };
}
