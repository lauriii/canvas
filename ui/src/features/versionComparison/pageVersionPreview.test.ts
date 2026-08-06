import { describe, expect, it } from 'vitest';

import {
  buildContentOnlyPreviewDocument,
  buildFullPagePreviewDocument,
} from './pageVersionPreview';

const fullPageHtml = `<!doctype html>
<html>
  <head>
    <title>Preview page</title>
  </head>
  <body class="path-canvas toolbar-fixed">
    <header class="site-header">Site header</header>
    <main>
      <div class="region-content">Page content</div>
    </main>
    <footer class="site-footer">Site footer</footer>
  </body>
</html>`;

describe('page version preview documents', () => {
  it('preserves global regions for visual comparison previews', () => {
    const previewDocument = buildFullPagePreviewDocument(fullPageHtml);

    expect(previewDocument).toContain('Site header');
    expect(previewDocument).toContain('Page content');
    expect(previewDocument).toContain('Site footer');
    expect(previewDocument).toContain('class="path-canvas"');
  });

  it('keeps content-only preview behavior available', () => {
    const previewDocument = buildContentOnlyPreviewDocument(fullPageHtml);

    expect(previewDocument).not.toContain('Site header');
    expect(previewDocument).toContain('Page content');
    expect(previewDocument).not.toContain('Site footer');
  });

  it('shows a warning when content-only preview selectors do not match', () => {
    const previewDocument = buildContentOnlyPreviewDocument(`
      <!doctype html>
      <html>
        <body>
          <section class="custom-content-region">Custom content</section>
        </body>
      </html>
    `);

    expect(previewDocument).toContain(
      'Unable to build a content-only preview because no supported content region was found.',
    );
    expect(previewDocument).toContain(
      'data-canvas-page-version-preview-warning',
    );
    expect(previewDocument).toContain('.region-content');
    expect(previewDocument).toContain('.block-system-main-block');
    expect(previewDocument).toContain('main');
    expect(previewDocument).toContain('[role=&quot;main&quot;]');
    expect(previewDocument).not.toContain('Custom content');
  });
});
