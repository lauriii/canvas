import { useCallback, useEffect, useRef } from 'react';

import {
  getPreviewDocument,
  siblingsBetween,
} from '@/features/layout/preview/optimisticPreview';
import {
  isCustomValue,
  parseCustomValue,
  partOf,
} from '@/features/styles/styleUtils';

import type { StyleDefinition } from '@/types/Styles';

/** What one choice adds to an element. */
interface Contribution {
  attribute?: string;
  value?: string;
  declarations: Record<string, string>;
}

/**
 * What a stored value adds to its element, as the server renders it.
 *
 * @see \Drupal\canvas\Style\ComponentStyles::resolveWithDefinitions()
 */
export function contributionOf(
  definition: StyleDefinition,
  value: string | undefined,
): Contribution | null {
  if (value === undefined) return null;
  if (isCustomValue(value)) {
    const parsed = definition.custom
      ? parseCustomValue(value, definition.custom)
      : undefined;
    if (!parsed || !definition.custom) return null;
    return {
      attribute: 'class',
      value: definition.custom.value,
      declarations: {
        [definition.custom.property]: `${parsed.number}${parsed.unit}`,
      },
    };
  }
  const option = definition.options.find(({ id }) => id === value);
  if (!option) return null;
  if (option.kind === 'token' && definition.tokens) {
    return {
      attribute: 'class',
      value: definition.tokens.value,
      declarations: {
        [definition.tokens.property]:
          `var(${option.cssVariable}, ${option.swatch})`,
      },
    };
  }
  if (option.attribute === 'style') {
    return {
      declarations: Object.fromEntries(
        option.value
          .split(';')
          .map((declaration) => declaration.split(':'))
          .filter((parts) => parts.length >= 2)
          .map(([property, ...rest]) => [
            property.trim(),
            rest.join(':').trim(),
          ]),
      ),
    };
  }
  return {
    attribute: option.attribute,
    value: option.value.trim(),
    declarations: {},
  };
}

const apply = (
  element: HTMLElement,
  contribution: Contribution,
  add: boolean,
) => {
  if (contribution.attribute === 'class' && contribution.value) {
    contribution.value
      .split(/\s+/)
      .forEach((name) => element.classList.toggle(name, add));
  } else if (contribution.attribute && contribution.value !== undefined) {
    if (add) element.setAttribute(contribution.attribute, contribution.value);
    else element.removeAttribute(contribution.attribute);
  }
  for (const [property, declaration] of Object.entries(
    contribution.declarations,
  )) {
    if (add) element.style.setProperty(property, declaration);
    else element.style.removeProperty(property);
  }
};

/**
 * A component's root elements in the active preview, between its
 * `canvas-start-{uuid}` and `canvas-end-{uuid}` markers. Works from anywhere
 * in the editor, not only inside the preview.
 */
function rootElements(uuid: string): HTMLElement[] {
  const doc = getPreviewDocument();
  if (!doc?.body) return [];
  const walker = doc.createTreeWalker(doc.body, NodeFilter.SHOW_COMMENT);
  let start: Node | null = null;
  for (let node = walker.nextNode(); node; node = walker.nextNode()) {
    const data = (node as Comment).data.trim();
    if (data === `canvas-start-${uuid}`) start = node;
    else if (start && data === `canvas-end-${uuid}`) {
      return siblingsBetween(start, node).filter(
        (candidate): candidate is HTMLElement => candidate.nodeType === 1,
      );
    }
  }
  return [];
}

/**
 * Elements a selection key styles in the preview: the component's root, or
 * its part, marked `data-canvas-style-part` in previews. A part inside a
 * nested component belongs to that component, not this one.
 */
export function useStyleElements(): (
  uuid: string,
  key: string,
) => HTMLElement[] {
  return useCallback((uuid: string, key: string) => {
    const roots = rootElements(uuid);
    const part = partOf(key);
    if (part === undefined) return roots;
    const selector = `[data-canvas-style-part="${CSS.escape(part)}"]`;
    return roots.flatMap((root) =>
      [
        ...(root.matches(selector) ? [root] : []),
        ...root.querySelectorAll<HTMLElement>(selector),
      ].filter((element) => {
        // The nearest component start marker before the part must be ours.
        for (
          let node: Node | null = element;
          node && node !== root;
          node = node.parentNode
        ) {
          for (
            let sibling = node.previousSibling;
            sibling;
            sibling = sibling.previousSibling
          ) {
            const data =
              sibling.nodeType === 8 ? (sibling as Comment).data.trim() : '';
            if (data.startsWith('canvas-end-')) break;
            if (data.startsWith('canvas-start-'))
              return data === `canvas-start-${uuid}`;
          }
        }
        return true;
      }),
    );
  }, []);
}

/**
 * Previews a choice on hover, as in Webflow and Wix: the preview's classes
 * change at once, without touching the layout, undo, or auto-save. Ending
 * the preview restores the element exactly.
 */
export function useStyleHoverPreview(
  uuid: string | undefined,
  key: string,
  definition: StyleDefinition,
  current: string | undefined,
) {
  const elementsFor = useStyleElements();
  // Every attribute of each touched element, to restore it exactly.
  const saved = useRef(new Map<HTMLElement, Array<[string, string]>>());

  const end = useCallback(() => {
    saved.current.forEach((attributes, element) => {
      element
        .getAttributeNames()
        .forEach((name) => element.removeAttribute(name));
      attributes.forEach(([name, value]) => element.setAttribute(name, value));
    });
    saved.current.clear();
  }, []);

  const preview = useCallback(
    (candidate: string | undefined) => {
      if (!uuid) return;
      end();
      const from = contributionOf(definition, current);
      const to = contributionOf(definition, candidate);
      for (const element of elementsFor(uuid, key)) {
        saved.current.set(
          element,
          element
            .getAttributeNames()
            .map((name) => [name, element.getAttribute(name)!]),
        );
        if (from) apply(element, from, false);
        if (to) apply(element, to, true);
      }
    },
    [uuid, key, definition, current, elementsFor, end],
  );

  // A real change re-renders the preview; never leave a hover behind.
  useEffect(() => end, [end, current]);

  return { preview, end };
}
