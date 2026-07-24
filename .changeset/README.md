# Changesets

This directory holds pending release notes for the npm workspace packages in
`packages/*`. When a merge request changes one of those packages, it must add
a changeset. Merge requests that do not touch `packages/*` never need one.

## The quick way

```sh
npm run changeset:prompt
```

This prints a prompt covering your branch's package changes, the expected
format, and the bump-level rules, and copies it to the clipboard. Paste it
into your AI coding agent, and it writes the changeset for you.

## Writing one by hand

```sh
npx changeset
```

The command asks interactively which packages are affected, the semver bump
level for each, and a summary. The summary becomes the entry in the package's
`CHANGELOG.md`, so write it for consumers of the package.

If a merge request touches package files but should not trigger a release
(for example, test-only or documentation changes), add an empty changeset:

```sh
npx changeset --empty
```

## Summary format

Each changeset becomes one bullet point in the `CHANGELOG.md` of every
package it lists, under a "Minor Changes" or "Patch Changes" heading. The
whole summary is that bullet: the first line becomes the bullet text, and
any further lines are indented under it, so a nested markdown list renders
as sub-bullets.

Example changeset:

```markdown
---
"@drupal-canvas/headless": minor
---

Add draft-session recovery to the preview protocol.

- The host retries activation once after an expired session.
- `createHeadlessPreviewHost` accepts an `onRecovery` callback.
```

Resulting `CHANGELOG.md` entry after the next release:

```markdown
## 0.1.0

### Minor Changes

- Add draft-session recovery to the preview protocol.
  - The host retries activation once after an expired session.
  - `createHeadlessPreviewHost` accepts an `onRecovery` callback.
```

The summary is copied verbatim into every package listed in the frontmatter.
When a change means something different for each affected package, write a
separate changeset per package instead of sharing one summary.

For the release flow, see `docs/release-process.md`. General documentation:
https://github.com/changesets/changesets
