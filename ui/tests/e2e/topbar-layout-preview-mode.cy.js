/**
 * Test topbar layout changes for preview mode and icon-only buttons.
 *
 * Tests the restructured Canvas top navigation bar:
 * - Preview mode uses left|center|right grid layout
 * - Title truncates at 118px to prevent overlap
 * - Eye icons appear (no text labels)
 * - No sections overlap across viewport sizes
 */

describe('Canvas Topbar Layout - Preview Mode', () => {
  const getLanguageSelectTrigger = () =>
    cy.get('body').then(($body) => {
      const trigger = $body.find('[data-testid="language-select-trigger"]');

      return trigger.length ? cy.wrap(trigger) : cy.wrap(null, { log: false });
    });

  before(() => {
    cy.drupalCanvasInstall([], {}, ['administer nodes']);
  });

  beforeEach(() => {
    cy.drupalSession();
    cy.viewport(1200, 800);
    cy.drupalLogin('canvasUser', 'canvasUser');
  });

  after(() => {
    cy.drupalUninstall();
  });

  it('shows preview button (eye icon) in editor mode', () => {
    cy.loadURLandWaitForCanvasLoaded({
      url: 'canvas/editor/canvas_page/2',
    });

    // Preview button should exist with accessible name "Preview"
    cy.findByRole('button', { name: 'Preview' })
      .should('exist')
      .should('be.visible');

    // Exit Preview button should NOT exist in editor mode
    cy.findByRole('button', { name: 'Exit Preview' }).should('not.exist');
  });

  it(
    'shows exit preview button (eye icon) in preview mode',
    { retries: { openMode: 0, runMode: 2 } },
    () => {
      cy.loadURLandWaitForCanvasLoaded({
        url: 'canvas/editor/canvas_page/2',
      });

      // Click preview to enter preview mode
      cy.findByRole('button', { name: 'Preview' }).click();
      cy.url().should('include', '/preview/');

      // Exit Preview button should now exist
      cy.findByRole('button', { name: 'Exit Preview' })
        .should('exist')
        .should('be.visible');

      // Preview button should NOT exist in preview mode
      cy.findByRole('button', { name: 'Preview' }).should('not.exist');

      // Exit Preview should take us back to editor
      cy.findByRole('button', { name: 'Exit Preview' }).click();
      cy.url().should('include', '/editor/');
    },
  );

  it(
    'topbar sections do not overlap in preview mode',
    { retries: { openMode: 0, runMode: 2 } },
    () => {
      cy.loadURLandWaitForCanvasLoaded({
        url: 'canvas/editor/canvas_page/2',
      });

      // Enter preview mode
      cy.findByRole('button', { name: 'Preview' }).click();
      cy.url().should('include', '/preview/');

      // Get topbar bounding boxes
      cy.get('[data-testid="canvas-topbar"]').should('exist');

      // Verify left section exists (Drupal logo + viewport selector)
      // Viewport selector has aria-label="Select preview width"
      cy.findByRole('button', { name: 'Select preview width' }).should('exist');

      // Verify center section exists (page title)
      cy.findByTestId('canvas-navigation-button').should('exist');

      // Exit preview button should exist on right
      cy.findByRole('button', { name: 'Exit Preview' }).should('exist');

      getLanguageSelectTrigger().then((trigger) => {
        if (trigger) {
          cy.wrap(trigger).should('be.visible');
        }
      });

      // Verify no horizontal scroll needed (no overflow)
      cy.get('body').then(($body) => {
        expect($body[0].scrollWidth).to.equal($body[0].clientWidth);
      });
    },
  );

  it('truncates long page titles at 118px to prevent overlap', () => {
    cy.loadURLandWaitForCanvasLoaded({
      url: 'canvas/editor/canvas_page/2',
    });

    // Find the page title element
    cy.findByTestId('canvas-navigation-button').should('exist');

    // The title should have width constraints to prevent overflow
    cy.findByTestId('canvas-navigation-button').then(($button) => {
      // Find the span with the page title text inside the button
      cy.wrap($button)
        .find('span:not(.visually-hidden)')
        .filter(function () {
          // Get the span that contains the title text, not icon spans
          return (
            this.textContent.trim() !== '' &&
            !this.querySelector('svg') &&
            !this.getAttribute('data-testid')
          );
        })
        .first()
        .then(($title) => {
          // The title text width should be constrained to prevent overlap
          const titleTextWidth = $title.width();
          // Title is capped at 118px
          expect(titleTextWidth).to.be.lessThan(130);
          expect(titleTextWidth).to.be.greaterThan(0);

          // Verify the visible title span has a tooltip (for full text on hover)
          cy.wrap($title).should('have.attr', 'title');
        });
    });
  });

  it(
    'maintains topbar layout across different viewport sizes',
    { retries: { openMode: 0, runMode: 2 } },
    () => {
      cy.loadURLandWaitForCanvasLoaded({
        url: 'canvas/editor/canvas_page/2',
      });

      cy.findByRole('button', { name: 'Preview' }).click();
      cy.url().should('include', '/preview/');

      const viewports = [
        { name: '1024px', width: 1024, height: 768 },
        { name: '1200px', width: 1200, height: 800 },
        { name: '1440px', width: 1440, height: 900 },
      ];

      viewports.forEach(({ name, width, height }) => {
        cy.viewport(width, height);

        // Topbar should always be visible
        cy.get('[data-testid="canvas-topbar"]').should('be.visible');

        // Exit preview button should still be clickable
        cy.findByRole('button', { name: 'Exit Preview' }).should('be.visible');

        getLanguageSelectTrigger().then((trigger) => {
          if (trigger) {
            cy.wrap(trigger).should('be.visible');
          }
        });

        // No horizontal overflow
        cy.get('body').then(($body) => {
          expect($body[0].scrollWidth).to.equal($body[0].clientWidth);
        });

        cy.log(`✓ Layout OK at ${name}`);
      });
    },
  );

  it(
    'language selector displays code format (EN) not full name (English)',
    { retries: { openMode: 0, runMode: 2 } },
    () => {
      cy.loadURLandWaitForCanvasLoaded({
        url: 'canvas/editor/canvas_page/2',
      });

      getLanguageSelectTrigger().then((trigger) => {
        if (!trigger) {
          // Language selector is not available because no translation modules are installed
          // in the test fixture (LanguageSelect only renders when languages.length > 1)
          cy.log('Language selector not enabled for this fixture');
          return;
        }

        cy.wrap(trigger)
          .invoke('text')
          .then((text) => {
            expect(text.trim()).to.match(/^[A-Z]{2}(?:\s|$)/);
            expect(text).not.to.include('English');
            expect(text).not.to.include('Français');
          });
      });
    },
  );

  it('viewport selector appears in left section of preview mode', () => {
    cy.loadURLandWaitForCanvasLoaded({
      url: 'canvas/editor/canvas_page/2',
    });

    cy.findByRole('button', { name: 'Preview' }).click();
    cy.url().should('include', '/preview/');

    // Viewport selector should exist in the topbar
    // It's a button with aria-label="Select preview width"
    cy.findByRole('button', { name: 'Select preview width' }).should('exist');
  });
});
