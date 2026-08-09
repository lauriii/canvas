import path from 'path';

import { readSpecTags, walkSpecs } from './spec-tags.js';

// Print the base name of every spec tagged `// @canvas-ci flaky`, one per line.
// The `cypress E2E` job greps its run log for these names to decide whether to
// retry the job (see `.gitlab-ci.yml`). Reading the tags here makes the `flaky`
// marker the single source of truth for both the split and the retry guard.
const TEST_FOLDER = path.join(process.cwd(), 'tests/e2e');

const flaky = walkSpecs(TEST_FOLDER)
  .filter((file) => readSpecTags(file).flaky)
  .map((file) => path.basename(file, '.cy.js'));

console.log(flaky.join('\n'));
