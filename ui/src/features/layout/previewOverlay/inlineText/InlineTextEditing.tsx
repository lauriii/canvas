// cspell:ignore lineboundary documentboundary
import { useCallback, useEffect, useRef, useState } from 'react';
import { v4 as uuidv4 } from 'uuid';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import { selectLayout, selectModel } from '@/features/layout/layoutModelSlice';
import { findComponentByUuid } from '@/features/layout/layoutUtils';
import {
  commitInlineText,
  currentEditTarget,
  getPendingInlineValue,
  runDeferredFormRefresh,
} from '@/features/layout/previewOverlay/inlineText/commitInlineText';
import {
  caretInElementFromPoint,
  createInlineEditingHost,
  findInlineEditablePropAtPoint,
  findInlineEditableProps,
  humanizePropName,
  isRich,
  readPlainValue,
  targetRange,
  toIframePoint,
} from '@/features/layout/previewOverlay/inlineText/inlineTextEditing';
import InlineTextFeedback from '@/features/layout/previewOverlay/inlineText/InlineTextFeedback';
import InlineTextToolbar from '@/features/layout/previewOverlay/inlineText/InlineTextToolbar';
import {
  createRichTextEditor,
  destroyRichTextEditor,
  getFormatToolbarItems,
  getRichTextValue,
  UnsupportedFormatError,
} from '@/features/layout/previewOverlay/inlineText/richTextEditor';
import {
  focusSidebarField,
  getSidebarLabel,
  markSidebarFieldEditing,
  mirrorSidebarText,
} from '@/features/layout/previewOverlay/inlineText/sidebarField';
import {
  endTextEditSession,
  selectIsInlineTextBusy,
  selectPreviewLoading,
  selectSelectedComponentUuid,
  selectTextEditSession,
  selectUndoItem,
  startTextEditSession,
  UndoRedoActionCreators,
} from '@/features/ui/uiSlice';
import { useGetComponentsQuery } from '@/services/componentAndLayout';
import { getCanvasSettings } from '@/utils/drupal-globals';

import type { Editor } from '@ckeditor/ckeditor5-core';
import type {
  ComponentModel,
  ComponentNode,
  RegionNode,
} from '@/features/layout/layoutModelSlice';
import type {
  InlineEditableProp,
  InlineEditingHost,
} from '@/features/layout/previewOverlay/inlineText/inlineTextEditing';
import type { InlineTextNotice } from '@/features/layout/previewOverlay/inlineText/InlineTextFeedback';
import type { FieldDataItem, PropSourceComponent } from '@/types/Component';

const STYLE_ID = 'canvas-inline-text-editing-styles';
const UNSAVED_STORAGE_KEY = 'canvas.inlineTextUnsaved';
const TIP_STORAGE_KEY = 'canvas.inlineTextTipSeen';
// Identifies this page load, so edits stored by it while their commit is in
// flight are not offered as unsaved when the editor remounts.
const LOAD_ID = uuidv4();
const TIP_MESSAGE =
  'Tip: double-click any text to edit it in place. Escape saves and exits.';
// Set on the editor's body during formatted text sessions. CKEditor's own
// badge would be positioned for this document, not the preview.
const RICH_SESSION_ATTRIBUTE = 'data-canvas-inline-rich-text';

const tipSeen = () => {
  try {
    return localStorage.getItem(TIP_STORAGE_KEY) === '1';
  } catch {
    return true;
  }
};
const markTipSeen = () => {
  try {
    localStorage.setItem(TIP_STORAGE_KEY, '1');
  } catch {
    // Without storage the tip shows once per page load.
  }
};

/** Component instances in page order. */
function componentsInOrder(regions: RegionNode[]): string[] {
  const order: string[] = [];
  const walk = (components: ComponentNode[]) =>
    components.forEach((component) => {
      order.push(component.uuid);
      component.slots.forEach((slot) => walk(slot.components));
    });
  regions.forEach((region) => walk(region.components));
  return order;
}

// Injected into the preview document. Editing chrome lives in the parent
// document; only the focus ring, caret and empty-value placeholder have to be
// drawn next to the text itself.
const IFRAME_STYLES = `
[data-canvas-inline-editing] {
  outline: 2px solid rgb(0 144 255 / 0.55) !important;
  outline-offset: 3px !important;
  border-radius: 1px;
  cursor: text;
  caret-color: currentColor;
  -webkit-user-modify: read-write;
  user-select: text;
}
[data-canvas-inline-empty-hint]::before {
  content: attr(data-canvas-inline-placeholder);
  opacity: 0.45;
  font-style: italic;
  pointer-events: none;
}
[data-canvas-inline-editing][data-canvas-inline-empty]::before {
  content: attr(data-canvas-inline-placeholder);
  opacity: 0.45;
  pointer-events: none;
}
/* Rich text keeps an empty paragraph for the caret; overlay the placeholder. */
[data-canvas-inline-editing^="rich"][data-canvas-inline-empty]::before {
  position: absolute;
  inset-inline-start: 0;
}
/* Themes often style links like text; while editing, show where they are. */
[data-canvas-inline-editing] a {
  cursor: text;
  text-decoration: underline dotted !important;
}
`;

