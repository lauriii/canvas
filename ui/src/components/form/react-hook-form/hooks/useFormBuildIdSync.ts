import { useEffect } from 'react';

import { dispatchFieldValue } from '@/components/form/react-hook-form/utils';
import { AJAX_UPDATE_FORM_BUILD_ID_EVENT } from '@/types/Ajax';

import type { UseFormReturn } from 'react-hook-form';
import type { useAppDispatch } from '@/app/hooks';
import type { FormId } from '@/features/form/formStateSlice';
import type { AjaxUpdateFormBuildIdEvent } from '@/types/Ajax';

/**
 * Hook that handles form_build_id updates from AJAX callbacks
 *
 * Special handling for the form_build_id field which can be updated by an AJAX
 * callback without using hyperscriptify to render a new React component.
 *
 * @param fieldName - The name of the field
 * @param formId - The form ID
 * @param rhfContext - React Hook Form context
 * @param dispatch - Redux dispatch function
 */
export const useFormBuildIdSync = (
  fieldName: string,
  formId: FormId | undefined,
  rhfContext: UseFormReturn | null,
  dispatch: ReturnType<typeof useAppDispatch>,
) => {
  useEffect(() => {
    if (fieldName !== 'form_build_id' || !rhfContext || !formId) {
      return;
    }

    // Listen for changes to the form build ID so we can update that in
    // our form state and value.
    const formBuildIdListener = (e: AjaxUpdateFormBuildIdEvent) => {
      if (e.detail.formId === formId) {
        dispatchFieldValue(
          dispatch,
          formId,
          fieldName,
          e.detail.newFormBuildId,
        );
        // Update react-hook-form value
        rhfContext.setValue(fieldName, e.detail.newFormBuildId);
      }
    };

    // Listen for the event triggered in js/ajax.command.customizations.js.
    // It's triggered from an override that intercepts core AJAX update_build_id
    // commands and skips the default in favor of sending a custom event. This
    // allow us to manage the build id changes fully in React, which prevents
    // issues where jQuery is confused by a perpetually re-rendering form.
    document.addEventListener(
      AJAX_UPDATE_FORM_BUILD_ID_EVENT,
      formBuildIdListener as unknown as EventListener,
    );

    return () => {
      document.removeEventListener(
        AJAX_UPDATE_FORM_BUILD_ID_EVENT,
        formBuildIdListener as unknown as EventListener,
      );
    };
  }, [dispatch, fieldName, formId, rhfContext]);
};
