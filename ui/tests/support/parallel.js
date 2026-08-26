import path from 'path';

import { readSpecTags, walkSpecs } from './spec-tags.js';

const NODE_INDEX = Number(process.env.CI_NODE_INDEX || 1); // 1-based.
const NODE_TOTAL = Number(process.env.CI_NODE_TOTAL || 1);
const TEST_FOLDER = path.join(process.cwd(), 'tests/e2e');

const specs = walkSpecs(TEST_FOLDER).sort().map(readSpecTags);

// One bin per CI node, tracking accumulated weight.
const bins = Array.from({ length: NODE_TOTAL }, () => ({ load: 0, files: [] }));
const assign = (spec, binIndex) => {
  bins[binIndex].files.push(spec.file);
  bins[binIndex].load += spec.weight;
};
const lightestBin = () =>
  bins.reduce((min, bin, i) => (bin.load < bins[min].load ? i : min), 0);

// Phase 1 — deal flaky specs round-robin so no two land on the same node. This
// bounds how much Cypress in-spec retries (which re-run a failed test up to N
// times) can inflate any single node when a flaky test misbehaves.
specs
  .filter((spec) => spec.flaky)
  .sort((a, b) => b.weight - a.weight)
  .forEach((spec, i) => assign(spec, i % NODE_TOTAL));

// Phase 2 — greedy longest-processing-time: assign the heaviest remaining spec
// to the currently-lightest bin, so the slow specs spread across nodes instead
// of stacking on whichever node the alphabetical order happened to fill.
specs
  .filter((spec) => !spec.flaky)
  .sort((a, b) => b.weight - a.weight)
  .forEach((spec) => assign(spec, lightestBin()));

console.log(bins[NODE_INDEX - 1].files.join(','));
