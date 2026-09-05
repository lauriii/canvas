import { useState } from 'react';

import useSyncIframeHeightToContent from '@/hooks/useSyncIframeHeightToContent';

const VIEWPORT_MIN_HEIGHT = 400;

// Mirrors the Viewport/Preview.module.css structure: the iframe is absolutely
// positioned and fills the preview container, whose height the hook keeps in
// sync with the content rendered inside the iframe.
const Harness = ({ srcDoc }) => {
  const [iframe, setIframe] = useState(null);
  const [container, setContainer] = useState(null);
  useSyncIframeHeightToContent(iframe, container, VIEWPORT_MIN_HEIGHT);
  return (
    <>
      <style>{`
        .harness-container { position: relative; width: 800px; }
        .harness-frame { position: absolute; top: 0; left: 0; width: 100%; height: 100%; border: 0; }
      `}</style>
      <div
        data-testid="container"
        className="harness-container"
        ref={setContainer}
        style={{ minHeight: `${VIEWPORT_MIN_HEIGHT}px` }}
      >
        <iframe
          data-testid="frame"
          className="harness-frame"
          title="preview"
          data-canvas-swap-active="true"
          ref={setIframe}
          srcDoc={srcDoc}
        />
      </div>
    </>
  );
};

// The hook's viewport-height probes intentionally resize the iframe while a
// ResizeObserver watches its document; the browser then reports the benign
// "ResizeObserver loop completed with undelivered notifications" error, which
// Cypress would otherwise treat as a test failure. Registered globally rather
// than per test because the error can also surface between tests or retry
// attempts, where a per-test cy.on handler is not attached.
Cypress.on(
  'uncaught:exception',
  (err) => !err.message.includes('ResizeObserver loop'),
);

describe('useSyncIframeHeightToContent', () => {
  it('sizes the preview to content when html/body use block-size 100% (#3544531)', () => {
    cy.mount(
      <Harness
        srcDoc={`<!doctype html><html><head><style>
            html, body { block-size: 100%; margin: 0; }
          </style></head><body>
            <div id="tall" style="height: 900px"></div>
            <div id="short" style="height: 100px"></div>
          </body></html>`}
      />,
    );

    // The preview must grow to fit the 1000px of content even though the
    // page's 100% block-size on html/body makes their offsetHeight track the
    // iframe height instead of the content height.
    cy.get('[data-testid="container"]', { timeout: 10000 }).should(($el) => {
      expect($el[0].getBoundingClientRect().height).to.be.greaterThan(950);
    });

    // Removing content must shrink the preview again instead of ratcheting at
    // the previously grown height.
    cy.get('[data-testid="frame"]').then(($frame) => {
      $frame[0].contentDocument.getElementById('tall').remove();
    });
    cy.get('[data-testid="container"]', { timeout: 10000 }).should(($el) => {
      expect($el[0].getBoundingClientRect().height).to.be.lessThan(600);
    });

    // The measurement must leave no trace on the page: its temporary
    // neutralization styles are restored, leaving only the hook's own
    // overflow and min-height writes.
    cy.get('[data-testid="frame"]').should(($frame) => {
      const html = $frame[0].contentDocument.documentElement;
      const body = $frame[0].contentDocument.body;
      expect(
        html.style.getPropertyValue('height'),
        'html inline height',
      ).to.equal('');
      expect(
        body.style.getPropertyValue('height'),
        'body inline height',
      ).to.equal('');
      expect(html.style.getPropertyValue('min-height')).to.equal(
        `${VIEWPORT_MIN_HEIGHT}px`,
      );
      expect(html.style.getPropertyPriority('min-height')).to.equal('');
    });

    // The resize machinery must settle: the measurement's own style writes
    // may not keep re-triggering the MutationObserver in a feedback loop.
    // The waits are the point here: let trailing resize work finish, then
    // observe a fixed idle window for stray mutations.
    // eslint-disable-next-line cypress/no-unnecessary-waiting
    cy.wait(300);
    cy.get('[data-testid="frame"]').then(($frame) => {
      const html = $frame[0].contentDocument.documentElement;
      const records = [];
      const observer = new MutationObserver((mutations) =>
        records.push(...mutations),
      );
      observer.observe(html, { attributes: true, subtree: true });
      // eslint-disable-next-line cypress/no-unnecessary-waiting
      cy.wait(700).then(() => {
        observer.disconnect();
        expect(records, 'mutation records while idle').to.have.length(0);
      });
    });
  });

  it('still sizes the preview to content without viewport-tracking CSS', () => {
    cy.mount(
      <Harness
        srcDoc={`<!doctype html><html><head><style>body { margin: 0; }</style></head><body>
            <div style="height: 900px"></div>
          </body></html>`}
      />,
    );
    cy.get('[data-testid="container"]', { timeout: 10000 }).should(($el) => {
      const height = $el[0].getBoundingClientRect().height;
      expect(height).to.be.greaterThan(850);
      expect(height).to.be.lessThan(1000);
    });
  });
});
