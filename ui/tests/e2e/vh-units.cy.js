describe('Vh units should not cause issues', () => {
  /**
   * Asserts vh test fixtures are tagged and their rendered height matches the
   * canvas-applied cap (`data-canvas-preview-max-height`). That attribute is
   * stable even when the iframe innerHeight later changes (layout/content); it
   * must not match `innerHeight/2` after resize. Waits for tagging and rejects
   * absurd sizes (unbounded vh feedback before overrides apply).
   */
  const assertTaggedVhBoxHeights = () => {
    cy.testInIframe('[data-div="vh-half"]', (vhDiv) => {
      expect(vhDiv.getAttribute('data-canvas-preview-max-height')).to.match(
        /^\d+$/,
      );
      const tagged = parseInt(
        vhDiv.getAttribute('data-canvas-preview-max-height'),
        10,
      );
      const h = vhDiv.getBoundingClientRect().height;
      expect(h, 'vh-half box height').to.be.lessThan(5000);
      const tol = Math.max(10, Math.round(tagged * 0.08));
      expect(h).to.be.closeTo(tagged, tol);
    });
    cy.testInIframe('#vh-full', (vhDiv) => {
      expect(vhDiv.getAttribute('data-canvas-preview-max-height')).to.match(
        /^\d+$/,
      );
      const tagged = parseInt(
        vhDiv.getAttribute('data-canvas-preview-max-height'),
        10,
      );
      const h = vhDiv.getBoundingClientRect().height;
      expect(h, 'vh-full box height').to.be.lessThan(5000);
      const tol = Math.max(10, Math.round(tagged * 0.08));
      expect(h).to.be.closeTo(tagged, tol);
    });
  };

  before(() => {
    cy.drupalCanvasInstall(['canvas_test_vh_preview']);
  });

  beforeEach(() => {
    cy.drupalLogin('canvasUser', 'canvasUser');
  });

  after(() => {
    cy.drupalUninstall();
  });

  it('does not continually increase the height of the iframe when there are elements that have height defined in vh units', () => {
    cy.loadURLandWaitForCanvasLoaded({ url: 'canvas/editor/node/2' });
    cy.openLibraryPanel();
    cy.insertComponent({ name: 'Hero' });
    cy.insertComponent({ name: 'VH Half' });
    cy.insertComponent({ name: 'VH Full' });
    cy.waitForElementInIframe('[data-div="vh-half"]');
    cy.waitForElementInIframe('#vh-full');
    assertTaggedVhBoxHeights();

    // Intentionally wait two seconds to ensure the heights of the VH styled
    // styled elements have not changed.
    // eslint-disable-next-line cypress/no-unnecessary-waiting
    cy.wait(2000);
    assertTaggedVhBoxHeights();

    // Edit a component to ensure the VH styled elements do not increase in
    // size when the preview updates.
    cy.clickComponentInPreview('Hero');
    cy.findByLabelText('Heading').type('{selectall}{del}');
    cy.findByLabelText('Heading').type('NO GROW');
    cy.waitForElementContentInIframe('.my-hero__heading', 'NO GROW');
    assertTaggedVhBoxHeights();
  });

  it('re-applies vh constraints after in-place code component preview updates and accommodates content taller than one viewport', () => {
    const activePreviewIframeSelector =
      '[data-test-canvas-content-initialized="true"][data-canvas-swap-active="true"]';
    const longLabel =
      'A very long label that should wrap to many lines because the font size is large and forces the section to grow past one viewport height when rendered in the preview iframe';

    cy.loadURLandWaitForCanvasLoaded({ url: 'canvas/editor/node/2' });
    cy.openLibraryPanel();
    cy.insertComponent({ name: 'VH Min Screen Code' });
    cy.waitForElementInIframe('[data-testid="vh-code-min-screen"]');
    cy.testInIframe('[data-testid="vh-code-min-screen"]', (section) => {
      expect(section?.getAttribute('data-canvas-preview-max-height')).to.match(
        /^\d+$/,
      );
    });
    cy.get(activePreviewIframeSelector).then(($iframe) => {
      expect($iframe[0].clientHeight).to.be.lessThan(5000);
    });

    cy.clickComponentInPreview('VH Min Screen Code');

    // First edit: short text triggers in-place canvas-island re-render via
    // COMPONENT_PREVIEW_UPDATE_EVENT. The new section must be re-tagged so
    // that `min-height: 100vh` cannot feedback-grow the iframe.
    cy.get('[data-testid*="canvas-component-form-"]')
      .findByLabelText('Label')
      .clear();
    cy.get('[data-testid*="canvas-component-form-"]')
      .findByLabelText('Label')
      .type('Updated by canvas-island');
    cy.waitForElementContentInIframe(
      '[data-testid="vh-code-min-screen"] p',
      'Updated by canvas-island',
    );
    cy.testInIframe('[data-testid="vh-code-min-screen"]', (section) => {
      expect(section?.getAttribute('data-canvas-preview-max-height')).to.match(
        /^\d+$/,
      );
    });

    // Second edit: a long label wraps and forces the section past one
    // viewport height. Detection must still tag the section (matching at the
    // higher multipliers even when ratios[0] reflects content height) and
    // omit max-height so content can grow without clipping.
    cy.get('[data-testid*="canvas-component-form-"]')
      .findByLabelText('Label')
      .clear();
    cy.get('[data-testid*="canvas-component-form-"]')
      .findByLabelText('Label')
      .type(longLabel, { delay: 0 });
    cy.waitForElementContentInIframe(
      '[data-testid="vh-code-min-screen-label"]',
      longLabel,
    );

    // Intentionally wait to ensure the iframe height does not run away after
    // mutations settle.
    // eslint-disable-next-line cypress/no-unnecessary-waiting
    cy.wait(2000);
    cy.testInIframe('[data-testid="vh-code-min-screen"]', (section) => {
      expect(section?.getAttribute('data-canvas-preview-max-height')).to.match(
        /^\d+$/,
      );
      expect(section.style.minHeight, 'min-height is overridden inline').to.not
        .be.empty;
    });
    cy.get(activePreviewIframeSelector).then(($iframe) => {
      expect(
        $iframe[0].clientHeight,
        'iframe stays bounded with long content',
      ).to.be.lessThan(5000);
    });

    // Signature cache should avoid re-running the multiplier loop on every
    // keystroke: initial load requires one measurement; subsequent in-place
    // canvas-island updates with the same structural signature hit the cache.
    // Each pass that still has uncached vh candidates increments the counter
    // (initial load, then edits that replace DOM with new nodes). Keep a loose
    // bound that only guards against runaway feedback loops (e.g. dozens per
    // keystroke).
    cy.get(activePreviewIframeSelector).should(($iframe) => {
      const runs = parseInt(
        $iframe.attr('data-canvas-vh-detection-runs') || '0',
        10,
      );
      expect(runs, 'vh multiplier measurements').to.be.at.most(20);
    });
  });
});
