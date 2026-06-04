# `AGENTS.md` — Canvas

Canvas is a Drupal module providing a decoupled, React-based page builder.

## 1. Repository layout

- Back end PHP Drupal module code in the repository root.
- React/TypeScript UI º in `ui`
- Monorepo packages (CLI º, Workbench º, Vite plugin, extensions, and others) in `packages/*`.

(The back end powers multiple front ends. Front ends are marked with º.)

## 2. Running tests and linting

Run commands directly from the Canvas module root:

```bash
composer run lint   # PHPCS + PHPStan
composer run fix    # auto-fix PHPCS violations
composer run --list # lists everything available, including running tests

npm run lint        # eslint + prettier + stylelint + tsc + cspell + yaml
npm run fix         # auto-fix eslint + prettier
npm run --list      # lists everything available, including running tests

# npm workspace-specific commands:
npm run --workspace=@drupal-canvas/ui --list
npm run --workspace=@drupal-canvas/cli --list
```

To run a specific test:
```bash
composer run phpunit -- tests/src/Kernel/PropExpressionKernelTest.php
composer run phpunit -- tests/src/Kernel/PropExpressionKernelTest.php --filter testLabel
npm run test:playwright -- tests/src/Playwright/tests/isolatedPerTest/routing.spec.ts
npm run --workspace=@drupal-canvas/ui cy:component -- --spec tests/unit/validation.cy.js
npm run --workspace=@drupal-canvas/ui cy:run -- --spec tests/e2e/canary.cy.js
npm run --workspace=@drupal-canvas/cli test:vitest -- src/lib/transform-css.test.ts
```

If developing in a DDEV environment, first determine the Canvas root, then use those same commands:
```bash
ddev exec -d /path/to/canvas npm run --list
```

- ALWAYS run `npm run fix` when JavaScript code is updated.
- ALWAYS run `composer run fix` when PHP code is updated.
- ALWAYS run narrow, directly impacted tests first.
- NEVER run full test suites. (Cypress component, Cypress E2E, PHPUnit, Playwright)

## 3. Collaboration conventions

- ALWAYS write U.S. English ("behavior", "color" …).
- NEVER push commits nor post comments without human approval.

### Writing code

- ALWAYS update relevant docs (both code doc blocks and `.md` files)
- NEVER create parallel/duplicate infra. Search existing abstractions first, ask second, create last.
- ALWAYS prefer adding new test cases to an existing test over creating a new test. Refactoring an existing test to make that possible is preferred.

### Creating MR commits

**The primary goal is reviewability.** Every commit should be something a human reviewer can
understand in isolation — the diff should tell a clear story, and the message should explain why.
When in doubt, ask: *would a reviewer looking only at this commit's diff immediately understand
what changed and why?* One concern per commit.

```
[🤖 ]<intent: what this commit achieves and why — ≤160 characters>

[<body: additional context, constraints, or reasoning — max 1 paragraph>]

[Co-authored-by: <Name> (<Role>, <model>)]
```
(Brackets convey optionality; drop the brackets if populated.)

When crafting or pushing commits to an MR, suggest:
- reviewers (by looking at the modified files' commit messages' `By:` lines; pick consistent contributors)
- an MR description, 1) summarize what the branch does, 2) include testing instructions as a checklist, 3) disclose AI use per [Drupal.org's policy](https://www.drupal.org/docs/develop/issues/issue-procedures-and-etiquette/policy-on-the-use-of-ai-when-contributing-to-drupal)
- the human in the loop actually understands the MR.

### Reading comments on an issue or MR

- Bugs may be reported as front end (UI, CLI …) because that is where they are observed by humans, but the right solution might be in the back end. Before doing any work, diagnose the root cause.

### Writing comments for an issue or MR

- Length: ≤180 words. Sentences: ≤25 words. Simplified English.
- State facts. Describe or explain only. No interpretation, speculation, recommendations, or hedging.
- No editorial flourishes (for example, adjectives like `robust`, `seamless`, `comprehensive`; buzz-verbs like `leverage`, `streamline`; etc.).
- Comments are for other contributors to understand **what** the code does, not **why** a change was made. Keep it brief and evergreen.
