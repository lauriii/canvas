/**
 * @file
 * Finds a prop's field in the component instance form in the sidebar.
 *
 * Inline editing and the sidebar are two views of one prop. These helpers let
 * the inline editor use the field's label, mark the field and show the typed
 * text in it while the prop is edited on the canvas, and send the author to it
 * for text that cannot be edited in place.
 */

/** Marks the form item of a prop being edited on the canvas. */
export const EDITING_ON_CANVAS_ATTRIBUTE = 'data-canvas-editing-on-canvas';

const fieldName = (componentUuid: string, propName: string) =>
  `canvas_component_props[${componentUuid}][${propName}]`;

/** The form control holding the prop's main value, if the form is shown. */
export function getSidebarControl(
  componentUuid: string,
  propName: string,
): HTMLInputElement | HTMLTextAreaElement | null {
  const prefix = fieldName(componentUuid, propName);
  return (
    document.querySelector<HTMLInputElement | HTMLTextAreaElement>(
      `[name="${prefix}[0][value]"], [name="${prefix}[0][uri]"]`,
    ) ??
    document.querySelector<HTMLInputElement | HTMLTextAreaElement>(
      `[name^="${prefix}"]:not([type="hidden"])`,
    )
  );
}

const formItem = (control: Element | null) =>
  control?.closest<HTMLElement>(
    '.js-form-item, .form-item, [data-canvas-form-item]',
  ) ??
  control?.parentElement ??
  null;

/** The label the sidebar shows for a prop, such as "Heading". */
export function getSidebarLabel(
  componentUuid: string,
  propName: string,
): string | null {
  const control = getSidebarControl(componentUuid, propName);
  if (!control) {
    return null;
  }
  const label =
    (control.id &&
      document.querySelector(`label[for="${CSS.escape(control.id)}"]`)) ||
    formItem(control)?.querySelector('label');
  return label?.textContent?.replace(/\*$/, '').trim() || null;
}

/** Moves focus to a prop's field in the sidebar. Returns whether it could. */
export function focusSidebarField(
  componentUuid: string,
  propName: string,
): boolean {
  const control = getSidebarControl(componentUuid, propName);
  if (!control) {
    return false;
  }
  // Formatted text is edited by a CKEditor next to its hidden textarea.
  const target =
    control.offsetParent === null
      ? formItem(control)?.querySelector<HTMLElement>(
          '[contenteditable="true"], input:not([type="hidden"]), textarea, select',
        )
      : control;
  if (!target) {
    return false;
  }
  target.scrollIntoView({ block: 'nearest' });
  target.focus();
  return true;
}

/**
 * Marks a prop's sidebar field while the prop is edited on the canvas.
 *
 * The field shows the new value after the commit. Typing is not copied into
 * it: the form tracks its fields' values itself, and a value written into a
 * field from outside is sent again by the form, as a second change.
 *
 * @returns A function that removes the mark.
 */
export function markSidebarFieldEditing(
  componentUuid: string,
  propName: string,
): () => void {
  const item = formItem(getSidebarControl(componentUuid, propName));
  item?.setAttribute(EDITING_ON_CANVAS_ATTRIBUTE, '');
  return () => item?.removeAttribute(EDITING_ON_CANVAS_ATTRIBUTE);
}

/**
 * Shows text typed on the canvas in a prop's plain text field.
 *
 * The field's value is left alone, for the reason given above: a copy of the
 * text covers it instead, until the form is rebuilt with the saved value.
 *
 * @returns The copy's controls, or null for a field that is not plain text.
 */
export function mirrorSidebarText(
  componentUuid: string,
  propName: string,
): { update: (text: string) => void; remove: () => void } | null {
  const control = getSidebarControl(componentUuid, propName);
  const parent = control?.parentElement;
  // Formatted text has a hidden textarea, edited through CKEditor.
  if (!control || !parent || control.offsetParent === null) {
    return null;
  }
  if (getComputedStyle(parent).position === 'static') {
    parent.style.position = 'relative';
  }
  const style = getComputedStyle(control);
  const isInput = control instanceof HTMLInputElement;
  const copy = document.createElement('div');
  copy.setAttribute('aria-hidden', 'true');
  copy.setAttribute('data-canvas-sidebar-mirror', '');
  Object.assign(copy.style, {
    position: 'absolute',
    top: `${control.offsetTop}px`,
    left: `${control.offsetLeft}px`,
    width: `${control.offsetWidth}px`,
    height: `${control.offsetHeight}px`,
    boxSizing: 'border-box',
    // Single-line inputs center their text vertically.
    display: isInput ? 'flex' : 'block',
    alignItems: 'center',
    padding: style.padding,
    textIndent: style.textIndent,
    borderWidth: style.borderWidth,
    borderStyle: 'solid',
    borderColor: 'transparent',
    font: style.font,
    lineHeight: style.lineHeight,
    color: style.color,
    overflow: 'hidden',
    textOverflow: 'ellipsis',
    whiteSpace: isInput ? 'pre' : 'pre-wrap',
    pointerEvents: 'none',
  });
  parent.append(copy);
  // Some themes set the text fill color, which wins over the text color.
  control.style.setProperty('-webkit-text-fill-color', 'transparent');
  return {
    update: (text) => {
      copy.textContent = text;
    },
    remove: () => {
      copy.remove();
      control.style.removeProperty('-webkit-text-fill-color');
    },
  };
}
