#!/usr/bin/env node
/**
 * Fails when a pending changeset names a private or unknown package.
 *
 * The changesets config sets `privatePackages.version` to false, so
 * `changeset version` skips private packages: a changeset that only names
 * private packages is never consumed, and the `version packages` CI job finds
 * a pending changeset but nothing to commit. Changes to private packages need
 * an empty changeset (`npx changeset --empty`) instead.
 *
 * Changesets and workspace packages are discovered with the same libraries
 * the changesets CLI uses, so this check sees what `changeset version` sees.
 *
 * See .changeset/README.md and docs/release-process.md.
 *
 * Usage: npm run lint:changesets
 */
import { execFileSync } from 'node:child_process';
import readChangesets from '@changesets/read';
import { getPackages } from '@manypkg/get-packages';

const root = execFileSync('git', ['rev-parse', '--show-toplevel'], {
  encoding: 'utf8',
}).trim();

const { packages } = await getPackages(root);
const isPrivate = new Map(
  packages.map(({ packageJson }) => [
    packageJson.name,
    packageJson.private === true,
  ]),
);

const errors = [];
const changesets = await readChangesets(root);
for (const { id, releases } of changesets) {
  for (const { name } of releases) {
    if (!isPrivate.has(name)) {
      errors.push(
        `.changeset/${id}.md: "${name}" is not a workspace package.`,
      );
    } else if (isPrivate.get(name)) {
      errors.push(
        `.changeset/${id}.md: "${name}" is private and is never versioned. ` +
          'Remove it from the changeset; use `npx changeset --empty` if no public package changed.',
      );
    }
  }
}

if (errors.length) {
  console.error(errors.join('\n'));
  process.exit(1);
}
console.log(`Checked ${changesets.length} changeset(s).`);
