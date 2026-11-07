import {
  forwardRef,
  useCallback,
  useDeferredValue,
  useEffect,
  useImperativeHandle,
  useLayoutEffect,
  useRef,
  useState,
} from 'react';
import clsx from 'clsx';
import ReactDOM from 'react-dom';
import {
  CodeIcon,
  FontBoldIcon,
  FontItalicIcon,
  Link2Icon,
  LinkBreak2Icon,
  ListBulletIcon,
  QuoteIcon,
  StrikethroughIcon,
  UnderlineIcon,
} from '@radix-ui/react-icons';

import {
  isSafeHref,
  normalizeLinkInput,
} from '@/features/layout/previewOverlay/inlineText/inlineTextEditing';
import { useGetContentListQuery } from '@/services/content';

import type { Editor } from '@ckeditor/ckeditor5-core';

import styles from './InlineTextToolbar.module.css';

const NumberedListIcon = () => (
  <svg width="15" height="15" viewBox="0 0 15 15" aria-hidden="true">
    <path
      d="M6 4h7M6 7.5h7M6 11h7"
      stroke="currentColor"
      strokeLinecap="round"
    />
    <text x="1" y="5.6" fontSize="4.6" fill="currentColor">
      1
    </text>
    <text x="1" y="9.1" fontSize="4.6" fill="currentColor">
      2
    </text>
    <text x="1" y="12.6" fontSize="4.6" fill="currentColor">
      3
    </text>
  </svg>
);

const isMac = /Mac|iPhone|iPad/.test(navigator.platform);
const mod = isMac ? '⌘' : 'Ctrl+';

// CKEditor 5 toolbar items Canvas can offer, keyed by item name, which is
// also the command name. Other items, such as dropdowns that need a value
// (`heading`, `alignment`), are left out: the sidebar still has them.
const ITEMS: Record<
  string,
  { label: string; icon: React.ReactNode; shortcut?: string }
> = {
  bold: { label: 'Bold', icon: <FontBoldIcon />, shortcut: `${mod}B` },
  italic: { label: 'Italic', icon: <FontItalicIcon />, shortcut: `${mod}I` },
  underline: {
    label: 'Underline',
    icon: <UnderlineIcon />,
    shortcut: `${mod}U`,
  },
  strikethrough: { label: 'Strikethrough', icon: <StrikethroughIcon /> },
  code: { label: 'Code', icon: <CodeIcon /> },
  bulletedList: { label: 'Bulleted list', icon: <ListBulletIcon /> },
  numberedList: { label: 'Numbered list', icon: <NumberedListIcon /> },
  blockQuote: { label: 'Block quote', icon: <QuoteIcon /> },
};

interface InlineTextToolbarProps {
  /** The rich text editor, or null for plain text. */
  editor: Editor | null;
  host: HTMLElement;
  iframe: HTMLIFrameElement;
  /** The prop's label, as the sidebar shows it. */
  label: string;
  /** The text format's toolbar items, as configured for CKEditor 5. */
  items?: string[];
  /** Rects of the component's other text, in iframe CSS pixels, to keep clear. */
  avoid: () => DOMRect[];
  /** Opens the component's link field, for button and link labels. */
  onEditComponentLink?: () => void;
  /** The prop's maximum length, if its schema sets one. */
  maxLength?: number;
  /** The URL of the component's link prop, shown with its button. */
  componentLinkUrl?: string;
}

/**
 * Shortens a URL in the middle, so both the domain and the end, such as
 * campaign parameters, stay visible.
 */
export function shortenUrl(url: string, max = 44): string {
  if (url.length <= max) {
    return url;
  }
  const head = Math.ceil((max - 1) * 0.55);
  return `${url.slice(0, head)}…${url.slice(url.length - (max - 1 - head))}`;
}

// Titles of Canvas pages linked by their system path, such as `/page/13`.
const pageLinks = new Map<string, { title: string; path: string } | null>();

/**
 * The title and alias of a Canvas page linked by its system path, so a link
 * reads "Plans and pricing · /pricing" rather than "/page/13".
 */
