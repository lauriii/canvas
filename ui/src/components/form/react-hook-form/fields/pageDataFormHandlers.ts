import { useEffect } from 'react';

import { setUpdatePreview } from '@/features/layout/layoutModelSlice';
import {
  externalUpdateComplete,
  setPageData,
} from '@/features/pageData/pageDataSlice';

import type { UseFormReturn } from 'react-hook-form';
import type { PropsValues } from '@drupal-canvas/types';
import type { Dispatch } from '@reduxjs/toolkit';

/**
 * Extracts the new value from a page data form change event.
 *
 * Handles multi-selects (returns array of selected values), checkboxes/radios
 * (returns '1'/'0'), and all other inputs (returns value, or null for '_none').
 */
export const extractPageDataValue = (e: any): any => {
  const target = e?.target as HTMLInputElement | HTMLSelectElement | undefined;
  if (!target) return null;
  if (target instanceof HTMLSelectElement && target.multiple) {
    return Array.from(target.selectedOptions).map((opt) => opt.value);
  }
  if (target.type === 'checkbox' || target.type === 'radio') {
    return (target as HTMLInputElement).checked ? '1' : '0';
  }
  if ('value' in target) {
    return target.value === '_none' ? null : target.value;
  }
  return null;
};

/**
 * Creates a form state handler for page data forms.
 * Handles field filtering and Redux state updates for entity/page forms.
 */
export const createPageDataFormStateHandler = (dispatch: Dispatch) => {
  return (newFormState: PropsValues) => {
    const values = Object.keys(newFormState).reduce(
      (acc: Record<string, any>, key) => {
        if (
          !['changed', 'formId', 'formType', 'externalUpdates'].includes(key)
        ) {
          const value = newFormState[key];
          if (
            Array.isArray(value) &&
            // @todo replace this with a better solution in https://www.drupal.org/i/3587609.
            document.querySelector(
              `select[data-is-multiselect="true"][name="${key}"]`,
            )
          ) {
            const baseKey = key.slice(0, -2);
            (value as any[]).forEach((item, index) => {
              acc[`${baseKey}[${index}]`] = item;
            });
            return acc;
          }
          return { ...acc, [key]: value };
        }
        return acc;
      },
      {},
    );
    // Flag that we need to update the preview.
    dispatch(setUpdatePreview(true));
    dispatch(setPageData(values));
  };
};

/**
 * Watches for external updates to pageData and syncs them to react-hook-form.
 * When a field is marked for external update, this hook updates RHF and clears the flag.
 */
export const useRespondToPageDataStoreUpdates = (
  fieldName: string,
  pageData: PropsValues | null,
  rhfContext: UseFormReturn | null,
  dispatch: Dispatch,
) => {
  useEffect(() => {
    if (!rhfContext || !pageData) return;

    // If this field was updated externally, sync it to RHF
    if (
      pageData.externalUpdates?.includes(fieldName) &&
      fieldName in pageData
    ) {
      rhfContext.setValue(fieldName, pageData[fieldName], {
        shouldValidate: false,
        shouldDirty: true,
        shouldTouch: false,
      });

      // Clear the external update flag for this field
      dispatch(externalUpdateComplete(fieldName));
    }
  }, [pageData, pageData?.externalUpdates, fieldName, rhfContext, dispatch]);
};
