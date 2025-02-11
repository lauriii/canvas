export const AJAX_UPDATE_FORM_STATE_EVENT = 'ajaxUpdateFormState';
export interface AjaxUpdateFormStateEvent {
  detail: {
    formId: string | null;
    updates: Record<string, string | null>;
  };
}
