import { useLayoutEffect } from 'react';

import { computeVisibility } from '@/utils/propVisibility';

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
export const readControl = (wrapper: HTMLElement): unknown => {
  const controls = [...wrapper.querySelectorAll<HTMLInputElement>(CONTROLS)];
  // Boolean props render as a switch.
  // @see ui/src/components/form/components/drupal/DrupalToggle.tsx
  const toggles = wrapper.querySelectorAll('[role="switch"]');
  if (toggles.length === 1 && controls.length === 0) {
    return toggles[0].getAttribute('aria-checked') === 'true';
  }
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
  // Optional selects offer "None".
  // @see \Drupal\Core\Field\Plugin\Field\FieldWidget\OptionsWidgetBase::EMPTY_VALUE
  return control.value === '_none' ? null : control.value;
};

const isInvalid = (wrapper: HTMLElement): boolean =>
  wrapper.querySelector('[data-invalid-prop-value="true"]') !== null;

const labelOf = (wrapper: HTMLElement): string =>
  wrapper.querySelector('label')?.textContent?.replace('*', '').trim() ||
  (wrapper.dataset.canvasProp as string);

/**
 * Announces props that appear or disappear to screen reader users.
 */
const announce = (message: string) => {
  let region = document.getElementById('canvas-prop-visibility-status');
  if (!region) {
    region = document.createElement('div');
    region.id = 'canvas-prop-visibility-status';
    region.setAttribute('role', 'status');
    region.style.cssText =
      'position:absolute;width:1px;height:1px;overflow:hidden;clip-path:inset(50%);white-space:nowrap';
    document.body.append(region);
  }
  region.textContent = message;
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
    const apply = (userChange = false) => {
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
      const shown: string[] = [];
      const hidden: string[] = [];
      wrappers.forEach((wrapper) => {
        const propName = wrapper.dataset.canvasProp as string;
        const visible = visibility[propName];
        if (visible === undefined) {
          return;
        }
        const wasHidden = wrapper.hidden;
        // A prop with an invalid value stays visible, so the user can fix it.
        wrapper.hidden = !visible && !isInvalid(wrapper);
        if (wasHidden && !wrapper.hidden) {
          // Fades props in as they appear.
          wrapper.dataset.canvasRevealed = '';
          setTimeout(() => delete wrapper.dataset.canvasRevealed, 200);
          shown.push(labelOf(wrapper));
        } else if (!wasHidden && wrapper.hidden) {
          hidden.push(labelOf(wrapper));
        }
      });
      if (userChange && (shown.length || hidden.length)) {
        announce(
          [
            shown.length && `Shown: ${shown.join(', ')}.`,
            hidden.length && `Hidden: ${hidden.join(', ')}.`,
          ]
            .filter(Boolean)
            .join(' '),
        );
      }
    };
    apply();
    // After React re-rendered the control, e.g. a switch after a click.
    const scheduleApply = () => requestAnimationFrame(() => apply(true));
    const events = ['input', 'change', 'click'];
    events.forEach((event) => form.addEventListener(event, scheduleApply));
    return () => {
      events.forEach((event) => form.removeEventListener(event, scheduleApply));
    };
  }, [formRef, resolved, formContent]);
};
