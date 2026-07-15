import { useCallback, useEffect, useRef, useState } from 'react';
import clsx from 'clsx';

import { useAppSelector } from '@/app/hooks';
import InputDescription from '@/components/form/components/drupal/InputDescription';
import FormElementLabel from '@/components/form/components/FormElementLabel';
import {
  errorsText,
  validateProp,
} from '@/components/form/react-hook-form/fields/componentFieldValidation';
import { toDateTime } from '@/components/form/react-hook-form/fields/componentFormData';
import { isEvaluatedComponentModel } from '@/features/layout/layoutModelSlice';
import { selectLatestUndoRedoActionId } from '@/features/ui/uiSlice';

import { useNativePropWrite } from './useNativePropWrite';

import type { StaticPropSource } from '@/features/layout/layoutModelSlice';
import type {
  ClientWidgetContext,
  ClientWidgetDefinition,
  WidgetCodecResult,
} from './types';

import styles from '@/components/form/components/FormElement.module.css';

export interface PropSlotState {
  visible: boolean;
  enabled: boolean;
}

interface NativePropSlotProps {
  context: ClientWidgetContext;
  definition: ClientWidgetDefinition;
  slotState: PropSlotState;
}

/**
 * Renders one natively edited prop: shared widget chrome (label, description,
 * required indicator, error presentation) from schema metadata, the client
 * widget itself, and the validate-then-write pipeline.
 *
 * A hidden prop (via `x-canvas-states`) keeps its model value; visibility is
 * a UI affordance only.
 */
const NativePropSlot = ({
  context,
  definition,
  slotState,
}: NativePropSlotProps) => {
  const { propName, jsonSchema, required } = context;
  const { write, inputAndUiData } = useNativePropWrite(context);
  const { selectedComponent, model } = inputAndUiData;
  const latestUndoRedoActionId = useAppSelector(selectLatestUndoRedoActionId);

  const selectedModel = model?.[selectedComponent];
  const readModelValue = useCallback(() => {
    const resolved = selectedModel?.resolved?.[propName];
    const source =
      selectedModel && isEvaluatedComponentModel(selectedModel)
        ? ((selectedModel.source?.[propName] as StaticPropSource | undefined)
            ?.value ?? resolved)
        : resolved;
    return definition.codec.fromModel(source, resolved, context);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [selectedModel, propName, definition]);

  const [widgetValue, setWidgetValue] = useState<unknown>(readModelValue);
  const [error, setError] = useState<string | null>(null);

  // Re-derive the widget value from the model when the edited component or an
  // undo/redo changes it underneath the widget. Deliberately NOT on every
  // model echo: the server patch response must not clobber in-progress input.
  const isFirstRender = useRef(true);
  useEffect(() => {
    if (isFirstRender.current) {
      isFirstRender.current = false;
      return;
    }
    setWidgetValue(readModelValue());
    setError(null);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [selectedComponent, latestUndoRedoActionId]);

  const validateResolved = useCallback(
    (codecResult: WidgetCodecResult): string | null => {
      if (codecResult === null) {
        // Empty values are not schema-validated client-side; required-ness
        // remains enforced server-side (required props always have defaults).
        return null;
      }
      // When the stored source value differs from the resolved value (media,
      // image, and file references), the client-side resolved value is a
      // placeholder until the server evaluates the source; the server is the
      // validation authority, matching the server-form path's AJAX behavior.
      if (codecResult.source !== undefined) {
        return null;
      }
      // Object-shaped props (e.g. media/image) have a source shape that
      // differs from their resolved value; the server remains the validation
      // authority for those, matching the server-form path's behavior.
      if (
        jsonSchema.type === 'object' ||
        (jsonSchema.type === 'array' &&
          (jsonSchema.items as { type?: string } | undefined)?.type ===
            'object')
      ) {
        return null;
      }
      let valueToValidate = codecResult.resolved;
      if (
        typeof valueToValidate === 'string' &&
        valueToValidate === '' &&
        !required
      ) {
        return null;
      }
      const format =
        (jsonSchema.format ??
          (jsonSchema.items as { format?: string } | undefined)?.format) ||
        undefined;
      if (format === 'date-time' && typeof valueToValidate === 'string') {
        valueToValidate = toDateTime(valueToValidate);
      }
      const [valid, validateFn] = validateProp(
        propName,
        valueToValidate,
        inputAndUiData,
      );
      return valid ? null : errorsText(validateFn?.errors ?? null);
    },
    [jsonSchema, propName, required, inputAndUiData],
  );

  const handleChange = useCallback(
    (newWidgetValue: unknown) => {
      setWidgetValue(newWidgetValue);
      const customError =
        definition.validate?.(newWidgetValue, context) ?? null;
      if (customError) {
        setError(customError);
        return;
      }
      const codecResult = definition.codec.toModel(newWidgetValue, context);
      const ajvError = validateResolved(codecResult);
      if (ajvError) {
        // The model keeps the last valid value.
        setError(ajvError);
        return;
      }
      setError(null);
      // Discrete inputs (booleans, enums, selections) commit immediately;
      // free-typing inputs are debounced.
      const immediate =
        jsonSchema.type === 'boolean' ||
        'enum' in jsonSchema ||
        jsonSchema.type === 'object' ||
        jsonSchema.type === 'array';
      write(codecResult, { immediate });
    },
    [context, definition, jsonSchema, validateResolved, write],
  );

  if (!slotState.visible) {
    return null;
  }

  const label = (jsonSchema.title as string | undefined) ?? propName;
  const description = (jsonSchema.description as string | undefined) ?? '';
  const inputId = `canvas-native-widget--${selectedComponent}--${propName}`;
  const WidgetComponent = definition.component;

  return (
    <div
      className={clsx(
        'form-item',
        // The field--name-* class matches the server-rendered widget wrapper
        // so existing tests and styling hooks address native slots the same
        // way.
        `field--name-${propName.toLowerCase().replace(/_/g, '-')}`,
        styles.root,
        {
          'form-item--error': Boolean(error),
          'form-disabled': !slotState.enabled,
        },
      )}
      data-testid={`canvas-native-prop-slot-${propName}`}
      data-canvas-native-widget={context.fieldData.field_widget}
    >
      <FormElementLabel
        attributes={{ htmlFor: inputId }}
        className={required ? 'form-required' : null}
      >
        {label}
      </FormElementLabel>
      <InputDescription description={description} descriptionDisplay="after">
        <WidgetComponent
          {...context}
          value={widgetValue}
          onChange={handleChange}
          disabled={!slotState.enabled}
          errors={error}
          inputId={inputId}
          inputName={`canvas_component_props[${selectedComponent}][${propName}]`}
          siblingValues={
            (selectedModel?.resolved ?? {}) as Record<string, unknown>
          }
        />
        {error && (
          <div
            className="form-item--error-message form-item-errors"
            data-prop-message
          >
            {error}
          </div>
        )}
      </InputDescription>
    </div>
  );
};

export default NativePropSlot;
