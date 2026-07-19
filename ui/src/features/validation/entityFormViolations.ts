import { FORM_TYPES } from '@/features/form/constants';
import { clearFormErrors, setFieldError } from '@/features/form/formStateSlice';

import type { Dispatch } from '@reduxjs/toolkit';

/**
 * A stored entity-form constraint violation, as emitted by the layout GET
 * response (`entityFormViolations`) and, in dotted `source.pointer` form, by
 * publish-time 422 error objects.
 *
 * Pointer/property-path formats produced by the backend:
 * - Entity form-state errors: Drupal element paths (`title][0][value`) are
 *   exploded on `][` and imploded with `.`, yielding `title.0.value`.
 *   @see \Drupal\canvas\ClientDataToEntityConverter::setEntityFields()
 * - Field access errors: `entity_form_fields.{field_name}` (no delta).
 *   @see \Drupal\canvas\ClientDataToEntityConverter::setEntityFields()
 * - Component-tree violations are renamed to `layout.children...`,
 *   `layout...`, or `model...` and never target an entity form field.
 *   @see \Drupal\canvas\ClientDataToEntityConverter::convert()
 * - Publish-time error objects copy the violation's property path verbatim
 *   into `source.pointer`.
 *   @see \Drupal\canvas\EventSubscriber\ApiExceptionSubscriber::violationToJsonApiStyleErrorObject()
 * - The layout GET response replays stored auto-save violations as
 *   `entityFormViolations: [{propertyPath, message}]`.
 *   @see \Drupal\canvas\Controller\ApiLayoutController
 */
export interface EntityFormViolation {
  propertyPath: string;
  message: string;
}

/**
 * The DOM event dispatched to jump the sidebar form to a specific field.
 */
export const FOCUS_ENTITY_FORM_FIELD_EVENT = 'canvas:focus-entity-form-field';

/**
 * The property-path prefix used for field access violations.
 *
 * @see \Drupal\canvas\ClientDataToEntityConverter::setEntityFields()
 */
const ENTITY_FORM_FIELDS_PREFIX = 'entity_form_fields';

/**
 * Property-path segments of entity form fields only ever contain field
 * machine names, numeric deltas, and property names. Anything else (UUIDs are
 * allowed by the hyphen, but colons, brackets, and spaces are not) marks a
 * path this mapping does not understand.
 */
const SEGMENT_PATTERN = /^[a-zA-Z0-9_-]+$/;

/**
 * Maps a violation property path to the matching form element name.
 *
 * Inverts the element-path-to-property-path transform documented on
 * EntityFormViolation: `title.0.value` becomes `title[0][value]` (first
 * segment bare, subsequent segments bracketed), matching the `name` attribute
 * the Drupal-rendered form gives each control and the key the pageData form
 * registers it under. An `entity_form_fields.` prefix is stripped first. A
 * single remaining segment maps to itself (e.g. an access violation on a
 * whole field).
 *
 * @param propertyPath - The dotted violation property path.
 * @returns The form element name, or null for component-tree/layout paths
 *   (`layout.*`, `model.*`) and paths that cannot be mapped.
 */
export const propertyPathToFormElementName = (
  propertyPath: string,
): string | null => {
  let segments = propertyPath.split('.');
  if (segments[0] === ENTITY_FORM_FIELDS_PREFIX) {
    segments = segments.slice(1);
  }
  if (segments.length === 0) {
    return null;
  }
  // Component-tree violations target the layout, not an entity form field.
  if (segments[0] === 'layout' || segments[0] === 'model') {
    return null;
  }
  if (!segments.every((segment) => SEGMENT_PATTERN.test(segment))) {
    return null;
  }
  const [fieldName, ...rest] = segments;
  return rest.reduce((carry, segment) => `${carry}[${segment}]`, fieldName);
};

/**
 * Surfaces entity-form violations as blocking errors on the sidebar form.
 *
 * Each mappable violation becomes a `setFieldError` on the entity form
 * (FORM_TYPES.ENTITY_FORM), which FieldErrorDisplay renders under the
 * matching field. Unmappable violations are skipped.
 *
 * @param dispatch - The store dispatch.
 * @param violations - The violations to apply.
 */
export const applyEntityFormViolations = (
  dispatch: Dispatch,
  violations: EntityFormViolation[],
): void => {
  violations.forEach(({ propertyPath, message }) => {
    const fieldName = propertyPathToFormElementName(propertyPath);
    if (fieldName) {
      dispatch(
        setFieldError({
          formId: FORM_TYPES.ENTITY_FORM,
          fieldName,
          type: 'error',
          message,
        }),
      );
      // Entity-level constraint violations (publish-time `validate()`) carry
      // bare field names, but most widgets register their control under the
      // first delta's main property. Registering the error under both keys
      // makes it display in the common case; the unmatched key is inert.
      // @see \Drupal\canvas\Controller\ApiAutoSaveController::post()
      if (!fieldName.includes('[')) {
        dispatch(
          setFieldError({
            formId: FORM_TYPES.ENTITY_FORM,
            fieldName: `${fieldName}[0][value]`,
            type: 'error',
            message,
          }),
        );
      }
    }
  });
};

/**
 * Clears all blocking errors on the entity form.
 *
 * Called when a layout loads without stored violations so errors from a
 * previously open entity (or an already-corrected draft) do not linger.
 *
 * @param dispatch - The store dispatch.
 */
export const clearEntityFormViolations = (dispatch: Dispatch): void => {
  dispatch(clearFormErrors(FORM_TYPES.ENTITY_FORM));
};

/**
 * Whether a publish error's entity matches the currently open editor route.
 *
 * @param errorMeta - The error object's meta (entity_type/entity_id).
 * @param routeParams - The editor route params (entityType/entityId).
 * @returns True when the error belongs to the entity open in the editor.
 */
export const isViolationForOpenEntity = (
  errorMeta: { entity_type?: string; entity_id?: string | number } | undefined,
  routeParams: { entityType?: string; entityId?: string },
): boolean =>
  errorMeta?.entity_type !== undefined &&
  errorMeta?.entity_id !== undefined &&
  routeParams.entityType !== undefined &&
  routeParams.entityId !== undefined &&
  errorMeta.entity_type === routeParams.entityType &&
  String(errorMeta.entity_id) === routeParams.entityId;

/**
 * Requests that the sidebar form scroll to and focus a form control.
 *
 * Dispatches a DOM CustomEvent that ContextualPanel listens for; the panel
 * activates the tab whose partition contains the control before focusing it.
 *
 * @param fieldName - The form element name, e.g. `title[0][value]`.
 */
export const focusFormField = (fieldName: string): void => {
  document.dispatchEvent(
    new CustomEvent(FOCUS_ENTITY_FORM_FIELD_EVENT, {
      detail: { fieldName },
    }),
  );
};
