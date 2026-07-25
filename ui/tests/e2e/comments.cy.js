describe('Comments', () => {
  before(() => {
    // `canvasUser` holds the `canvas` role, which does not carry the comment
    // permissions by default. The third argument of drupalCanvasInstall is
    // forwarded as CANVAS_EXTRA_PERMISSIONS and granted to that role.
    // @see tests/src/TestSite/CanvasTestSetup.php
    cy.drupalCanvasInstall([], {}, [
      'view canvas comments',
      'create canvas comments',
    ]);
  });

  beforeEach(() => {
    cy.drupalLogin('canvasUser', 'canvasUser');
  });

  after(() => {
    cy.drupalUninstall();
  });

  // A single flow so the created thread carries through every step without
  // relying on test ordering. The sidebar is driven throughout: its test IDs are
  // stable, whereas on-canvas pins depend on preview geometry.
  it('creates a thread, replies, resolves, and persists across a reload', () => {
    cy.intercept('GET', '**/canvas/api/v0/comments*').as('getComments');
    cy.intercept('POST', '**/canvas/api/v0/comments').as('createThread');
    cy.intercept('POST', '**/canvas/api/v0/comments/*/replies').as('reply');
    cy.intercept('PATCH', '**/canvas/api/v0/comments/*').as('setResolved');

    cy.loadURLandWaitForCanvasLoaded();

    // Open the comments panel from the side menu.
    cy.get('[data-testid="canvas-side-menu"]')
      .find('[aria-label="Comments"]')
      .click();
    cy.get('[data-testid="canvas-comments-panel"]').should('exist');
    cy.wait('@getComments');
    cy.get('[data-testid="canvas-comments-empty"]').should('exist');

    // Create a surface-level thread.
    cy.get('[data-testid="canvas-comment-composer"]').type(
      'The hero needs a shorter heading.',
    );
    cy.get('[data-testid="canvas-comment-submit"]').click();
    cy.wait('@createThread').its('response.statusCode').should('eq', 201);

    cy.get('[data-testid="canvas-comment-thread"]')
      .should('have.length', 1)
      .and('contain.text', 'The hero needs a shorter heading.');

    // Reply to it.
    cy.get('[data-testid="canvas-comment-thread-toggle"]').click();
    cy.get('[data-testid="canvas-comment-reply-input"]').type(
      'Agreed, shortening it now.',
    );
    cy.get('[data-testid="canvas-comment-reply-submit"]').click();
    cy.wait('@reply').its('response.statusCode').should('eq', 201);
    cy.get('[data-testid="canvas-comment-replies"]').should(
      'contain.text',
      '1 reply',
    );

    // Reload and assert both the thread and its reply were persisted.
    cy.loadURLandWaitForCanvasLoaded({ clearAutoSave: false });
    cy.get('[data-testid="canvas-side-menu"]')
      .find('[aria-label="Comments"]')
      .click();
    cy.wait('@getComments');
    cy.get('[data-testid="canvas-comment-thread"]')
      .should('have.length', 1)
      .and('contain.text', 'The hero needs a shorter heading.');
    cy.get('[data-testid="canvas-comment-replies"]').should(
      'contain.text',
      '1 reply',
    );
    cy.get('[data-testid="canvas-comment-thread-toggle"]').click();
    cy.get('[data-testid="canvas-comment-reply"]').should(
      'contain.text',
      'Agreed, shortening it now.',
    );

    // Resolve it. Resolving requires only `create canvas comments`, which this
    // user holds: resolve is deliberately cheap and reversible, so a plain
    // commenter can close a thread out.
    cy.get('[data-testid="canvas-comment-resolve"]').click();
    cy.wait('@setResolved').its('response.statusCode').should('eq', 200);

    // Resolved threads leave the default list and are reachable under the
    // resolved filter.
    cy.get('[data-testid="canvas-comments-empty"]').should('exist');
    cy.get('[data-testid="canvas-comments-filter-resolved"]').click();
    cy.get('[data-testid="canvas-comment-thread"]')
      .should('have.length', 1)
      .and('contain.text', 'The hero needs a shorter heading.');

    // Reopening restores it to the open list with the conversation intact.
    cy.get('[data-testid="canvas-comment-resolve"]').click();
    cy.wait('@setResolved').its('response.statusCode').should('eq', 200);
    cy.get('[data-testid="canvas-comments-filter-open"]').click();
    cy.get('[data-testid="canvas-comment-thread"]').should('have.length', 1);
  });
});
