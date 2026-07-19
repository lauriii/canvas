import React, { useCallback, useMemo } from 'react';
import { Controller } from 'react-hook-form';

import { useSafeRHFContext } from '@/components/form/contexts/FormContext';
import {
  applyEnhancedProps,
  comparePropsWithAttributes,
  getCurrentValueFromProps,
} from '@/components/form/react-hook-form/utils';

import type { ComponentType } from 'react';

interface StackedEntityFormFieldProps<P> {
  props: P;
  WrappedComponent: ComponentType<P>;
  fieldName: string;
}

/**
 * Wires a Drupal form element into the stacked reference-editing form.
 *
 * The slim sibling of PageDataFormField: react-hook-form keeps the field
 * interactive (controlled value plus change handling), but nothing is written
 * to Redux — the pageData slice, undo history, and preview belong to the open
 * entity, not the referenced one. The stacked panel serializes the form DOM
 * and auto-saves it through the entity-form-fields endpoint instead.
 *
 * @see ui/src/components/stackedEntityForm/StackedEntityForm.tsx
 */
export const StackedEntityFormField = <P extends Record<string, any>>({
  props,
  WrappedComponent,
  fieldName,
}: StackedEntityFormFieldProps<P>) => {
  const rhfContext = useSafeRHFContext();

  const MemoizedWrappedComponent = useMemo(
    () => React.memo(WrappedComponent, comparePropsWithAttributes),
    [WrappedComponent],
  ) as unknown as React.ComponentType<P>;

  if (!rhfContext) {
    return <MemoizedWrappedComponent {...(props as any)} />;
  }

  return (
    <Controller
      name={fieldName}
      control={rhfContext.control}
      defaultValue={getCurrentValueFromProps(props)}
      render={({ field }) => {
        const onChange = useCallback(
          (e: any) => {
            field.onChange(e);
            // The original attribute handlers (if any) still run through
            // applyEnhancedProps merging in the base components.
          },
          [field],
        );
        const onBlur = useCallback(() => field.onBlur(), [field]);
        const enhancedProps = useMemo(
          () => applyEnhancedProps(props, field, onChange, onBlur),
          // eslint-disable-next-line react-hooks/exhaustive-deps
          [field, field.value, onChange, onBlur],
        );

        return <MemoizedWrappedComponent {...(enhancedProps as any)} />;
      }}
    />
  );
};
