import React from 'react';

import { useAppDispatch } from '@/app/hooks';
import {
  useSafeFormContext,
  useSafeRHFContext,
} from '@/components/form/contexts/FormContext';
import { ComponentFormField } from '@/components/form/react-hook-form/fields/ComponentFormField';
import { PageDataFormField } from '@/components/form/react-hook-form/fields/PageDataFormField';
import { useAjaxFieldRegistration } from '@/components/form/react-hook-form/hooks/useAjaxFieldRegistration';
import { useFormBuildIdSync } from '@/components/form/react-hook-form/hooks/useFormBuildIdSync';
import {
  compareAllPropsWithDeepAttributes,
  getCurrentValueFromProps,
} from '@/components/form/react-hook-form/utils';

/**
 * Higher-Order Component that integrates react-hook-form with form inputs.
 *
 * Usage:
 *   export default withRHF(MyComponent);
 */
export const withRHF = <P extends Record<string, any>>(
  WrappedComponent: React.ComponentType<P>,
): React.ComponentType<P> => {
  const WithRHF = (props: P) => {
    const dispatch = useAppDispatch();
    // Information about the form overall, such as form id.
    const formContext = useSafeFormContext();
    // Provides access to RHF internals.
    const rhfContext = useSafeRHFContext();

    // Extract field name from various possible prop structures
    // Note: data-canvas-name is used for radios and potentially other inputs
    // where value management is not specific to one form field.
    const fieldName =
      props.attributes?.name ||
      props.attributes?.['data-canvas-name'] ||
      props.name ||
      props.attributes?.id;

    // Get the current value from various possible sources
    const getCurrentValue = () => getCurrentValueFromProps(props);

    // Special handling for the form_build_id which can be updated by a core
    // AJAX callback.
    useFormBuildIdSync(fieldName, formContext?.formId, rhfContext, dispatch);

    // Checks for fields added via AJAX, thus not registered with
    // react-hook-form on initialization.
    useAjaxFieldRegistration(fieldName, rhfContext, getCurrentValue);

    // If there's no react-hook-form context or field name, then the input
    // should be rendered without enhancement.
    if (!rhfContext || !fieldName) {
      return <WrappedComponent {...props} />;
    }

    const formFieldProps = {
      props,
      WrappedComponent,
      fieldName,
    };

    // Route to appropriate sub-component based on form type
    return (
      <>
        {formContext?.formId === 'page_data_form' && (
          <PageDataFormField {...formFieldProps} />
        )}
        {formContext?.formId === 'component_instance_form' && (
          <ComponentFormField {...formFieldProps} />
        )}
      </>
    );
  };

  WithRHF.displayName = `withRHF(${WrappedComponent.displayName || WrappedComponent.name || 'Component'})`;

  // Wrap with React.memo to prevent re-renders when props haven't actually changed
  // Use deep comparison since props objects are often recreated with same values
  return React.memo(
    WithRHF,
    compareAllPropsWithDeepAttributes,
  ) as unknown as React.ComponentType<P>;
};

export default withRHF;