function usePageLink(href: string | undefined) {
  const [, setLoaded] = useState(0);
  const id = href?.match(/^\/page\/(\d+)$/)?.[1];
  useEffect(() => {
    if (!href || !id || pageLinks.has(href)) {
      return;
    }
    pageLinks.set(href, null);
    const base = (window as any).drupalSettings?.path?.baseUrl ?? '/';
    fetch(`${base}canvas/api/v0/content/canvas_page/${id}`, {
      credentials: 'same-origin',
    })
      .then((response) => (response.ok ? response.json() : null))
      .then((page) => {
        if (page?.title) {
          pageLinks.set(href, { title: page.title, path: page.path ?? href });
          setLoaded((count) => count + 1);
        }
      })
      .catch(() => undefined);
  }, [href, id]);
  return href ? (pageLinks.get(href) ?? null) : null;
}

/** Whether link field input reads as a search, not as a URL being typed. */
const isSearchInput = (input: string) =>
  input.trim().length >= 2 &&
  !/^[a-z][a-z0-9+.-]*:|^[/#?]|\.|@/i.test(input.trim());

const GAP = 8;

const intersects = (
  a: { top: number; left: number; bottom: number; right: number },
  b: { top: number; left: number; bottom: number; right: number },
) =>
  a.left < b.right && a.right > b.left && a.top < b.bottom && a.bottom > b.top;

/**
 * Floating toolbar for text edited in place.
 *
 * Rendered in the parent document, so it never shifts the themed preview and
 * keeps its size at any zoom level. For formatted text, its buttons are the
 * text format's own CKEditor 5 toolbar items, running the editor's commands.
 * For plain text it shows the prop's label and length.
 */
const InlineTextToolbar = forwardRef<HTMLDivElement, InlineTextToolbarProps>(
  (
    {
      editor,
      host,
      iframe,
      label,
      items = [],
      avoid,
      onEditComponentLink,
      maxLength,
      componentLinkUrl,
    },
    ref,
  ) => {
    const rootRef = useRef<HTMLDivElement>(null);
    useImperativeHandle(ref, () => rootRef.current!);
    const [position, setPosition] = useState<{
      top: number;
      left: number;
    } | null>(null);
    const [, setRevision] = useState(0);
    const [linkHref, setLinkHref] = useState<string | null>(null);
    const [suggestion, setSuggestion] = useState(0);
    // Pages whose title matches what is typed in the link field.
    const search = useDeferredValue(
      linkHref !== null && isSearchInput(linkHref) ? linkHref.trim() : '',
    );
    const { data: pages } = useGetContentListQuery(
      { entityType: 'canvas_page', search },
      { skip: !search },
    );
    const suggestions = search ? (pages?.items ?? []).slice(0, 6) : [];
    const avoidRef = useRef(avoid);
    avoidRef.current = avoid;

    // Group the configured items, keeping their order and separators.
    const groups: string[][] = [[]];
    if (editor) {
      items.forEach((item) => {
        if (item === '|' || item === '-') {
          groups.push([]);
        } else if (
          (item === 'link' && editor.commands.get('link')) ||
          (ITEMS[item] && editor.commands.get(item))
        ) {
          groups[groups.length - 1].push(item);
        }
      });
    }
    const visibleGroups = groups.filter((group) => group.length);
    const firstItem = visibleGroups[0]?.[0];
    const currentHref: string | undefined = editor
      ? (editor.commands.get('link') as any)?.value
      : undefined;
    const linkedPage = usePageLink(currentHref);

    // Follow the edited text through scrolling, zooming and reflow.
    // ponytail: one rect read per frame while the toolbar is open; switch to
    // observers if this ever shows up in a profile.
    useLayoutEffect(() => {
      let frame = 0;
      // The visible part of the preview is the nearest scrolling ancestor.
      let pane: HTMLElement | null = iframe.parentElement;
      while (pane && getComputedStyle(pane).overflow === 'visible') {
        pane = pane.parentElement;
      }
      const update = () => {
        const toolbar = rootRef.current;
        if (toolbar) {
          const iframeRect = iframe.getBoundingClientRect();
          const scale = iframe.offsetWidth
            ? iframeRect.width / iframe.offsetWidth
            : 1;
          const toParent = (rect: DOMRect) => ({
            top: iframeRect.top + (rect.top + iframe.clientTop) * scale,
            bottom: iframeRect.top + (rect.bottom + iframe.clientTop) * scale,
            left: iframeRect.left + (rect.left + iframe.clientLeft) * scale,
            right: iframeRect.left + (rect.right + iframe.clientLeft) * scale,
          });
          const hostRect = toParent(host.getBoundingClientRect());
          const frameBounds = (pane ?? iframe).getBoundingClientRect();
          const height = toolbar.offsetHeight;
          const width = toolbar.offsetWidth;
          const minTop = frameBounds.top + GAP;
          const maxTop = frameBounds.bottom - height - GAP;
          const left = Math.min(
            Math.max(hostRect.left, frameBounds.left + GAP),
            frameBounds.right - width - GAP,
          );
          // Aligned with the text's right edge, for wide text whose start is
          // crowded, such as a paragraph under a short heading.
          const end = Math.max(
            Math.min(hostRect.right, frameBounds.right - GAP) - width,
            frameBounds.left + GAP,
          );
          const others = avoidRef.current().map(toParent);
          const above = hostRect.top - height - GAP;
          const below = hostRect.bottom + GAP;
          const fits = (top: number) => top >= minTop && top <= maxTop;
          const clearAt = (top: number, atLeft: number) =>
            !others.some((other) =>
              intersects(
                {
                  top,
                  bottom: top + height,
                  left: atLeft,
                  right: atLeft + width,
                },
                other,
              ),
            );
          // In order: above the text, below it, at either end, beside it, never
          // over other text. Without such a place, sit in the preview's top edge, above
          // the page, rather than over copy.
          const candidates = [
            { top: above, left },
            { top: below, left },
            { top: above, left: end },
            { top: below, left: end },
            { top: hostRect.top, left: hostRect.right + GAP },
            { top: hostRect.top, left: hostRect.left - width - GAP },
          ];
          const placed =
            candidates.find(
              (candidate) =>
                fits(candidate.top) &&
                candidate.left >= frameBounds.left + GAP &&
                candidate.left + width <= frameBounds.right - GAP &&
                clearAt(candidate.top, candidate.left),
            ) ??
            (above >= minTop
              ? { top: above, left }
              : // Centered in the preview's top edge, clear of its viewport
                // and zoom controls at the sides.
                {
                  top: frameBounds.top + 2,
                  left: frameBounds.left + (frameBounds.width - width) / 2,
                });
          const top = Math.min(
            Math.max(placed.top, frameBounds.top + 2),
            Math.max(minTop, maxTop),
          );
          setPosition((previous) =>
            previous?.top === top && previous.left === placed.left
              ? previous
              : { top, left: placed.left },
          );
        }
        frame = requestAnimationFrame(update);
      };
      update();
      return () => cancelAnimationFrame(frame);
    }, [host, iframe]);

    // Re-render when command states change, such as Bold under the caret,
    // and as plain text is typed, for its length.
    useEffect(() => {
      const refresh = () => setRevision((revision) => revision + 1);
      if (!editor) {
        host.addEventListener('input', refresh);
        return () => host.removeEventListener('input', refresh);
      }
      const { document } = editor.model;
      document.on('change', refresh);
      document.selection.on('change', refresh);
      return () => {
        document.off('change', refresh);
        document.selection.off('change', refresh);
      };
    }, [editor, host]);

    const focusEditor = () => {
      // Focus the preview's window first: Firefox does not move keyboard
      // focus into a frame when only an element inside it is focused.
      iframe.contentWindow?.focus();
      if (editor) {
        editor.editing.view.focus();
      } else {
        host.focus({ preventScroll: true });
      }
    };

    const run = (command: string) => {
      editor?.execute(command);
      focusEditor();
    };

    const openLinkEditor = useCallback(() => {
      if (editor?.commands.get('link')) {
        setLinkHref((editor.commands.get('link') as any)?.value ?? '');
      }
    }, [editor]);

    // The editor opens the link field for Mod+K.
    useEffect(() => {
      const root = rootRef.current;
      root?.addEventListener('canvas:edit-link', openLinkEditor);
      return () =>
        root?.removeEventListener('canvas:edit-link', openLinkEditor);
    }, [openLinkEditor]);

    const linkUrl = (input: string) =>
      normalizeLinkInput(
        input,
        (editor?.config.get('link.defaultProtocol') as string) || 'https://',
      );

    const applyLink = (input: string) => {
      focusEditor();
      if (!input.trim()) {
        editor?.execute('unlink');
      } else if (isSafeHref(linkUrl(input))) {
        // Without selected text, CKEditor inserts the URL as the link text.
        editor?.execute('link', linkUrl(input));
      }
      setLinkHref(null);
    };

    // Roving focus, per the WAI-ARIA toolbar pattern.
    const onToolbarKeyDown = (event: React.KeyboardEvent<HTMLDivElement>) => {
      // Keys here belong to the toolbar, not to the editor frame's shortcuts.
      event.stopPropagation();
      if (event.key === 'Escape') {
        event.preventDefault();
        setLinkHref(null);
        focusEditor();
        return;
      }
      if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) {
        return;
      }
      if ((event.target as HTMLElement).localName === 'input') {
        return;
      }
      const buttons = Array.from(
        rootRef.current?.querySelectorAll<HTMLButtonElement>(
          'button:not(:disabled)',
        ) ?? [],
      );
      const index = buttons.indexOf(event.target as HTMLButtonElement);
      const next =
        event.key === 'Home'
          ? 0
          : event.key === 'End'
            ? buttons.length - 1
            : (index + (event.key === 'ArrowRight' ? 1 : -1) + buttons.length) %
              buttons.length;
      event.preventDefault();
      buttons[next]?.focus();
    };

    // Clicking a button must not move focus out of the text: that would end
    // the session and lose the selection the command applies to.
    const keepFocus = (event: React.MouseEvent) => event.preventDefault();

    const renderItem = (item: string) => {
      if (item === 'link') {
        return [
          <button
            key="link"
            type="button"
            className={clsx(styles.button, { [styles.active]: !!currentHref })}
            aria-label={currentHref ? 'Edit link' : 'Link'}
            aria-pressed={!!currentHref}
            title={`${currentHref ? 'Edit link' : 'Link'} (${mod}K)`}
            tabIndex={item === firstItem ? 0 : -1}
            onMouseDown={keepFocus}
            onClick={openLinkEditor}
          >
            <Link2Icon />
          </button>,
          // Always present, so the toolbar keeps its width.
          <button
            key="unlink"
            type="button"
            className={styles.button}
            aria-label="Remove link"
            title="Remove link"
            disabled={!currentHref}
            tabIndex={-1}
            onMouseDown={keepFocus}
            onClick={() => run('unlink')}
          >
            <LinkBreak2Icon />
          </button>,
          currentHref && (
            <a
              key="href"
              className={styles.linkPreview}
              href={isSafeHref(currentHref) ? currentHref : undefined}
              target="_blank"
              rel="noopener noreferrer"
              title={`Open ${currentHref} in a new tab`}
              tabIndex={-1}
              onMouseDown={keepFocus}
              data-canvas-inline-link-preview=""
            >
              {linkedPage
                ? shortenUrl(`${linkedPage.title} · ${linkedPage.path}`, 36)
                : shortenUrl(currentHref, 36)}
            </a>
          ),
        ];
      }
      const command = editor?.commands.get(item) as any;
      const meta = ITEMS[item];
      const active = command?.value === true;
      return (
        <button
          key={item}
          type="button"
          className={clsx(styles.button, { [styles.active]: active })}
          aria-label={meta.label}
          aria-pressed={active}
          title={
            meta.shortcut ? `${meta.label} (${meta.shortcut})` : meta.label
          }
          disabled={!command?.isEnabled}
          tabIndex={item === firstItem ? 0 : -1}
          onMouseDown={keepFocus}
          onClick={() => run(item)}
        >
          {meta.icon}
        </button>
      );
    };

    const portalRoot = document.getElementById('canvasPreviewOverlay');
    if (!portalRoot) {
      return null;
    }

    const length = (host.textContent ?? '').trim().length;
    const linkInvalid = !!linkHref?.trim() && !isSafeHref(linkUrl(linkHref));

    return ReactDOM.createPortal(
      <div
        ref={rootRef}
        role="toolbar"
        aria-label={editor ? 'Text formatting' : `Editing ${label}`}
        aria-orientation="horizontal"
        className={styles.toolbar}
        data-canvas-inline-text-toolbar=""
        style={{
          top: position?.top ?? -9999,
          left: position?.left ?? -9999,
          visibility: position ? 'visible' : 'hidden',
        }}
        onKeyDown={onToolbarKeyDown}
      >
        {!editor ? (
          <>
            <span className={styles.chipLabel}>{label}</span>
            {maxLength !== undefined && (
              <span
                className={clsx(styles.chipCount, {
                  [styles.chipCountOver]: length > maxLength,
                })}
                title={`${length} of at most ${maxLength} characters`}
              >
                {length}/{maxLength}
              </span>
            )}
            {onEditComponentLink && componentLinkUrl && (
              <>
                <span className={styles.separator} aria-hidden="true" />
                <button
                  type="button"
                  className={styles.chipLink}
                  aria-label={`Edit link, ${componentLinkUrl}, in the panel`}
                  title="Edit link in the panel"
                  tabIndex={0}
                  onMouseDown={keepFocus}
                  onClick={onEditComponentLink}
                >
                  <Link2Icon aria-hidden="true" />
                  <span>{shortenUrl(componentLinkUrl, 32)}</span>
                </button>
              </>
            )}
          </>
        ) : linkHref === null ? (
          <>
            {visibleGroups.map((group, index) => [
              index > 0 && (
                <span
                  key={`separator-${index}`}
                  className={styles.separator}
                  aria-hidden="true"
                />
              ),
              ...group.map(renderItem),
            ])}
          </>
        ) : (
          <form
            className={styles.linkForm}
            onSubmit={(event) => {
              event.preventDefault();
              const page = suggestions[suggestion];
              if (page?.internalPath) {
                pageLinks.set(page.internalPath, {
                  title: page.title,
                  path: page.path ?? page.internalPath,
                });
              }
              applyLink(page ? (page.internalPath ?? page.path) : linkHref);
            }}
          >
            <Link2Icon aria-hidden="true" className={styles.linkIcon} />
            <input
              // The field opens in response to the author's request.
              autoFocus
              className={styles.linkInput}
              type="text"
              inputMode="url"
              aria-label="Link URL"
              placeholder="Search pages, or paste a link"
              value={linkHref}
              aria-invalid={linkInvalid}
              role="combobox"
              aria-expanded={suggestions.length > 0}
              aria-controls="canvas-inline-link-suggestions"
              aria-activedescendant={
                suggestions.length
                  ? `canvas-inline-link-suggestion-${suggestion}`
                  : undefined
              }
              onChange={(event) => {
                setLinkHref(event.target.value);
                setSuggestion(0);
              }}
              onKeyDown={(event) => {
                if (!suggestions.length) {
                  return;
                }
                if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                  event.preventDefault();
                  setSuggestion(
                    (current) =>
                      (current +
                        (event.key === 'ArrowDown' ? 1 : -1) +
                        suggestions.length) %
                      suggestions.length,
                  );
                }
              }}
            />
            {suggestions.length > 0 && (
              <ul
                id="canvas-inline-link-suggestions"
                role="listbox"
                aria-label="Pages"
                className={styles.suggestions}
              >
                {suggestions.map((page, index) => (
                  <li
                    key={page.id}
                    id={`canvas-inline-link-suggestion-${index}`}
                    role="option"
                    aria-selected={index === suggestion}
                    className={clsx(styles.suggestion, {
                      [styles.suggestionActive]: index === suggestion,
                    })}
                    onMouseDown={(event) => {
                      event.preventDefault();
                      // The system path keeps working when the alias changes.
                      if (page.internalPath) {
                        pageLinks.set(page.internalPath, {
                          title: page.title,
                          path: page.path ?? page.internalPath,
                        });
                      }
                      applyLink(page.internalPath ?? page.path);
                    }}
                  >
                    <span>{page.title}</span>
                    <span className={styles.suggestionPath}>
                      {page.path ?? page.internalPath}
                    </span>
                  </li>
                ))}
              </ul>
            )}
            <button
              type="submit"
              className={styles.textButton}
              disabled={linkInvalid}
            >
              {linkHref.trim() || !currentHref ? 'Apply' : 'Remove'}
            </button>
          </form>
        )}
      </div>,
      portalRoot,
    );
  },
);

export default InlineTextToolbar;
