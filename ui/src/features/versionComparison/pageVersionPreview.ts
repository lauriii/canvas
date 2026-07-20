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
];

const PREVIEW_DOCUMENT_RESET_STYLES = `<style data-canvas-page-version-preview-reset>
html,
body {
  overflow: hidden !important;
}
html.toolbar-anti-flicker.toolbar-loading.toolbar-fixed body,
html.toolbar-anti-flicker.toolbar-loading.toolbar-fixed.toolbar-horizontal.toolbar-tray-open body,
body.toolbar-anti-flicker,
body.toolbar-loading,
body.toolbar-fixed {
  padding-top: 0 !important;
}
</style>`;

const escapeHtml = (value: string): string =>
  value
    .replace(/&/g, '&amp;')
    .replace(/"/g, '&quot;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;');

const serializeAttributes = (element: Element | null | undefined): string =>
  Array.from(element?.attributes ?? [])
    .map((attribute) => `${attribute.name}="${escapeHtml(attribute.value)}"`)
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

const buildMissingContentSelectorMessage = (): string =>
  `<main data-canvas-page-version-preview-warning="true"><p>Unable to build a content-only preview because no supported content region was found.</p><p>Expected one of these selectors: ${CONTENT_SELECTORS.map(
    (selector) => `<code>${escapeHtml(selector)}</code>`,
  ).join(', ')}.</p></main>`;

const getContentOnlyMarkup = (document: Document): string => {
  for (const selector of CONTENT_SELECTORS) {
    const element = document.querySelector(selector);
    if (element?.innerHTML.trim()) {
      return element.innerHTML.trim();
    }
  }

  return buildMissingContentSelectorMessage();
};

const wrapPreviewDocument = (
  headHtml: string,
  bodyHtml: string,
  bodyAttributes = '',
): string => {
  const bodyAttributeString = bodyAttributes ? ` ${bodyAttributes}` : '';

  return `<!doctype html><html><head><base target="_blank" />${headHtml}${PREVIEW_DOCUMENT_RESET_STYLES}</head><body${bodyAttributeString}>${bodyHtml}</body></html>`;
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
  const bodyHtml = getContentOnlyMarkup(document);
  const bodyAttributes = serializeAttributes(document.body);

  return wrapPreviewDocument(headHtml, bodyHtml, bodyAttributes);
};

export const buildFullPagePreviewDocument = (html: string): string => {
  const trimmed = html.trim();
  if (!trimmed || typeof DOMParser === 'undefined') {
    return wrapPreviewDocument('', trimmed);
  }

  const document = new DOMParser().parseFromString(trimmed, 'text/html');
  document.head.querySelectorAll('base').forEach((element) => element.remove());
  removeDrupalToolbarState(document);

  const headHtml = document.head?.innerHTML ?? '';
  const bodyHtml = document.body?.innerHTML.trim() || trimmed;
  const bodyAttributes = serializeAttributes(document.body);

  return wrapPreviewDocument(headHtml, bodyHtml, bodyAttributes);
};
