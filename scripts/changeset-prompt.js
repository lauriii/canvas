#!/usr/bin/env node
/**
 * Prints a prompt for an AI coding agent to write the changeset for the
 * current branch's package changes, and copies it to the clipboard.
 *
 * This only concerns the npm workspace packages in packages/*; changes to
 * the Drupal module or the UI never need changesets. See
 * .changeset/README.md for the changeset format and workflow, and
 * docs/release-process.md for how the packages are released.
 *
 * Usage: npm run changeset:prompt [-- <target-branch>]
 */
import { execFileSync, spawnSync } from 'node:child_process';
import { existsSync, readFileSync } from 'node:fs';

const targetBranch = process.argv[2] ?? 'origin/1.x';

function git(...args) {
  return execFileSync('git', args, { encoding: 'utf8' }).trim();
}

// Package manifests are read with repository-relative paths; make them work
// when the script is invoked directly instead of through `npm run`.
process.chdir(git('rev-parse', '--show-toplevel'));

let mergeBase;
try {
  mergeBase = git('merge-base', targetBranch, 'HEAD');
} catch {
  console.error(
    `Could not resolve a merge base with ${targetBranch}. Fetch the target ` +
      'branch first, or pass one: npm run changeset:prompt -- <branch>',
  );
  process.exit(1);
}

// Committed and uncommitted changes, plus untracked files.
const changedFiles = new Set(
  [
    ...git('diff', '--name-only', mergeBase, '--', 'packages/').split('\n'),
    ...git('ls-files', '--others', '--exclude-standard', '--', 'packages/').split('\n'),
  ].filter(Boolean),
);

const packages = new Map();
for (const file of changedFiles) {
  const dir = file.split('/')[1];
  if (!dir || packages.has(dir)) {
    continue;
  }
  const manifestPath = `packages/${dir}/package.json`;
  // A package without a manifest was deleted; it cannot be versioned.
  if (!existsSync(manifestPath)) {
    continue;
  }
  const manifest = JSON.parse(readFileSync(manifestPath, 'utf8'));
  packages.set(dir, {
    name: manifest.name,
    private: manifest.private === true,
  });
}

if (packages.size === 0) {
  console.error(
    `No changes under packages/ compared to ${targetBranch}; no changeset needed.`,
  );
  process.exit(0);
}

const packageList = [...packages.values()]
  .map((pkg) => `- ${pkg.name} (${pkg.private ? 'private' : 'public'})`)
  .join('\n');

const prompt = `Write the changeset for this branch's npm package changes.

Changed packages, computed against ${targetBranch}:

${packageList}

Steps:

1. Review this branch's changes to the packages listed above
   (\`git diff ${targetBranch}... -- packages/\`).
2. Only public packages get changesets; ignore the private ones.
3. Ignore changes that cannot affect npm consumers of a package, such as
   changes to tests, READMEs, or tooling configuration.
4. If no consumer-visible change to a public package remains, run
   \`npx changeset --empty\` and stop.
5. Decide the bump level for each affected package: \`patch\` for bug fixes
   and internal changes with no new API, \`minor\` for new features or API
   additions. Never use \`major\`; if a change is breaking, stop and flag it
   to me instead.
6. Create \`.changeset/<short-kebab-case-slug>.md\`:

   ---
   "@drupal-canvas/<package>": <patch|minor>
   ---

   <summary>

Writing the summary:

- It becomes a changelog bullet read by npm consumers of the package. The
  first line must be one standalone sentence in the imperative ("Add ...",
  "Fix ..."). Optional further lines: a nested markdown list with
  consumer-relevant details.
- Read the affected packages' existing CHANGELOG.md files, where present,
  and match their tone, voice, and level of detail.
- Leave out repository-internal details (CI, refactors, tests) and issue
  numbers.
- The summary is copied verbatim into the changelog of every package listed
  in the frontmatter. Write one changeset per package when the packages need
  different texts.
- Do not bump versions in package.json; CI does that during the release.

See .changeset/README.md for the format reference and examples.
`;

process.stdout.write(prompt);

function copyToClipboard(text) {
  const candidates =
    process.platform === 'darwin'
      ? [['pbcopy']]
      : process.platform === 'win32'
        ? [['clip']]
        : [
            ['wl-copy'],
            ['xclip', '-selection', 'clipboard'],
            ['xsel', '--clipboard', '--input'],
          ];
  for (const [command, ...args] of candidates) {
    const result = spawnSync(command, args, { input: text });
    if (result.status === 0) {
      return true;
    }
  }
  return false;
}

console.error(
  copyToClipboard(prompt)
    ? '\n(Prompt copied to the clipboard.)'
    : '\n(Could not copy to the clipboard; copy the prompt above manually.)',
);
