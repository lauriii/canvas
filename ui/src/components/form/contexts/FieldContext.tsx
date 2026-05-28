import { createContext, useContext } from 'react';

/**
 * Context for field-level form operations.
 *
 * Provided by react-hook-form/fields (ComponentFormField/PageDataFormField)
 * to allow child components of the field to trigger form state updates.
 */

export interface FieldContextValue {
  fieldName: string;
  /**
   * Triggers the enhanced onChange handler.
   * Overload 1: Pass value and optional target element (simpler, preferred)
   * Overload 2: Pass a pre-constructed event (for complex cases)
   */
  triggerChange: {
    (value: any, target?: HTMLElement): void;
    (event: Event | React.ChangeEvent): void;
  };
  /**
   * Triggers the enhanced onBlur handler.
   */
  triggerBlur: (target?: HTMLElement) => void;
}

const FieldContext = createContext<FieldContextValue | null>(null);

/**
 * Hook to access FieldContext. Returns null if not within a wrapped field.
 */
export const useFieldContext = () => useContext(FieldContext);

export const FieldContextProvider = FieldContext.Provider;

/**
 * Helper to create a synthetic change event with proper target.
 * If no target element is provided, creates a minimal synthetic target
 * with the value property set.
 */
export function createSyntheticChangeEvent(
  value: any,
  target?: HTMLElement,
): Event {
  const event = new Event('change', { bubbles: true });

  // If a real target is provided, use it (setting value if provided)
  if (target) {
    if (value !== undefined) {
      (target as any).value = value;
    }

    Object.defineProperty(event, 'target', {
      writable: false,
      value: target,
    });
  } else {
    // No target provided - create a minimal synthetic target object
    // For booleans, include 'checked' property so parseValue correctly
    // identifies this as a checkbox/toggle value
    const syntheticTarget: { value: any; checked?: boolean } = { value };
    if (typeof value === 'boolean') {
      syntheticTarget.checked = value;
    }
    Object.defineProperty(event, 'target', {
      writable: false,
      value: syntheticTarget,
    });
  }

  return event;
}

/**
 * Helper to create a synthetic blur event with proper target.
 */
export function createSyntheticBlurEvent(target?: HTMLElement): FocusEvent {
  const event = new FocusEvent('blur', { bubbles: true });

  if (target) {
    Object.defineProperty(event, 'target', {
      writable: false,
      value: target,
    });
  }

  return event;
}
