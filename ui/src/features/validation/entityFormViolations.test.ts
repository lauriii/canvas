import { describe, expect, it, vi } from 'vitest';

import { FORM_TYPES } from '@/features/form/constants';
import {
  clearFormErrors,
  formStateSlice,
  initialState,
  setFieldError,
} from '@/features/form/formStateSlice';
import {
  applyEntityFormViolations,
  clearEntityFormViolations,
  FOCUS_ENTITY_FORM_FIELD_EVENT,
  focusFormField,
  isViolationForOpenEntity,
  propertyPathToFormElementName,
} from '@/features/validation/entityFormViolations';

describe('propertyPathToFormElementName', () => {
  it.each([
    ['title.0.value', 'title[0][value]'],
    ['body.0.value', 'body[0][value]'],
    ['field_tags.0.target_id', 'field_tags[0][target_id]'],
    // The `entity_form_fields.` prefix of access violations is stripped.
    ['entity_form_fields.field_image.0.target_id', 'field_image[0][target_id]'],
    ['entity_form_fields.body.0.value', 'body[0][value]'],
    // A whole-field access violation carries no delta or property.
    ['entity_form_fields.field_image', 'field_image'],
    // A bare single segment maps to itself.
    ['title', 'title'],
  ])('maps %s to %s', (propertyPath, expected) => {
    expect(propertyPathToFormElementName(propertyPath)).toBe(expected);
  });

  it.each([
    // Component-tree violations renamed by ClientDataToEntityConverter.
    ['layout.children.0'],
    ['layout'],
    ['model.xyz'],
    ['model'],
    ['entity_form_fields.layout.children.0'],
    // Paths with segments outside the form-element charset.
    ['brand_kit:global'],
    ['field_canvas_demo.0.tree[a94dc7b0-40fb-42d6-9f3f-1d3d43deda36]'],
    ['title.0..value'],
    // Nothing remains after the prefix.
    ['entity_form_fields'],
    [''],
  ])('returns null for %s', (propertyPath) => {
    expect(propertyPathToFormElementName(propertyPath)).toBeNull();
  });
});

describe('applyEntityFormViolations', () => {
  it('dispatches a field error for each mappable violation only', () => {
    const dispatch = vi.fn();
    applyEntityFormViolations(dispatch, [
      { propertyPath: 'title.0.value', message: 'Title is required.' },
      { propertyPath: 'layout.children.0', message: 'Not a form field.' },
      { propertyPath: 'entity_form_fields.body.0.value', message: 'Too long.' },
    ]);

    expect(dispatch).toHaveBeenCalledTimes(2);
    expect(dispatch).toHaveBeenNthCalledWith(
      1,
      setFieldError({
        formId: FORM_TYPES.ENTITY_FORM,
        fieldName: 'title[0][value]',
        type: 'error',
        message: 'Title is required.',
      }),
    );
    expect(dispatch).toHaveBeenNthCalledWith(
      2,
      setFieldError({
        formId: FORM_TYPES.ENTITY_FORM,
        fieldName: 'body[0][value]',
        type: 'error',
        message: 'Too long.',
      }),
    );
  });
});

describe('clearEntityFormViolations', () => {
  it('clears entity form errors but keeps values', () => {
    const withError = formStateSlice.reducer(
      {
        ...initialState,
        [FORM_TYPES.ENTITY_FORM]: {
          values: { 'title[0][value]': 'Hello' },
          errors: {
            'title[0][value]': { type: 'error', message: 'Required.' },
          },
        },
      },
      clearFormErrors(FORM_TYPES.ENTITY_FORM),
    );

    expect(withError[FORM_TYPES.ENTITY_FORM].errors).toEqual({});
    expect(withError[FORM_TYPES.ENTITY_FORM].values).toEqual({
      'title[0][value]': 'Hello',
    });
  });

  it('dispatches clearFormErrors for the entity form', () => {
    const dispatch = vi.fn();
    clearEntityFormViolations(dispatch);
    expect(dispatch).toHaveBeenCalledWith(
      clearFormErrors(FORM_TYPES.ENTITY_FORM),
    );
  });
});

describe('isViolationForOpenEntity', () => {
  it('matches when entity type and id equal the route params', () => {
    expect(
      isViolationForOpenEntity(
        { entity_type: 'node', entity_id: 42 },
        { entityType: 'node', entityId: '42' },
      ),
    ).toBe(true);
  });

  it.each([
    [{ entity_type: 'node', entity_id: 42 }, { entityType: 'node' }],
    [
      { entity_type: 'canvas_page', entity_id: '1' },
      { entityType: 'node', entityId: '1' },
    ],
    [
      { entity_type: 'node', entity_id: '2' },
      { entityType: 'node', entityId: '1' },
    ],
    [{}, { entityType: 'node', entityId: '1' }],
    [undefined, { entityType: 'node', entityId: '1' }],
  ])('does not match %o against %o', (meta, params) => {
    expect(isViolationForOpenEntity(meta, params)).toBe(false);
  });
});

describe('focusFormField', () => {
  it('dispatches a document CustomEvent carrying the field name', () => {
    const listener = vi.fn();
    document.addEventListener(FOCUS_ENTITY_FORM_FIELD_EVENT, listener);
    focusFormField('title[0][value]');
    document.removeEventListener(FOCUS_ENTITY_FORM_FIELD_EVENT, listener);

    expect(listener).toHaveBeenCalledTimes(1);
    expect((listener.mock.calls[0][0] as CustomEvent).detail).toEqual({
      fieldName: 'title[0][value]',
    });
  });
});
