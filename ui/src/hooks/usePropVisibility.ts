import { useLayoutEffect } from 'react';

import { computeDepths, computeVisibility } from '@/utils/propVisibility';

import type { RefObject } from 'react';
import type { VisibilityCondition } from '@/utils/propVisibility';

const CONTROLS =
  'input[name^="canvas_component_props["]:not([type="hidden"]):not([type="submit"]):not([name$="[_weight]"]), select[name^="canvas_component_props["], textarea[name^="canvas_component_props["]';

/**
 * Reads the value of a prop with a single control, as the user edits it.
 *
 * The model only updates after the server rendered the preview. Reading the
 * form element instead shows and hides props as soon as the user changes them.
 *
 * @returns
 *   The value, or undefined for props without a single control, which then use
 *   the model's value.
 */
const readControl = (wrapper: HTMLElement): unknown => {
  // Boolean props render as a switch.
  // @see ui/src/components/form/components/drupal/DrupalToggle.tsx
  const toggle = wrapper.querySelector('[role="switch"]');
  if (toggle) {
    return toggle.getAttribute('aria-checked') === 'true';
  }
  const controls = [...wrapper.querySelectorAll<HTMLInputElement>(CONTROLS)];
  if (controls.length > 0 && controls.every((c) => c.type === 'radio')) {
    return controls.find((c) => c.checked)?.value ?? null;
  }
  if (controls.length !== 1) {
    return undefined;
  }
  const [control] = controls;
  if (control.type === 'checkbox') {
    return control.checked;
  }
  return control.value;
};

/**
 * Shows and hides props in the component form as their conditions pass or fail.
 *
 * @param formRef
 *   The element that contains the component instance form.
 * @param resolved
 *   The prop values of the component instance, from the model.
 * @param formContent
 *   The rendered form; a new form re-applies the visibility.
 *
 * @see \Drupal\canvas\JsonSchemaInterpreter\PropVisibilityCondition
 */
export const usePropVisibility = (
  formRef: RefObject<HTMLElement | null>,
  resolved: Record<string, unknown> | undefined,
  formContent: unknown,
) => {
  useLayoutEffect(() => {
    const form = formRef.current;
    if (!form) {
      return;
    }
    const apply = () => {
      const wrappers = [
        ...form.querySelectorAll<HTMLElement>('[data-canvas-prop]'),
      ];
      const conditions: Record<string, VisibilityCondition> = {};
      const values: Record<string, unknown> = { ...resolved };
      wrappers.forEach((wrapper) => {
        const propName = wrapper.dataset.canvasProp as string;
        if (wrapper.dataset.canvasVisibleIf) {
          conditions[propName] = JSON.parse(wrapper.dataset.canvasVisibleIf);
        }
        const value = readControl(wrapper);
        if (value !== undefined) {
          values[propName] = value;
        }
      });
      const visibility = computeVisibility(conditions, values);
      const depths = computeDepths(conditions);
      wrappers.forEach((wrapper) => {
        const propName = wrapper.dataset.canvasProp as string;
        const visible = visibility[propName];
        if (visible === undefined) {
          return;
        }
        wrapper.style.setProperty(
          '--canvas-prop-depth',
          String(depths[propName]),
        );
        const wasHidden = wrapper.hidden;
        wrapper.hidden = !visible;
        // Briefly highlights props as they appear, so the user notices them.
        if (wasHidden && visible) {
          wrapper.dataset.canvasRevealed = '';
          setTimeout(() => delete wrapper.dataset.canvasRevealed, 1000);
        }
      });
    };
    apply();
    // After React re-rendered the control, e.g. a switch after a click.
    const scheduleApply = () => requestAnimationFrame(apply);
    const events = ['input', 'change', 'click'];
    events.forEach((event) => form.addEventListener(event, scheduleApply));
    return () => {
      events.forEach((event) => form.removeEventListener(event, scheduleApply));
    };
  }, [formRef, resolved, formContent]);
};
