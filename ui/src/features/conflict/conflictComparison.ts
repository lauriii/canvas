import type {
  PendingChange,
  PendingChanges,
} from '@/services/pendingChangesApi';

export type ConflictChangeEntry = {
  pointer: string;
  change: PendingChange;
};

const CHROME_SELECTORS = [
  '#toolbar-administration',
  'body > [role="banner"]',
  'body > [role="navigation"]',
  'body > header',
  'body > nav',
  '.content-header',
  '.breadcrumb',
  '.site-header',
  '.region-header',
];

const CONTENT_SELECTORS = [
  '.region-content',
  '.block-system-main-block',
  'main',
  '[role="main"]',
  'body',
];

const PREVIEW_DOCUMENT_RESET_STYLES = `<style data-canvas-conflict-preview-reset>
html.toolbar-anti-flicker.toolbar-loading.toolbar-fixed body,
html.toolbar-anti-flicker.toolbar-loading.toolbar-fixed.toolbar-horizontal.toolbar-tray-open body,
body.toolbar-anti-flicker,
body.toolbar-loading,
body.toolbar-fixed {
  padding-top: 0 !important;
}
</style>`;

const escapeHtmlAttribute = (value: string): string =>
  value
    .replace(/&/g, '&amp;')
    .replace(/"/g, '&quot;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;');

const serializeAttributes = (element: Element | null | undefined): string =>
  Array.from(element?.attributes ?? [])
    .map(
      (attribute) =>
        `${attribute.name}="${escapeHtmlAttribute(attribute.value)}"`,
    )
    .join(' ');

const removeCanvasChrome = (document: Document): void => {
  for (const selector of CHROME_SELECTORS) {
    document.querySelectorAll(selector).forEach((element) => element.remove());
  }
};

const removeDrupalToolbarState = (document: Document): void => {
  for (const element of [document.documentElement, document.body]) {
    const toolbarClassNames = Array.from(element?.classList ?? []).filter(
      (className) =>
        className === 'toolbar' || className.startsWith('toolbar-'),
    );

    if (toolbarClassNames.length > 0) {
      element.classList.remove(...toolbarClassNames);
    }
  }
};

const getContentOnlyMarkup = (document: Document): string => {
  for (const selector of CONTENT_SELECTORS) {
    const element = document.querySelector(selector);
    if (element?.innerHTML.trim()) {
      return element.innerHTML.trim();
    }
  }

  return document.body?.innerHTML.trim() ?? '';
};

const wrapPreviewDocument = (
  headHtml: string,
  bodyHtml: string,
  bodyAttributes = '',
): string => {
  const bodyAttributeString = bodyAttributes ? ` ${bodyAttributes}` : '';

  return `<!doctype html><html><head><base target="_blank" />${headHtml}${PREVIEW_DOCUMENT_RESET_STYLES}</head><body${bodyAttributeString}>${bodyHtml}</body></html>`;
};

export const getConflictQueue = (
  pendingChanges: PendingChanges | undefined,
): ConflictChangeEntry[] =>
  Object.entries(pendingChanges ?? {})
    .filter(
      ([, change]) =>
        change.entity_type === 'canvas_page' && change.hasConflict,
    )
    .sort(([, a], [, b]) => b.updated - a.updated)
    .map(([pointer, change]) => ({ pointer, change }));

export const findConflictIndex = (
  queue: ConflictChangeEntry[],
  entityType?: string,
  entityId?: string,
): number =>
  queue.findIndex(
    ({ change }) =>
      change.entity_type === entityType &&
      String(change.entity_id) === entityId,
  );

export const getConflictRouteFromEntry = ({
  change,
}: ConflictChangeEntry): string =>
  `/conflict/${change.entity_type}/${encodeURIComponent(String(change.entity_id))}`;

export const getNextConflictEntry = (
  queue: ConflictChangeEntry[],
  currentPointer: string,
): ConflictChangeEntry | undefined => {
  const remaining = queue.filter(({ pointer }) => pointer !== currentPointer);
  if (!remaining.length) {
    return undefined;
  }
  const currentIndex = queue.findIndex(
    ({ pointer }) => pointer === currentPointer,
  );
  return remaining[Math.min(currentIndex, remaining.length - 1)];
};

export const stripCanvasChrome = (html: string): string => {
  const trimmed = html.trim();
  if (!trimmed || typeof DOMParser === 'undefined') {
    return trimmed;
  }

  const document = new DOMParser().parseFromString(trimmed, 'text/html');
  removeCanvasChrome(document);

  return getContentOnlyMarkup(document) || trimmed;
};

export const buildContentOnlyPreviewDocument = (html: string): string => {
  const trimmed = html.trim();
  if (!trimmed || typeof DOMParser === 'undefined') {
    return wrapPreviewDocument('', trimmed);
  }

  const document = new DOMParser().parseFromString(trimmed, 'text/html');
  document.head.querySelectorAll('base').forEach((element) => element.remove());
  removeCanvasChrome(document);
  removeDrupalToolbarState(document);

  const headHtml = document.head?.innerHTML ?? '';
  const bodyHtml = getContentOnlyMarkup(document) || trimmed;
  const bodyAttributes = serializeAttributes(document.body);

  return wrapPreviewDocument(headHtml, bodyHtml, bodyAttributes);
};

const getCanvasIslandText = (document: Document): string => {
  const values = Array.from(
    document.querySelectorAll<HTMLElement>('canvas-island[props]'),
  ).flatMap((element) => {
    const props = element.getAttribute('props');
    if (!props) {
      return [];
    }

    try {
      const parsed = JSON.parse(props) as Record<string, unknown>;
      return Object.values(parsed)
        .map((value) => {
          if (Array.isArray(value) && typeof value[1] === 'string') {
            return value[1];
          }
          return typeof value === 'string' ? value : '';
        })
        .filter(Boolean);
    } catch {
      return [];
    }
  });

  return values.join(' ').replace(/\s+/g, ' ').trim();
};

export const toPlainText = (html: string): string => {
  const content = stripCanvasChrome(html);
  if (!content || typeof DOMParser === 'undefined') {
    return content;
  }
  const document = new DOMParser().parseFromString(content, 'text/html');
  const text = document.body?.textContent?.replace(/\s+/g, ' ').trim() ?? '';

  return text || getCanvasIslandText(document) || content;
};