/** Adds the inline editing styles to a preview document, once. */
export function ensureInlineEditingStyles(doc: Document) {
  if (!doc.getElementById(STYLE_ID)) {
    const style = doc.createElement('style');
    style.id = STYLE_ID;
    style.textContent = IFRAME_STYLES;
    doc.head.append(style);
  }
}

/** Returns the preview iframe currently shown to the author. */
export const getActivePreviewIframe = (): HTMLIFrameElement | null =>
  document.querySelector<HTMLIFrameElement>(
    'iframe[data-canvas-preview][data-canvas-swap-active="true"]',
  );

function usePropSources(component: ComponentNode | null) {
  const { data: components } = useGetComponentsQuery();
  return (
    components?.[component?.type.split('@')[0] ?? ''] as
      PropSourceComponent | undefined
  )?.propSources;
}

/**
 * Lists the props of a component instance that can be edited in place, and
 * resolves which of them is under a pointer.
 */
export function useInlineEditableProps(component: ComponentNode) {
  const propSources = usePropSources(component);
  // Only this instance's model, so overlays do not re-render for every edit.
  const model = useAppSelector((state) => selectModel(state)[component.uuid]);

  const list = useCallback((): {
    props: InlineEditableProp[];
    iframe: HTMLIFrameElement | null;
  } => {
    const iframe = getActivePreviewIframe();
    const doc = iframe?.contentDocument;
    if (!iframe || !doc) {
      return { props: [], iframe: null };
    }
    return {
      props: findInlineEditableProps(doc, component.uuid, propSources, model),
      iframe,
    };
  }, [component.uuid, model, propSources]);

  /** Finds the editable prop under a point in the parent document. */
  const propAt = useCallback(
    (clientX: number, clientY: number) => {
      const { props, iframe } = list();
      if (!iframe?.contentDocument || !props.length) {
        return null;
      }
      const point = toIframePoint(iframe, clientX, clientY);
      const prop = findInlineEditablePropAtPoint(props, point.x, point.y);
      return prop ? { prop, point } : null;
    },
    [list],
  );

  return { list, propAt };
}

interface ActiveSession {
  componentUuid: string;
  prop: InlineEditableProp;
  label: string;
  host: InlineEditingHost;
  iframe: HTMLIFrameElement;
  /** The entity edited, so a commit after navigating away is refused. */
  target: string;
  /** Resolves to CKEditor for rich text, or null. */
  editorPromise: Promise<Editor | null> | null;
  editor: Editor | null;
  /** Reads the value in the prop's stored shape. */
  read: () => string;
  initialValue: string;
  composing: boolean;
  /** Removes the "editing on canvas" mark from the sidebar field. */
  unmarkSidebar: () => void;
  /** Shows the typed text in the sidebar's plain text field, if any. */
  mirror: ReturnType<typeof mirrorSidebarText>;
  /** The component's link prop, for button and link labels. */
  linkProp: string | null;
  /** Editable props of the component in page order, from the start. */
  siblings: string[];
  /** Settles once the editing host is back to a plain element. */
  teardown: Promise<void>;
  /** Text of other components around the edited text, for the toolbar. */
  nearbyText: DOMRect[];
  /** The prop's maximum length, from its schema. */
  maxLength?: number;
  cleanup: () => void;
}

interface UnsavedEdit {
  target: string;
  label: string;
  value: string;
  componentUuid: string;
  propName: string;
  loadId: string;
}

const readUnsaved = (): UnsavedEdit[] => {
  try {
    return JSON.parse(sessionStorage.getItem(UNSAVED_STORAGE_KEY) ?? '[]');
  } catch {
    return [];
  }
};
const writeUnsaved = (edits: UnsavedEdit[]) => {
  try {
    sessionStorage.setItem(UNSAVED_STORAGE_KEY, JSON.stringify(edits));
  } catch {
    // Storage may be unavailable; the notice still offers the text.
  }
};

/** Copies a value, as formatted text when it is HTML. */
async function copyValue(value: string, rich: boolean) {
  const text = rich
    ? (new DOMParser().parseFromString(value, 'text/html').body.textContent ??
      '')
    : value;
  try {
    if (rich && 'ClipboardItem' in window) {
      await navigator.clipboard.write([
        new ClipboardItem({
          'text/html': new Blob([value], { type: 'text/html' }),
          'text/plain': new Blob([text], { type: 'text/plain' }),
        }),
      ]);
      return;
    }
    await navigator.clipboard.writeText(text);
  } catch {
    // Clipboard access can be refused; there is nothing else to do here.
  }
}

/** Props of a component that can be edited in place, in document order. */
function editablePropsInOrder(
  doc: Document,
  componentUuid: string,
  propSources: Record<string, FieldDataItem> | undefined,
  model: ComponentModel | undefined,
) {
  return [
    ...findInlineEditableProps(doc, componentUuid, propSources, model),
  ].sort((a, b) =>
    targetRange(a.target).compareBoundaryPoints(
      Range.START_TO_START,
      targetRange(b.target),
    ),
  );
}

