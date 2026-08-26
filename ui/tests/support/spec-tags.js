import fs from 'fs';
import path from 'path';

// Default weight for an untagged spec — one unit ≈ 30 seconds of runtime.
export const DEFAULT_WEIGHT = 1;

// Recursively collect `.cy.js` spec file paths under a directory.
export const walkSpecs = (dir) =>
  fs.readdirSync(dir).flatMap((file) => {
    const filePath = path.join(dir, file);
    if (fs.statSync(filePath).isDirectory()) {
      return walkSpecs(filePath);
    }
    return filePath.match(/\.cy\.js$/) ? [filePath] : [];
  });

// Read the first `// @canvas-ci <tag> <tag>…` line in a spec. This runs before
// the tests, so it can only read the files, not measured timings — the tags
// carry that knowledge instead. Supported tags:
//   weight=N  relative runtime, one unit ≈ 30s (default 1). Balances the split.
//   flaky     spread across nodes, and retried at the job level, so stacked
//             in-spec retries can't pile up on one node.
export const readSpecTags = (filePath) => {
  const head = fs.readFileSync(filePath, 'utf8').slice(0, 2000);
  const match = head.match(/^\s*\/\/\s*@canvas-ci\s+(.+)$/m);
  const tags = match ? match[1].trim().split(/\s+/) : [];
  const weightTag = tags.find((tag) => tag.startsWith('weight='));
  return {
    file: filePath,
    flaky: tags.includes('flaky'),
    weight: weightTag
      ? Number(weightTag.slice('weight='.length))
      : DEFAULT_WEIGHT,
  };
};