/**
 * Runs text-edit sessions: turns a prop's preview DOM into an editor, and
 * writes the value to the layout model once, when the session ends.
 *
 * @see docs/react-codebase/inline-text-editing.md
 */
const InlineTextEditing: React.FC = () => {
  const dispatch = useAppDispatch();
  const session = useAppSelector(selectTextEditSession);
  const model = useAppSelector(selectModel);
  const layout = useAppSelector(selectLayout);
  const { data: components } = useGetComponentsQuery();
  const activeRef = useRef<ActiveSession | null>(null);
  const toolbarRef = useRef<HTMLDivElement | null>(null);
  const [active, setActive] = useState<ActiveSession | null>(null);
  const [announcement, setAnnouncement] = useState('');
  const [notices, setNotices] = useState<InlineTextNotice[]>([]);
  // Latest values for the session's event listeners, which outlive renders.
  const modelRef = useRef(model);
  modelRef.current = model;
  const layoutRef = useRef(layout);
  layoutRef.current = layout;
  const componentsRef = useRef(components);
  componentsRef.current = components;
  const selectedUuid = useAppSelector(selectSelectedComponentUuid);
  const previewLoading = useAppSelector(selectPreviewLoading);
  const tipShown = useRef(false);

  const notify = useCallback((notice: Omit<InlineTextNotice, 'id'>) => {
    setNotices((current) => [
      ...current.filter((other) => other.message !== notice.message),
      { ...notice, id: uuidv4() },
    ]);
  }, []);
  const dismiss = useCallback((id: string) => {
    setNotices((current) => current.filter((notice) => notice.id !== id));
  }, []);

  /** Offers an edit that could not be saved, so it is never silently lost. */
  const offerUnsaved = useCallback(
    (
      edit: UnsavedEdit,
      rich: boolean,
      message: string,
      retry?: () => void,
      restore?: () => void,
    ) => {
      const forget = () =>
        writeUnsaved(
          readUnsaved().filter(
            (other) =>
              other.value !== edit.value || other.target !== edit.target,
          ),
        );
      notify({
        tone: 'error',
        message,
        actions: [
          ...(restore
            ? [
                {
                  label: 'Restore',
                  onClick: () => {
                    forget();
                    restore();
                  },
                },
              ]
            : []),
          ...(retry
            ? [
                {
                  label: 'Try again',
                  onClick: () => {
                    forget();
                    retry();
                  },
                },
              ]
            : []),
          {
            label: 'Copy text',
            onClick: () => {
              copyValue(edit.value, rich);
              forget();
            },
          },
          { label: 'Discard', onClick: forget },
        ],
      });
    },
    [notify],
  );

  // Edits that were not confirmed before a reload, such as on a save
  // conflict or when the network dropped. Restore saves them again.
  useEffect(() => {
    const target = currentEditTarget();
    readUnsaved()
      .filter((edit) => edit.target === target && edit.loadId !== LOAD_ID)
      .forEach((edit) => {
        const rich = /<[a-z]/i.test(edit.value);
        offerUnsaved(
          edit,
          rich,
          `Your edit to ${edit.label} was not saved.`,
          undefined,
          edit.componentUuid
            ? () =>
                dispatch(
                  commitInlineText(
                    edit.componentUuid,
                    { [edit.propName]: edit.value },
                    target,
                  ),
                ).then((result) => {
                  if (result.ok) {
                    notify({
                      tone: 'info',
                      message: `Your edit to ${edit.label} was restored.`,
                      timeout: 4000,
                    });
                  } else {
                    writeUnsaved([...readUnsaved(), edit]);
                    offerUnsaved(
                      edit,
                      rich,
                      `Your edit to ${edit.label} could not be restored.`,
                    );
                  }
                })
            : undefined,
        );
      });
  }, [dispatch, notify, offerUnsaved]);

  const commit = useCallback(
    (current: ActiveSession, value: string) => {
      const { prop, host, componentUuid, label, target } = current;
      // Kept until the server confirms, so an edit survives even a crash of
      // the editor, such as when the network drops.
      const edit: UnsavedEdit = {
        target,
        label,
        value,
        componentUuid,
        propName: prop.propName,
        loadId: LOAD_ID,
      };
      writeUnsaved([...readUnsaved(), edit]);
      const forget = () =>
        writeUnsaved(
          readUnsaved().filter(
            (other) => other.target !== target || other.value !== value,
          ),
        );
      dispatch(
        commitInlineText(componentUuid, { [prop.propName]: value }, target),
      ).then(async (result) => {
        // Never undo the session's own teardown with a revert, or the reverse.
        await current.teardown;
        if (result.ok) {
          forget();
          if (!value && !prop.required) {
            // Undo only this removal: once anything else is done, it is no
            // longer the latest change.
            const removal = dispatch((_, getState) =>
              selectUndoItem(getState()),
            );
            notify({
              tone: 'info',
              message: `${label} removed.`,
              actions: [
                {
                  label: 'Undo',
                  onClick: () =>
                    dispatch((next, getState) => {
                      const state = getState();
                      if (
                        selectUndoItem(state) === removal &&
                        !selectIsInlineTextBusy(state)
                      ) {
                        next(UndoRedoActionCreators.undo('layoutModel'));
                      } else {
                        notify({
                          tone: 'info',
                          message: `The page changed since, so ${label} cannot be restored from here. Use Undo in the top bar.`,
                          timeout: 6000,
                        });
                      }
                    }),
                },
              ],
              timeout: 10000,
            });
          }
          return;
        }
        current.mirror?.remove();
        // Show what the server has again, and keep the text on offer, unless
        // the author is already editing that text again.
        const editing = activeRef.current?.host.element;
        if (
          !editing ||
          (editing !== host.element &&
            !editing.contains(host.element) &&
            !host.element.contains(editing))
        ) {
          host.revert();
          host.unwrap();
        }
        if (result.reason === 'navigated') {
          offerUnsaved(
            edit,
            isRich(prop.kind),
            `Your edit to ${label} was not saved because the page changed.`,
          );
          return;
        }
        if (result.status === 409) {
          offerUnsaved(
            edit,
            isRich(prop.kind),
            `This page was changed elsewhere, so your edit to ${label} was not saved.`,
          );
          return;
        }
        offerUnsaved(
          edit,
          isRich(prop.kind),
          `Your edit to ${label} could not be saved.`,
          () => commit(current, value),
        );
      });
    },
    [dispatch, notify, offerUnsaved],
  );

  /**
   * Ends the active session. Unless `discard` is set, a changed value is
   * committed as one model update, so one undo entry.
   */
  const finish = useCallback(
    (options: { discard?: boolean; focusOverlay?: boolean } = {}) => {
      const current = activeRef.current;
      if (!current) {
        return;
      }
      activeRef.current = null;
      setActive(null);
      const { prop, host, componentUuid, initialValue, label } = current;
      const value = current.read();
      // A required prop left empty keeps its previous value, rather than
      // collapsing the component to nothing that can be clicked again.
      const keepsPrevious = prop.required && !value && !!initialValue;
      const keep = !options.discard && !keepsPrevious && value !== initialValue;
      current.cleanup();
      current.unmarkSidebar();
      if (!keep) {
        current.mirror?.remove();
      }
      const restore = () => {
        if (!keep) {
          host.revert();
        }
        host.release();
        if (!keep) {
          host.unwrap();
        }
      };
      current.teardown = current.editorPromise
        ? current.editorPromise
            .then((editor) =>
              editor
                ? keep
                  ? destroyRichTextEditor(editor, host.element)
                  : editor.destroy()
                : undefined,
            )
            .catch(() => undefined)
            .finally(restore)
            .then(() => undefined)
        : Promise.resolve(restore());
      dispatch(endTextEditSession());
      setAnnouncement('');
      if (!keep) {
        // A commit made while this session ran may have deferred its form
        // refresh; with nothing to commit now, run it.
        dispatch(runDeferredFormRefresh());
      }
      if (keepsPrevious) {
        notify({
          tone: 'info',
          message: `${label} is required, so its previous text was kept.`,
          timeout: 5000,
        });
      }
      if (keep) {
        commit(current, value);
      }
      if (options.focusOverlay) {
        document
          .querySelector<HTMLElement>(
            `.componentOverlay[data-canvas-drop-item="${componentUuid}"]`,
          )
          ?.focus({ preventScroll: true });
      }
    },
    [commit, dispatch, notify],
  );

  // Start a session when one is requested.
  useEffect(() => {
    if (!session) {
      return;
    }
    if (
      activeRef.current?.componentUuid === session.componentUuid &&
      activeRef.current.prop.propName === session.propName
    ) {
      return;
    }
    // A request for another prop ends the current session first.
    if (activeRef.current) {
      // Re-request with a new object: after `finish()` clears the session,
      // the same reference would not register as a change.
      const next = { ...session };
      finish();
      dispatch(startTextEditSession(next));
      return;
    }
    const iframe = getActivePreviewIframe();
    // A newer preview document is still loading, such as right after a
    // commit: editing the current one would end as soon as it is swapped
    // out. This effect runs again once it is in.
    if (previewLoading) {
      return;
    }
    const doc = iframe?.contentDocument;
    const node = findComponentByUuid(layout, session.componentUuid);
    const componentModel = model[session.componentUuid];
    const propSources = (
      components?.[node?.type.split('@')[0] ?? ''] as
        PropSourceComponent | undefined
    )?.propSources;
    const prop =
      iframe && doc && node
        ? findInlineEditableProps(
            doc,
            session.componentUuid,
            propSources,
            componentModel,
          ).find((candidate) => candidate.propName === session.propName)
        : undefined;
    if (!iframe || !doc || !prop) {
      dispatch(endTextEditSession());
      return;
    }
    const { componentUuid } = session;

    // Page order of the component's texts, measured before editing takes this
    // prop's markers out of the document.
    const siblings = editablePropsInOrder(
      doc,
      componentUuid,
      propSources,
      componentModel,
    ).map((candidate) => candidate.propName);
    const host = createInlineEditingHost(prop);
    const label =
      getSidebarLabel(componentUuid, prop.propName) ??
      humanizePropName(prop.propName);
    host.element.setAttribute('aria-label', label);
    host.element.setAttribute('data-canvas-inline-placeholder', label);
    ensureInlineEditingStyles(doc);

    const listeners: Array<[EventTarget, string, EventListener, boolean?]> = [];
    const listen = <E extends Event>(
      target: EventTarget,
      type: string,
      listener: (event: E) => void,
      capture?: boolean,
    ) => {
      target.addEventListener(type, listener as EventListener, capture);
      listeners.push([target, type, listener as EventListener, capture]);
    };

    const plainKind = isRich(prop.kind)
      ? null
      : (prop.kind as 'plain' | 'multiline');
    const linkProp =
      Object.entries(propSources ?? {}).find(
        ([, source]) => source.sourceType === 'static:field_item:link',
      )?.[0] ?? null;
    const current: ActiveSession = {
      componentUuid,
      prop,
      label,
      host,
      iframe,
      target: currentEditTarget(),
      editorPromise: null,
      editor: null,
      read: () =>
        plainKind
          ? readPlainValue(host.element, plainKind, prop)
          : current.editor
            ? getRichTextValue(current.editor, prop.kind === 'richInline')
            : // Not ready yet: nothing can have been typed.
              current.initialValue,
      initialValue: '',
      composing: false,
      unmarkSidebar: () => {},
      mirror: null,
      // Only text that is the whole label of a link, such as a button's, offers
      // the link. A heading in a linked card is not the link's label.
      linkProp:
        plainKind &&
        host.element.closest('a')?.textContent?.trim() ===
          host.element.textContent?.trim()
          ? linkProp
          : null,
      siblings,
      teardown: Promise.resolve(),
      nearbyText: [],
      maxLength: (propSources?.[prop.propName]?.jsonSchema as any)?.maxLength,
      cleanup: () => {
        listeners.forEach(([target, type, listener, capture]) =>
          target.removeEventListener(type, listener, capture),
        );
        swapObserver.disconnect();
        document.body.removeAttribute(RICH_SESSION_ATTRIBUTE);
      },
    };

    const updateEmpty = () =>
      host.element.toggleAttribute('data-canvas-inline-empty', !current.read());
    // Text of other components near the edited text, which the toolbar keeps
    // clear of. Measured again as typing reflows the page.
    const measureNearbyText = () => {
      const hostRect = host.element.getBoundingClientRect();
      const top = hostRect.top - 120;
      const bottom = hostRect.bottom + 120;
      const walker = doc.createTreeWalker(doc.body, NodeFilter.SHOW_TEXT);
      const rects: DOMRect[] = [];
      for (let node = walker.nextNode(); node; node = walker.nextNode()) {
        const parent = node.parentElement;
        if (
          !node.textContent?.trim() ||
          !parent ||
          host.element.contains(parent)
        ) {
          continue;
        }
        const rect = parent.getBoundingClientRect();
        if (rect.bottom >= top && rect.top <= bottom && rect.width) {
          rects.push(rect);
        }
      }
      current.nearbyText = rects;
    };
    measureNearbyText();
    const onInput = () => {
      updateEmpty();
      current.mirror?.update(current.read());
      measureNearbyText();
    };
    if (plainKind) {
      current.initialValue = current.read();
      updateEmpty();
    }
    listen(host.element, 'input', onInput);

    // End the session when focus leaves both the text and the toolbar, but
    // not when the whole window loses focus: switching apps or tabs must not
    // close the editor.
    const endIfFocusLeft = () => {
      setTimeout(() => {
        if (activeRef.current !== current || !document.hasFocus()) {
          return;
        }
        const focusInText =
          document.activeElement === iframe &&
          doc.activeElement === host.element;
        const focusInToolbar = !!toolbarRef.current?.contains(
          document.activeElement,
        );
        if (!focusInText && !focusInToolbar && !current.composing) {
          finish();
        }
      }, 0);
    };
    listen(host.element, 'blur', endIfFocusLeft);
    listen(document, 'focusout', endIfFocusLeft, true);

    // Never read or end a session mid-composition: that would capture
    // provisional characters and can cancel the input method.
    listen(host.element, 'compositionstart', () => {
      current.composing = true;
    });
    listen(host.element, 'compositionend', () => {
      current.composing = false;
      endIfFocusLeft();
    });

    /**
     * Moves the session to the previous or next text: of this component first,
     * then of the other components, in page order.
     */
    const moveToSibling = (step: 1 | -1) => {
      const next =
        current.siblings[current.siblings.indexOf(prop.propName) + step];
      if (next) {
        dispatch(startTextEditSession({ componentUuid, propName: next }));
        return;
      }
      const order = componentsInOrder(layoutRef.current);
      for (
        let index = order.indexOf(componentUuid) + step;
        index >= 0 && index < order.length;
        index += step
      ) {
        const uuid = order[index];
        const node = findComponentByUuid(layoutRef.current, uuid);
        const editable = editablePropsInOrder(
          doc,
          uuid,
          (
            componentsRef.current?.[node?.type.split('@')[0] ?? ''] as
              PropSourceComponent | undefined
          )?.propSources,
          modelRef.current[uuid],
        );
        const first = step > 0 ? editable[0] : editable[editable.length - 1];
        if (first) {
          finish();
          (
            getCanvasSettings() as any
          )?.componentSelectionUtils?.setSelectedComponent(uuid);
          dispatch(
            startTextEditSession({
              componentUuid: uuid,
              propName: first.propName,
            }),
          );
          return;
        }
      }
      finish({ focusOverlay: true });
    };

    listen<KeyboardEvent>(host.element, 'keydown', (event) => {
      if (event.isComposing || current.composing) {
        return;
      }
      const mod = event.metaKey || event.ctrlKey;
      const key = event.key.toLowerCase();
      if (event.key === 'Escape') {
        event.preventDefault();
        finish({ focusOverlay: true });
        return;
      }
      if (event.key === 'Tab' && !mod && !event.altKey) {
        event.preventDefault();
        moveToSibling(event.shiftKey ? -1 : 1);
        return;
      }
      if (event.key === 'Enter') {
        const commits =
          prop.kind === 'plain' ||
          (prop.kind === 'richInline' && !event.shiftKey) ||
          mod;
        if (commits) {
          event.preventDefault();
          finish({ focusOverlay: true });
          return;
        }
        if (prop.kind === 'richInline') {
          // The inline format has no line breaks.
          event.preventDefault();
        }
        return;
      }
      // macOS gives Home and End to scrolling. In text being edited, authors
      // expect them to move the caret, as they do on other platforms.
      if ((event.key === 'Home' || event.key === 'End') && !event.altKey) {
        event.preventDefault();
        doc
          .getSelection()
          ?.modify(
            event.shiftKey ? 'extend' : 'move',
            event.key === 'End' ? 'forward' : 'backward',
            mod ? 'documentboundary' : 'lineboundary',
          );
        return;
      }
      // Plain text undo is the browser's; run it explicitly so it also works
      // where the shortcut does not reach the editing host.
      if (plainKind && mod && key === 'z') {
        event.preventDefault();
        doc.execCommand(event.shiftKey ? 'redo' : 'undo');
        onInput();
        return;
      }
      if (prop.format && mod && key === 'k') {
        event.preventDefault();
        toolbarRef.current?.dispatchEvent(new Event('canvas:edit-link'));
        return;
      }
      if (event.altKey && event.key === 'F10') {
        event.preventDefault();
        toolbarRef.current
          ?.querySelector<HTMLElement>('button:not(:disabled)')
          ?.focus();
      }
    });

    // Escape ends the session from the editor's own document too, for when
    // focus is there rather than in the text. The toolbar handles its own keys
    // and does not let them through.
    listen<KeyboardEvent>(document, 'keydown', (event) => {
      if (event.key === 'Escape' && !event.defaultPrevented) {
        event.preventDefault();
        finish({ focusOverlay: true });
      }
    });

    // Rich text pastes through CKEditor, which keeps only what the format
    // allows. `plaintext-only` keeps line breaks, which a one-line prop cannot.
    listen<ClipboardEvent>(host.element, 'paste', (event) => {
      if (prop.kind !== 'plain' || !event.clipboardData) {
        return;
      }
      event.preventDefault();
      const text = event.clipboardData.getData('text/plain');
      doc.execCommand('insertText', false, text.replace(/\s*\n\s*/g, ' '));
    });

    // The preview takes pointer events while editing; links must not navigate
    // it, including a link around the text being edited.
    listen<MouseEvent>(
      doc,
      'click',
      (event) => {
        if ((event.target as Element | null)?.closest?.('a[href]')) {
          event.preventDefault();
        }
      },
      true,
    );
    listen<SubmitEvent>(doc, 'submit', (event) => event.preventDefault(), true);

    // Clicking another editable prop of the same component moves the session
    // there directly; the component's overlay is out of the way while editing.
    listen<MouseEvent>(doc, 'mousedown', (event) => {
      if (host.element.contains(event.target as Node)) {
        return;
      }
      const editable = findInlineEditableProps(
        doc,
        componentUuid,
        propSources,
        modelRef.current[componentUuid],
      ).filter((candidate) => candidate.propName !== prop.propName);
      const next = findInlineEditablePropAtPoint(
        editable,
        event.clientX,
        event.clientY,
      );
      if (next) {
        event.preventDefault();
        dispatch(
          startTextEditSession({
            componentUuid,
            propName: next.propName,
            point: { x: event.clientX, y: event.clientY },
          }),
        );
      }
    });

    // A preview swap that was already in flight when the session started
    // replaces the document being edited. Commit what was typed.
    const swapObserver = new MutationObserver(() => {
      if (iframe.dataset.canvasSwapActive !== 'true') {
        finish();
      }
    });
    swapObserver.observe(iframe, {
      attributes: true,
      attributeFilter: ['data-canvas-swap-active'],
    });

    listen<BeforeUnloadEvent>(window, 'beforeunload', (event) => {
      if (current.read() !== current.initialValue) {
        event.preventDefault();
      }
    });

    activeRef.current = current;
    setActive(current);
    const point = session.point;
    // The sidebar form of a component just selected, such as by the first
    // click of a double-click or by Tab, may still be loading. Take its label
    // and mark its field as soon as it is there.
    const connectSidebar = () => {
      const sidebarLabel = getSidebarLabel(componentUuid, prop.propName);
      if (!sidebarLabel) {
        return false;
      }
      current.unmarkSidebar = markSidebarFieldEditing(
        componentUuid,
        prop.propName,
      );
      if (plainKind) {
        current.mirror = mirrorSidebarText(componentUuid, prop.propName);
        current.mirror?.update(current.read());
      }
      if (sidebarLabel !== current.label) {
        current.label = sidebarLabel;
        host.element.setAttribute('aria-label', sidebarLabel);
        host.element.setAttribute(
          'data-canvas-inline-placeholder',
          sidebarLabel,
        );
        setActive({ ...current });
      }
      return true;
    };
    if (!connectSidebar()) {
      const started = Date.now();
      const poll = () => {
        if (activeRef.current === current && Date.now() - started < 4000) {
          if (!connectSidebar()) {
            setTimeout(poll, 100);
          }
        }
      };
      setTimeout(poll, 100);
    }
    // Using the feature is the best sign the tip has done its job.
    markTipSeen();
    // Editing the text again also retires its "removed, Undo" notice.
    setNotices((shown) =>
      shown.filter(
        (notice) =>
          notice.message !== TIP_MESSAGE &&
          notice.message !== `${label} removed.`,
      ),
    );

    if (plainKind) {
      // Focus the preview's window first: Firefox does not move keyboard focus
      // into a frame when only an element inside it is focused.
      iframe.contentWindow?.focus();
      host.element.focus({ preventScroll: true });
      placeCaret(doc, host.element, point, session.selectWord);
      if (!point) {
        // Reached by keyboard, such as Tab: bring it into view.
        host.element.scrollIntoView({ block: 'nearest' });
      }
      if (session.applyKey) {
        doc.execCommand(
          session.applyKey === 'Backspace' ? 'delete' : 'forwardDelete',
        );
        onInput();
      }
    } else {
      document.body.setAttribute(RICH_SESSION_ATTRIBUTE, '');
      const source = (componentModel as any)?.source?.[prop.propName]?.value;
      const resolved = componentModel?.resolved[prop.propName];
      // A commit of this prop that is still in flight is newer than the model.
      const initialData =
        getPendingInlineValue(componentUuid, prop.propName) ??
        (typeof source === 'object' ? source?.value : source) ??
        (typeof resolved === 'string' ? resolved : '');
      current.editorPromise = createRichTextEditor(
        host.element,
        prop.format!,
        initialData,
      )
        .then((editor) => {
          // A session that already ended destroys the editor in finish().
          if (activeRef.current !== current) {
            return editor;
          }
          current.editor = editor;
          current.initialValue = current.read();
          updateEmpty();
          editor.model.document.on('change:data', onInput);
          iframe.contentWindow?.focus();
          editor.editing.view.focus();
          placeCaret(doc, host.element, point, session.selectWord);
          if (!point) {
            host.element.scrollIntoView({ block: 'nearest' });
          }
          if (session.applyKey) {
            editor.execute(
              session.applyKey === 'Backspace' ? 'delete' : 'deleteForward',
            );
          }
          // Re-render with the editor, so the toolbar can bind to it.
          setActive({ ...current });
          return editor;
        })
        .catch((error) => {
          if (activeRef.current === current) {
            finish({ discard: true });
          }
          if (error instanceof UnsupportedFormatError) {
            notify({
              tone: 'info',
              message: `${label} can only be edited in the panel.`,
              timeout: 6000,
            });
            focusSidebarField(componentUuid, prop.propName);
          } else {
            console.error('Inline rich text editing failed to start.', error);
          }
          return null;
        });
    }
    setAnnouncement(
      `Editing ${label}. Escape saves and exits. Tab moves to the next text.${
        prop.format ? ' Alt+F10 opens formatting.' : ''
      }`,
    );
  }, [
    components,
    dispatch,
    finish,
    layout,
    model,
    notify,
    previewLoading,
    session,
  ]);

  // A requested session that cannot start, because the preview keeps
  // loading, is dropped rather than started later out of the blue.
  useEffect(() => {
    if (!session || activeRef.current || !previewLoading) {
      return;
    }
    const timer = setTimeout(() => {
      if (!activeRef.current) {
        dispatch(endTextEditSession());
      }
    }, 3000);
    return () => clearTimeout(timer);
  }, [dispatch, previewLoading, session]);

  // End the session if its component disappears, such as after a delete from
  // the layers panel. The DOM it was anchored to is gone.
  useEffect(() => {
    if (activeRef.current && !model[activeRef.current.componentUuid]) {
      finish({ discard: true });
    }
  }, [finish, model]);

  useEffect(() => () => finish(), [finish]);

  // Once, on the first selection of a component whose text can be edited in
  // place, say how.
  useEffect(() => {
    if (!selectedUuid || tipShown.current || tipSeen()) {
      return;
    }
    const doc = getActivePreviewIframe()?.contentDocument;
    const node = findComponentByUuid(layout, selectedUuid);
    const propSources = (
      components?.[node?.type.split('@')[0] ?? ''] as
        PropSourceComponent | undefined
    )?.propSources;
    if (
      doc &&
      findInlineEditableProps(
        doc,
        selectedUuid,
        propSources,
        model[selectedUuid],
      ).length
    ) {
      tipShown.current = true;
      notify({
        tone: 'info',
        message: TIP_MESSAGE,
        actions: [{ label: 'Got it', onClick: markTipSeen }],
      });
    }
  }, [components, layout, model, notify, selectedUuid]);

  const avoid = useCallback(() => {
    const current = activeRef.current;
    const doc = current?.iframe.contentDocument;
    if (!current || !doc) {
      return [];
    }
    const node = findComponentByUuid(layout, current.componentUuid);
    const propSources = (
      components?.[node?.type.split('@')[0] ?? ''] as
        PropSourceComponent | undefined
    )?.propSources;
    return findInlineEditableProps(
      doc,
      current.componentUuid,
      propSources,
      modelRef.current[current.componentUuid],
    )
      .filter((other) => other.propName !== current.prop.propName)
      .flatMap((other) =>
        Array.from(targetRange(other.target).getClientRects()),
      )
      .concat(current.nearbyText)
      .concat(
        // Media of the component, such as a card's icon.
        Array.from(
          doc.querySelectorAll(
            `[data-canvas-uuid="${current.componentUuid}"]:is(img, svg, picture, video)`,
          ),
        ).map((media) => media.getBoundingClientRect()),
      );
  }, [components, layout]);

  const componentLink = active?.linkProp
    ? (model[active.componentUuid]?.resolved[active.linkProp] as unknown)
    : undefined;
  const componentLinkUrl =
    typeof componentLink === 'string'
      ? componentLink
      : ((componentLink as { uri?: string } | undefined)?.uri ?? '');
  // Plain text gets a toolbar only when it has something to show: a length
  // limit, or the link of a button or link being relabeled.
  const showToolbar =
    active &&
    (isRich(active.prop.kind)
      ? !!active.editor
      : !!componentLinkUrl || active.maxLength !== undefined);
  return (
    <>
      <div className="visually-hidden" role="status" aria-live="polite">
        {announcement}
      </div>
      {showToolbar && (
        <InlineTextToolbar
          ref={toolbarRef}
          editor={active.editor}
          host={active.host.element}
          iframe={active.iframe}
          label={active.label}
          items={
            active.prop.format ? getFormatToolbarItems(active.prop.format) : []
          }
          avoid={avoid}
          maxLength={active.maxLength}
          componentLinkUrl={componentLinkUrl}
          onEditComponentLink={
            active.linkProp && componentLinkUrl
              ? () => {
                  const { componentUuid } = active;
                  const linkProp = active.linkProp!;
                  finish();
                  focusSidebarField(componentUuid, linkProp);
                }
              : undefined
          }
        />
      )}
      <InlineTextFeedback notices={notices} onDismiss={dismiss} />
    </>
  );
};

/** Places the caret at a point, or at the end of the text. */
function placeCaret(
  doc: Document,
  element: HTMLElement,
  point?: { x: number; y: number },
  selectWord = false,
) {
  const selection = doc.getSelection();
  if (!selection) {
    return;
  }
  const range = doc.createRange();
  const caret = point
    ? caretInElementFromPoint(element, point.x, point.y)
    : null;
  if (caret) {
    range.setStart(caret.node, caret.offset);
    range.collapse(true);
    const text =
      caret.node.nodeType === Node.TEXT_NODE ? caret.node.textContent! : '';
    if (selectWord && text) {
      const isWord = (char: string | undefined) =>
        !!char && /[\p{L}\p{N}_'’-]/u.test(char);
      let start = caret.offset;
      let end = caret.offset;
      while (start > 0 && isWord(text[start - 1])) {
        start -= 1;
      }
      while (end < text.length && isWord(text[end])) {
        end += 1;
      }
      if (end > start) {
        range.setStart(caret.node, start);
        range.setEnd(caret.node, end);
      }
    }
  } else {
    range.selectNodeContents(element);
    range.collapse(false);
  }
  selection.removeAllRanges();
  selection.addRange(range);
}

export default InlineTextEditing;
