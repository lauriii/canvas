#!/usr/bin/env node
/**
 * Builds a record of flaky Playwright tests and CI infrastructure failures.
 *
 * - A test is flaky when it failed at least once and then passed on a retry.
 *   Playwright's JSON reporter marks these tests with `status: 'flaky'`
 *   (detection: `test retry`). A test that failed all its retries in a
 *   Playwright job, and passed after the job was retried in GitLab, is flaky
 *   too (detection: `job retry`). Each attempt of a job prints the tests that
 *   failed in it to the job log (see `--shard-summary` below), because the
 *   blob report artifacts expire after a day.
 * - An infrastructure failure is a failed job whose `failure_reason` is not
 *   `script_failure`. For example, a runner pod that was terminated
 *   (`runner_system_failure`) or a job that exceeded its timeout
 *   (`job_execution_timeout`). Retried jobs are included. The runner and the
 *   runner manager (the host process that ran the job) are recorded too.
 *
 * The script:
 * 1. Fetches the previously published CSV files, so the record keeps history
 *    beyond the artifact and log expiry.
 * 2. Lists all pipelines of the project updated in the last few days,
 *    including merge request pipelines. Pipelines that have not changed since
 *    they were last scanned are skipped.
 * 3. For each pipeline:
 *    - records its failed jobs that have an infrastructure failure reason,
 *      with the error message from the end of the job log;
 *    - downloads `test-results/playwright.json` from the `playwright-reports`
 *      job and records the number of tests that ran and the flaky tests;
 *    - for Playwright jobs that ran more than once, reads the failed tests
 *      from each attempt's log and records the tests that no longer fail in
 *      the last attempt.
 * 4. Lists the merge requests merged into the default branch in the same
 *    window, to show next to the flaky tests per day.
 * 5. Writes `flaky-tests.csv`, `test-runs.csv`, `infrastructure-failures.csv`,
 *    `merged-merge-requests.csv`, `scanned-pipelines.csv` and a summary
 *    `index.html` to the output directory.
 *
 * Environment variables (all set by GitLab CI, except where noted):
 * - CI_API_V4_URL, CI_PROJECT_ID, CI_DEFAULT_BRANCH
 * - FLAKY_TESTS_PREVIOUS_URL: URL of the directory with the published CSV
 *   files. Optional; without it the record starts empty.
 * - FLAKY_TESTS_API_TOKEN: optional personal or project access token, for
 *   projects whose pipelines are not public.
 * - FLAKY_TESTS_DAYS: how many days of pipelines to scan. Defaults to 7. Raise
 *   it for the first run, to backfill up to the artifact expiry.
 *
 * Usage:
 * - node scripts/flaky-tests-report.js <output-directory>
 * - node scripts/flaky-tests-report.js --shard-summary <playwright-json-report>
 *   Prints the tests that failed in one Playwright job, in a format this
 *   script reads back from the job log.
 *
 * See docs/testing/playwright.md#flaky-tests.
 */
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';

export const FLAKY_TESTS_COLUMNS = [
  'pipeline_created_at',
  'pipeline_id',
  'pipeline_ref',
  'pipeline_source',
  'commit_sha',
  'job_id',
  'job_url',
  'project',
  'file',
  'line',
  'title',
  'failed_attempts',
  'first_error',
  'detection',
];

export const INFRASTRUCTURE_FAILURES_COLUMNS = [
  'job_created_at',
  'pipeline_id',
  'pipeline_ref',
  'pipeline_source',
  'job_id',
  'job_name',
  'job_url',
  'failure_reason',
  'message',
  'runner_id',
  'runner_manager_system_id',
  'runner_version',
];

export const MERGED_MERGE_REQUESTS_COLUMNS = [
  'merged_at',
  'iid',
  'title',
  'web_url',
];

export const SCANNED_PIPELINES_COLUMNS = ['pipeline_id', 'updated_at'];

export const TEST_RUNS_COLUMNS = [
  'pipeline_created_at',
  'pipeline_id',
  'pipeline_ref',
  'pipeline_source',
  'job_id',
  'tests',
];

const FLAKY_TESTS_FILE = 'flaky-tests.csv';
const SCANNED_PIPELINES_FILE = 'scanned-pipelines.csv';
// Pipelines in these states do not change unless a job is retried, which
// updates the pipeline's `updated_at`.
const FINISHED_PIPELINE_STATUSES = [
  'success',
  'failed',
  'canceled',
  'skipped',
  'manual',
];
// The parallel Playwright jobs, e.g. `playwright: [1/6]`.
const PLAYWRIGHT_JOB_NAME = /^playwright(: \[.*\])?$/;
const SHARD_SUMMARY_MARKER = 'CANVAS_PLAYWRIGHT_SHARD_SUMMARY';
// Enough of the end of a job log to contain the shard summary in most cases.
const SHARD_SUMMARY_TAIL_BYTES = 65536;
export const DETECTION_TEST_RETRY = 'test retry';
export const DETECTION_JOB_RETRY = 'job retry';
const MERGED_MERGE_REQUESTS_FILE = 'merged-merge-requests.csv';
// The number of days the chart shows, ending today.
const CHART_DAYS = 90;
const DAY_MS = 24 * 60 * 60 * 1000;
const INFRASTRUCTURE_FAILURES_FILE = 'infrastructure-failures.csv';
const TEST_RUNS_FILE = 'test-runs.csv';
const REPORT_JOB_NAME = 'playwright-reports';
const REPORT_ARTIFACT_PATH = 'test-results/playwright.json';
// Failures caused by the job's own commands. Every other failure reason is
// recorded as an infrastructure failure.
const SCRIPT_FAILURE_REASON = 'script_failure';
// Enough of the end of a job log to contain the runner's final error.
const LOG_TAIL_BYTES = 8192;

/**
 * Extracts the tests with one status from a Playwright JSON report.
 *
 * @param {object} report
 *   A report produced by Playwright's JSON reporter.
 * @param {string} status
 *   `flaky` (failed, then passed on a retry) or `unexpected` (failed every
 *   retry).
 *
 * @returns {Array<{project: string, file: string, line: number, title: string, failedAttempts: number, firstError: string}>}
 */
function extractTests(report, status) {
  const tests = [];
  const visit = (suite, titlePath) => {
    // The top-level suites are files; their titles are the file paths, which
    // are reported separately.
    const path =
      suite.title && suite.title !== suite.file
        ? [...titlePath, suite.title]
        : titlePath;
    for (const spec of suite.specs ?? []) {
      for (const test of spec.tests ?? []) {
        if (test.status !== status) {
          continue;
        }
        const failed = (test.results ?? []).filter(
          (result) => result.status !== 'passed' && result.status !== 'skipped',
        );
        const error = failed.find((result) => result.error)?.error;
        tests.push({
          project: test.projectName ?? '',
          file: spec.file ?? suite.file ?? '',
          line: spec.line ?? '',
          title: [...path, spec.title].join(' › '),
          failedAttempts: failed.length,
          firstError: firstLine(error?.message ?? ''),
        });
      }
    }
    for (const child of suite.suites ?? []) {
      visit(child, path);
    }
  };
  for (const suite of report.suites ?? []) {
    visit(suite, []);
  }
  return tests;
}

export function extractFlakyTests(report) {
  return extractTests(report, 'flaky');
}

export function extractUnexpectedTests(report) {
  return extractTests(report, 'unexpected');
}

/**
 * Counts the tests that ran in a Playwright JSON report.
 *
 * A test that was retried counts once. Skipped tests do not count.
 */
export function countTestRuns(report) {
  let count = 0;
  const visit = (suite) => {
    for (const spec of suite.specs ?? []) {
      count += (spec.tests ?? []).filter(
        (test) => test.status !== 'skipped',
      ).length;
    }
    for (const child of suite.suites ?? []) {
      visit(child);
    }
  };
  for (const suite of report.suites ?? []) {
    visit(suite);
  }
  return count;
}

/**
 * Formats a share as a percentage, with two significant digits below 10%.
 */
export function formatPercent(part, whole) {
  if (!whole) {
    return '–';
  }
  const percent = (part / whole) * 100;
  return `${percent >= 10 ? Math.round(percent) : Number(percent.toPrecision(2))}%`;
}

/**
 * Lists the tests that failed in one Playwright job, as log lines.
 *
 * The `start` and `end` lines show that the list is complete.
 */
export function shardSummaryLines(report) {
  const unexpected = extractUnexpectedTests(report);
  return [
    `${SHARD_SUMMARY_MARKER} start`,
    ...unexpected.map(
      (test) => `${SHARD_SUMMARY_MARKER} unexpected ${JSON.stringify(test)}`,
    ),
    `${SHARD_SUMMARY_MARKER} end ${unexpected.length}`,
  ];
}

/**
 * Reads the failed tests back from a job log written by shardSummaryLines().
 *
 * @returns {Array<object>|null}
 *   The failed tests, or NULL when the log has no complete summary.
 */
export function parseShardSummary(log) {
  const lines = stripAnsi(log)
    .split('\n')
    .filter((line) => line.includes(SHARD_SUMMARY_MARKER))
    .map((line) =>
      line.slice(line.indexOf(SHARD_SUMMARY_MARKER) + SHARD_SUMMARY_MARKER.length + 1),
    );
  const start = lines.lastIndexOf('start');
  const end = lines.findIndex(
    (line, index) => index > start && line.startsWith('end '),
  );
  if (start === -1 || end === -1) {
    return null;
  }
  const unexpected = lines
    .slice(start + 1, end)
    .filter((line) => line.startsWith('unexpected '))
    .map((line) => JSON.parse(line.slice('unexpected '.length)));
  if (unexpected.length !== Number(lines[end].slice('end '.length))) {
    return null;
  }
  return unexpected;
}

/**
 * Finds tests that failed in an earlier attempt of a retried job, but not in
 * its last attempt.
 *
 * @param {Array<Array<object>|null>} summaries
 *   The failed tests of each attempt, oldest first. NULL for an attempt
 *   without a summary. The last attempt's summary must not be NULL.
 *
 * @returns {Array<object>}
 *   The tests, with `failedAttempts` summed over the earlier attempts.
 */
export function findTestsPassingOnJobRetry(summaries) {
  const key = (test) => `${test.project}\u0000${test.file}\u0000${test.title}`;
  const stillFailing = new Set(summaries.at(-1).map(key));
  const recovered = new Map();
  for (const summary of summaries.slice(0, -1)) {
    for (const test of summary ?? []) {
      if (stillFailing.has(key(test))) {
        continue;
      }
      const entry = recovered.get(key(test)) ?? { ...test, failedAttempts: 0 };
      entry.failedAttempts += test.failedAttempts;
      recovered.set(key(test), entry);
    }
  }
  return [...recovered.values()];
}

function stripAnsi(text) {
  // eslint-disable-next-line no-control-regex
  return text.replace(/\u001b\[[0-9;]*[A-Za-z]/g, '');
}

function firstLine(message) {
  const line =
    stripAnsi(message)
      .split('\n')
      .find((part) => part.trim() !== '') ?? '';
  return line.trim().slice(0, 300);
}

/**
 * Extracts the runner's final error from the end of a GitLab job log.
 *
 * For example, `ERROR: Job failed (system failure): pod "…" is disrupted:
 * reason "TerminationByKubelet", message "Pod was terminated in response to
 * imminent node shutdown."` becomes `TerminationByKubelet: Pod was terminated
 * in response to imminent node shutdown.`
 */
export function extractJobFailureMessage(log) {
  const lines = stripAnsi(log)
    .split('\n')
    .filter((line) => line.includes('ERROR: Job failed'));
  const line = lines.at(-1);
  if (!line) {
    return '';
  }
  const error = line.slice(line.indexOf('ERROR: Job failed')).trim();
  const disruption = error.match(/reason "([^"]+)", message "([^"]+)"/);
  if (disruption) {
    return `${disruption[1]}: ${disruption[2]}`;
  }
  return error
    .replace(/^ERROR: Job failed( \([^)]*\))?:\s*/, '')
    .slice(0, 300);
}

/**
 * Shortens a job name to the job it was created from, without its parallel
 * shard. For example `cypress E2E 2/4` becomes `cypress E2E`, and
 * `phpunit (11.3 sqlite): [PHPUnit-Kernel, 4]` becomes `phpunit (11.3 sqlite)`.
 */
export function jobFamily(name) {
  return name
    .replace(/: \[.*\]$/, '')
    .replace(/ \d+\/\d+$/, '')
    .trim();
}

export function toCsv(columns, rows) {
  const escape = (value) => {
    const text = String(value ?? '');
    return /[",\n\r]/.test(text) ? `"${text.replace(/"/g, '""')}"` : text;
  };
  return (
    [columns, ...rows.map((row) => columns.map((column) => row[column]))]
      .map((fields) => fields.map(escape).join(','))
      .join('\n') + '\n'
  );
}

export function parseCsv(text) {
  const records = [];
  let record = [];
  let field = '';
  let quoted = false;
  for (let i = 0; i < text.length; i++) {
    const char = text[i];
    if (quoted) {
      if (char === '"' && text[i + 1] === '"') {
        field += '"';
        i++;
      } else if (char === '"') {
        quoted = false;
      } else {
        field += char;
      }
    } else if (char === '"') {
      quoted = true;
    } else if (char === ',') {
      record.push(field);
      field = '';
    } else if (char === '\n') {
      record.push(field);
      records.push(record);
      record = [];
      field = '';
    } else if (char !== '\r') {
      field += char;
    }
  }
  if (field !== '' || record.length > 0) {
    record.push(field);
    records.push(record);
  }
  const [header, ...data] = records;
  if (!header) {
    return [];
  }
  return data.map((fields) =>
    Object.fromEntries(header.map((column, i) => [column, fields[i] ?? ''])),
  );
}

function escapeHtml(value) {
  return String(value ?? '').replace(
    /[&<>"']/g,
    (char) =>
      ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[
        char
      ],
  );
}

function countBy(rows, key) {
  const counts = new Map();
  for (const row of rows) {
    counts.set(key(row), (counts.get(key(row)) ?? 0) + 1);
  }
  return [...counts.entries()].sort((a, b) => b[1] - a[1]);
}

/**
 * Describes a pipeline ref: `refs/merge-requests/12/merge` becomes `!12`.
 */
export function describeRef(ref) {
  const mergeRequest = String(ref ?? '').match(/^refs\/merge-requests\/(\d+)\//);
  return mergeRequest ? `!${mergeRequest[1]}` : ref || 'unknown';
}

function flakyTestsSection(rows, testRuns, scope) {
  const byTest = new Map();
  for (const row of rows) {
    const key = `${row.project}\u0000${row.file}\u0000${row.title}`;
    const entry = byTest.get(key) ?? { ...row, count: 0 };
    entry.count++;
    if (row.pipeline_created_at >= entry.pipeline_created_at) {
      Object.assign(entry, {
        pipeline_created_at: row.pipeline_created_at,
        job_url: row.job_url,
        line: row.line,
        first_error: row.first_error,
        pipeline_ref: row.pipeline_ref,
        detection: row.detection,
      });
    }
    byTest.set(key, entry);
  }
  const tests = [...byTest.values()].sort(
    (a, b) =>
      b.count - a.count ||
      b.pipeline_created_at.localeCompare(a.pipeline_created_at),
  );
  const pipelineCount = new Set(rows.map((row) => row.pipeline_id)).size;
  // Every pipeline that ran Playwright runs every test, across its shards.
  const testedPipelines = new Set(testRuns.map((row) => row.pipeline_id)).size;
  const tableRows = tests
    .map(
      (test) => `<tr>
  <td class="num">${test.count}</td>
  <td class="num">${formatPercent(test.count, testedPipelines)}</td>
  <td><div>${escapeHtml(test.title)}</div><div class="muted">${escapeHtml(test.file)}:${escapeHtml(test.line)} · ${escapeHtml(test.project)} · last in ${escapeHtml(describeRef(test.pipeline_ref))}${test.detection === DETECTION_JOB_RETRY ? ' · passed on a job retry' : ''}</div><div class="error">${escapeHtml(test.first_error)}</div></td>
  <td><a href="${escapeHtml(test.job_url)}">${escapeHtml(test.pipeline_created_at.slice(0, 10))}</a></td>
</tr>`,
    )
    .join('\n');
  return `<h2>Flaky Playwright tests</h2>
<p class="muted">Tests that failed and then passed on a retry, in ${pipelineCount} pipeline(s) of ${escapeHtml(scope)}. <em>Rate</em> is the share of the ${testedPipelines} recorded pipeline(s) that ran Playwright tests in which the test was flaky. <a href="${FLAKY_TESTS_FILE}">Download CSV</a> (one row per flaky test per pipeline).</p>
${
  tests.length
    ? `<table>
<thead><tr><th class="num">Times</th><th class="num">Rate</th><th>Test</th><th>Last seen</th></tr></thead>
<tbody>
${tableRows}
</tbody>
</table>`
    : '<p>No flaky tests recorded.</p>'
}`;
}

/**
 * Labels the runner of an infrastructure failure row.
 *
 * Jobs on drupalcode.org share one instance runner, so the runner manager
 * (the host process that ran the job) tells them apart. Rows recorded before
 * the runner columns were added have no runner.
 */
export function runnerLabel(row) {
  if (!row.runner_manager_system_id) {
    return row.runner_id ? `runner ${row.runner_id}` : '(unknown)';
  }
  const version = row.runner_version ? ` (${row.runner_version})` : '';
  return `${row.runner_manager_system_id}${version}`;
}

function infrastructureFailuresSection(rows, scope) {
  const countTable = (heading, counts) => `<table>
<thead><tr><th class="num">Jobs</th><th>${heading}</th></tr></thead>
<tbody>
${counts.map(([label, count]) => `<tr><td class="num">${count}</td><td>${escapeHtml(label)}</td></tr>`).join('\n')}
</tbody>
</table>`;
  const recent = [...rows]
    .sort((a, b) => b.job_created_at.localeCompare(a.job_created_at))
    .slice(0, 100)
    .map(
      (row) => `<tr>
  <td><a href="${escapeHtml(row.job_url)}">${escapeHtml(row.job_created_at.slice(0, 16).replace('T', ' '))}</a></td>
  <td><div>${escapeHtml(row.job_name)}</div><div class="muted">${escapeHtml(row.pipeline_ref)} · ${escapeHtml(row.failure_reason)} · ${escapeHtml(runnerLabel(row))}</div><div class="error">${escapeHtml(row.message)}</div></td>
</tr>`,
    )
    .join('\n');
  return `<h2>Infrastructure failures</h2>
<p class="muted">Failed jobs in pipelines of ${escapeHtml(scope)} whose failure reason is not <code>${SCRIPT_FAILURE_REASON}</code>, including jobs that were retried. <a href="${INFRASTRUCTURE_FAILURES_FILE}">Download CSV</a> (one row per failed job).</p>
${
  rows.length
    ? `<div class="grid">
${countTable(
  'Failure reason',
  countBy(rows, (row) => row.failure_reason),
)}
${countTable(
  'Message',
  countBy(rows, (row) => row.message.split(':')[0] || '(none)'),
)}
${countTable(
  'Job',
  countBy(rows, (row) => jobFamily(row.job_name)),
)}
${countTable('Runner', countBy(rows, runnerLabel))}
</div>
<h3>Latest ${Math.min(rows.length, 100)}</h3>
<table>
<thead><tr><th>Started</th><th>Job</th></tr></thead>
<tbody>
${recent}
</tbody>
</table>`
    : '<p>No infrastructure failures recorded.</p>'
}`;
}

/**
 * Groups flaky tests, test runs and merged merge requests by UTC day, for the
 * chart.
 *
 * The days run from the earliest recorded day (at most `days` ago) to today,
 * including days without data. `flakyRuns` counts each flaky test once per
 * pipeline, like `testRuns` counts each test once per pipeline.
 *
 * @returns {Array<{date: string, pipelines: number, testRuns: number, flakyRuns: number, tests: Array<{title: string, file: string, project: string, count: number, refs: string[]}>, mergeRequests: Array<{iid: string, title: string, url: string}>}>}
 */
export function dailySeries(
  flakyTests,
  testRuns,
  mergedMergeRequests,
  { today, days = CHART_DAYS },
) {
  const recorded = [
    ...flakyTests.map((row) => row.pipeline_created_at),
    ...testRuns.map((row) => row.pipeline_created_at),
    ...mergedMergeRequests.map((row) => row.merged_at),
  ]
    .filter(Boolean)
    .sort();
  if (!recorded.length) {
    return [];
  }
  const end = Date.parse(`${today.toISOString().slice(0, 10)}T00:00:00Z`);
  const start = Math.max(
    Date.parse(`${recorded[0].slice(0, 10)}T00:00:00Z`),
    end - (days - 1) * DAY_MS,
  );
  const byDate = new Map();
  for (let time = start; time <= end; time += DAY_MS) {
    const date = new Date(time).toISOString().slice(0, 10);
    byDate.set(date, {
      date,
      pipelines: 0,
      testRuns: 0,
      tests: new Map(),
      mergeRequests: [],
    });
  }
  for (const row of testRuns) {
    const day = byDate.get(row.pipeline_created_at.slice(0, 10));
    if (day) {
      day.pipelines++;
      day.testRuns += Number(row.tests) || 0;
    }
  }
  for (const row of flakyTests) {
    const day = byDate.get(row.pipeline_created_at.slice(0, 10));
    if (!day) {
      continue;
    }
    const key = `${row.project}\u0000${row.file}\u0000${row.title}`;
    const test = day.tests.get(key) ?? {
      title: row.title,
      file: row.file,
      project: row.project,
      count: 0,
      refs: [],
    };
    test.count++;
    const ref = describeRef(row.pipeline_ref);
    if (!test.refs.includes(ref)) {
      test.refs.push(ref);
    }
    day.tests.set(key, test);
  }
  for (const row of [...mergedMergeRequests].sort((a, b) =>
    a.merged_at.localeCompare(b.merged_at),
  )) {
    byDate.get(row.merged_at.slice(0, 10))?.mergeRequests.push({
      iid: row.iid,
      title: row.title,
      url: row.web_url,
    });
  }
  return [...byDate.values()].map((day) => {
    const tests = [...day.tests.values()].sort((a, b) => b.count - a.count);
    return {
      date: day.date,
      pipelines: day.pipelines,
      testRuns: day.testRuns,
      flakyRuns: tests.reduce((sum, test) => sum + test.count, 0),
      tests,
      mergeRequests: day.mergeRequests,
    };
  });
}

/**
 * Draws the daily chart in the browser: the flaky test rate as a line, and
 * test runs and merged merge requests as columns, sharing one day axis, with
 * a tooltip per day.
 *
 * This function is serialized into the page, so it must not use anything
 * from this module.
 */
function renderChart(root, days) {
  const svgNs = 'http://www.w3.org/2000/svg';
  const tooltip = root.querySelector('.tooltip');
  const number = (value) => value.toLocaleString('en-US');
  const percent = (value) =>
    `${value >= 10 ? Math.round(value) : Number(value.toPrecision(2))}%`;
  // NULL on days without test runs, which leaves a gap in the line.
  const rate = (day) =>
    day.testRuns ? (day.flakyRuns / day.testRuns) * 100 : null;
  const panels = [
    {
      label: 'Flaky test runs, % of test runs',
      type: 'line',
      className: 'line-flaky',
      plotHeight: 120,
      value: rate,
      format: percent,
    },
    {
      label: 'Test runs',
      type: 'columns',
      className: 'bar-runs',
      plotHeight: 64,
      value: (day) => day.testRuns,
      format: (value) =>
        value >= 1000 ? `${number(value / 1000)}k` : number(value),
    },
    {
      label: 'Merge requests merged',
      type: 'columns',
      className: 'bar-merged',
      plotHeight: 64,
      value: (day) => day.mergeRequests.length,
      format: number,
    },
  ];
  // A round scale maximum whose half is a round tick too.
  const scaleMax = (highest) => {
    if (highest <= 0) {
      return 1;
    }
    const magnitude = 10 ** Math.floor(Math.log10(highest));
    const step = [1, 2, 4, 6, 8, 10].find((s) => s * magnitude >= highest);
    return step * magnitude;
  };
  const formatDate = (date, options) =>
    new Date(`${date}T00:00:00Z`).toLocaleDateString('en-US', {
      timeZone: 'UTC',
      ...options,
    });
  const element = (name, attributes = {}, parent = null) => {
    const node = document.createElementNS(svgNs, name);
    for (const [key, value] of Object.entries(attributes)) {
      node.setAttribute(key, value);
    }
    parent?.appendChild(node);
    return node;
  };
  const list = (heading, items, limit) => {
    const fragment = document.createDocumentFragment();
    const title = document.createElement('div');
    title.className = 'tooltip-heading';
    title.textContent = heading;
    fragment.appendChild(title);
    for (const item of items.slice(0, limit)) {
      const line = document.createElement('div');
      line.textContent = item;
      fragment.appendChild(line);
    }
    if (items.length > limit) {
      const more = document.createElement('div');
      more.className = 'muted';
      more.textContent = `and ${items.length - limit} more`;
      fragment.appendChild(more);
    }
    return fragment;
  };

  const draw = () => {
    root.querySelector('svg')?.remove();
    tooltip.hidden = true;
    const width = root.clientWidth;
    const margin = { left: 44, right: 44 };
    const labelHeight = 22;
    const panelGap = 24;
    const axisHeight = 22;
    let top = 0;
    for (const panel of panels) {
      panel.top = top;
      panel.baseline = top + labelHeight + panel.plotHeight;
      top = panel.baseline + panelGap;
    }
    const height = top - panelGap + axisHeight;
    const band = (width - margin.left - margin.right) / days.length;
    const barWidth = Math.max(1, Math.min(24, band - 2));
    const x = (index) => margin.left + band * index;
    const center = (index) => x(index) + band / 2;

    const svg = element('svg', {
      width,
      height,
      viewBox: `0 0 ${width} ${height}`,
      role: 'group',
      'aria-label':
        'Flaky test rate, test runs and merged merge requests per day. The table below lists the same data.',
    });
    const highlight = element('rect', {
      class: 'highlight',
      y: 0,
      width: band,
      height: height - axisHeight,
      visibility: 'hidden',
    });
    svg.appendChild(highlight);

    for (const panel of panels) {
      const { top, baseline, plotHeight } = panel;
      const max = scaleMax(Math.max(0, ...days.map(panel.value)));
      const y = (value) => baseline - (value / max) * (plotHeight - 4);
      panel.y = y;
      const label = element(
        'text',
        { class: 'panel-label', x: 0, y: top + 14 },
        svg,
      );
      label.textContent = panel.label;
      for (const tick of [0, max / 2, max]) {
        element(
          'line',
          {
            class: tick ? 'grid-line' : 'baseline',
            x1: margin.left,
            x2: width - margin.right,
            y1: y(tick),
            y2: y(tick),
          },
          svg,
        );
        const text = element(
          'text',
          { class: 'axis', x: margin.left - 6, y: y(tick) + 4, 'text-anchor': 'end' },
          svg,
        );
        text.textContent = panel.format(tick);
      }
      if (panel.type === 'line') {
        let previous = null;
        const d = days
          .map((day, index) => {
            const value = panel.value(day);
            const command =
              value === null ? '' : `${previous === null ? 'M' : 'L'}${center(index)},${y(value)}`;
            previous = value;
            return command;
          })
          .join('');
        element('path', { class: panel.className, d }, svg);
        // A day between two days without test runs has no line to show it.
        days.forEach((day, index) => {
          if (
            panel.value(day) !== null &&
            (days[index - 1] ? panel.value(days[index - 1]) : null) === null &&
            (days[index + 1] ? panel.value(days[index + 1]) : null) === null
          ) {
            element(
              'circle',
              {
                class: `dot ${panel.className}`,
                cx: center(index),
                cy: y(panel.value(day)),
                r: 2,
              },
              svg,
            );
          }
        });
        // The latest value, at the end of the line.
        const last = days.findLastIndex((day) => panel.value(day) !== null);
        if (last !== -1) {
          const end = element(
            'text',
            {
              class: 'end-label',
              x: center(last) + 8,
              y: y(panel.value(days[last])) + 4,
            },
            svg,
          );
          end.textContent = panel.format(panel.value(days[last]));
        }
        // Shown on the hovered day.
        panel.marker = element(
          'circle',
          { class: `marker ${panel.className}`, r: 4, visibility: 'hidden' },
          svg,
        );
        continue;
      }
      days.forEach((day, index) => {
        const value = panel.value(day);
        if (!value) {
          return;
        }
        const left = x(index) + (band - barWidth) / 2;
        const right = left + barWidth;
        const topY = y(value);
        const radius = Math.min(4, barWidth / 2, baseline - topY);
        element(
          'path',
          {
            class: panel.className,
            d: `M${left},${baseline}V${topY + radius}Q${left},${topY} ${left + radius},${topY}H${right - radius}Q${right},${topY} ${right},${topY + radius}V${baseline}Z`,
          },
          svg,
        );
      });
    }

    // Date labels, spaced at least 64px apart and ending on the last day.
    const step = Math.max(1, Math.ceil(64 / band));
    days.forEach((day, index) => {
      if ((days.length - 1 - index) % step) {
        return;
      }
      const text = element(
        'text',
        {
          class: 'axis',
          x: x(index) + band / 2,
          y: height - 6,
          'text-anchor': 'middle',
        },
        svg,
      );
      text.textContent = formatDate(day.date, {
        month: 'short',
        day: 'numeric',
      });
    });

    const show = (day, index) => {
      highlight.setAttribute('x', x(index));
      highlight.setAttribute('visibility', 'visible');
      for (const panel of panels.filter((candidate) => candidate.marker)) {
        const value = panel.value(day);
        panel.marker.setAttribute('cx', center(index));
        panel.marker.setAttribute('cy', panel.y(value ?? 0));
        panel.marker.setAttribute(
          'visibility',
          value === null ? 'hidden' : 'visible',
        );
      }
      tooltip.replaceChildren();
      const date = document.createElement('div');
      date.className = 'tooltip-date';
      date.textContent = formatDate(day.date, {
        weekday: 'short',
        month: 'short',
        day: 'numeric',
        year: 'numeric',
      });
      tooltip.appendChild(date);
      const summary = document.createElement('div');
      summary.textContent = day.testRuns
        ? `${percent(rate(day))} flaky: ${number(day.flakyRuns)} of ${number(day.testRuns)} test runs in ${number(day.pipelines)} pipeline(s)`
        : 'No test runs recorded';
      tooltip.appendChild(summary);
      tooltip.appendChild(
        list(
          `${day.tests.length} flaky test(s)`,
          day.tests.map(
            (test) =>
              `${test.title}${test.count > 1 ? ` (×${test.count})` : ''} · ${test.refs.join(', ')}`,
          ),
          6,
        ),
      );
      tooltip.appendChild(
        list(
          `${day.mergeRequests.length} merge request(s) merged`,
          day.mergeRequests.map((mr) => `!${mr.iid} ${mr.title}`),
          8,
        ),
      );
      tooltip.hidden = false;
      // Beside the day, on the side with more room, so the day's own bars
      // stay visible.
      const gap = 8;
      const onLeft = x(index) + band / 2 > width / 2;
      const left = Math.min(
        Math.max(
          0,
          onLeft
            ? x(index) - gap - tooltip.offsetWidth
            : x(index) + band + gap,
        ),
        width - tooltip.offsetWidth,
      );
      tooltip.style.left = `${left}px`;
    };
    const hide = () => {
      highlight.setAttribute('visibility', 'hidden');
      for (const panel of panels.filter((candidate) => candidate.marker)) {
        panel.marker.setAttribute('visibility', 'hidden');
      }
      tooltip.hidden = true;
    };
    days.forEach((day, index) => {
      const target = element(
        'rect',
        {
          class: 'hit',
          x: x(index),
          y: 0,
          width: band,
          height: height - axisHeight,
          tabindex: 0,
          'aria-label': `${day.date}: ${day.testRuns ? `${percent(rate(day))} of ${number(day.testRuns)} test runs flaky` : 'no test runs'}, ${day.tests.length} flaky test(s), ${day.mergeRequests.length} merge request(s) merged`,
        },
        svg,
      );
      target.addEventListener('pointerenter', () => show(day, index));
      target.addEventListener('focus', () => show(day, index));
      target.addEventListener('pointerleave', hide);
      target.addEventListener('blur', hide);
    });
    root.prepend(svg);
  };

  new ResizeObserver(draw).observe(root);
}

function chartSection(days) {
  if (!days.length) {
    return '';
  }
  const data = JSON.stringify(days).replace(/</g, '\\u003c');
  const tableRows = [...days]
    .reverse()
    .filter(
      (day) => day.testRuns || day.tests.length || day.mergeRequests.length,
    )
    .map(
      (day) => `<tr>
  <td>${day.date}</td>
  <td class="num">${day.testRuns.toLocaleString('en-US')}</td>
  <td class="num">${day.flakyRuns}</td>
  <td class="num">${formatPercent(day.flakyRuns, day.testRuns)}</td>
  <td>${day.tests.map((test) => `<div>${escapeHtml(test.title)} <span class="muted">${escapeHtml(test.refs.join(', '))}</span></div>`).join('')}</td>
  <td>${day.mergeRequests.map((mr) => `<div><a href="${escapeHtml(mr.url)}">!${escapeHtml(mr.iid)}</a> ${escapeHtml(mr.title)}</div>`).join('')}</td>
</tr>`,
    )
    .join('\n');
  return `<h2>Per day</h2>
<p class="muted">Per day (UTC), in all recorded pipelines: the share of test runs that were flaky, where each test counts once per pipeline; the number of test runs; and the merge requests merged into the default branch. Hover or focus a day for details.</p>
<div class="chart" id="chart"><div class="tooltip" role="status" hidden></div></div>
<script>(${renderChart.toString()})(document.getElementById('chart'), ${data});</script>
<details>
<summary>By day</summary>
<div class="table-scroll">
<table>
<thead><tr><th>Date</th><th class="num">Test runs</th><th class="num">Flaky</th><th class="num">Rate</th><th>Flaky tests</th><th>Merge requests merged</th></tr></thead>
<tbody>
${tableRows}
</tbody>
</table>
</div>
</details>`;
}

/**
 * Renders the summary page for all records.
 */
export function toHtml(
  { flakyTests, testRuns, infrastructureFailures, mergedMergeRequests },
  { generatedAt, scope },
) {
  return `<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>CI reliability</title>
<style>
  :root { color-scheme: light dark; --fg: #1a1a1a; --bg: #fcfcfb; --muted: #5f5e5a; --line: #e4e3df; --error: #a40000; --flaky: #2a78d6; --merged: #eb6834; --runs: #8a8983; --hover: rgb(0 0 0 / 6%); --tooltip-bg: #fff; }
  @media (prefers-color-scheme: dark) { :root { --fg: #f0efec; --bg: #1a1a19; --muted: #b0afa8; --line: #33332f; --error: #ff8a80; --flaky: #3987e5; --merged: #d95926; --runs: #74736d; --hover: rgb(255 255 255 / 8%); --tooltip-bg: #262624; } }
  body { margin: 0 auto; max-width: 1100px; padding: 16px; font: 15px/1.45 system-ui, sans-serif; color: var(--fg); background: var(--bg); }
  h2 { margin-top: 40px; }
  table { width: 100%; border-collapse: collapse; }
  th, td { text-align: left; vertical-align: top; padding: 8px; border-bottom: 1px solid var(--line); }
  .num { text-align: right; font-variant-numeric: tabular-nums; width: 4em; }
  .muted { color: var(--muted); font-size: 13px; }
  .error { color: var(--error); font: 12px/1.4 ui-monospace, monospace; overflow-wrap: anywhere; }
  .grid { display: grid; gap: 16px; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); align-items: start; }
  a { color: inherit; }
  .chart { position: relative; margin: 8px 0 16px; }
  .chart svg { display: block; overflow: visible; }
  .chart .panel-label { fill: var(--fg); font-size: 13px; font-weight: 600; }
  .chart .axis { fill: var(--muted); font-size: 12px; font-variant-numeric: tabular-nums; }
  .chart .grid-line { stroke: var(--line); stroke-width: 1; }
  .chart .baseline { stroke: var(--muted); stroke-width: 1; }
  .chart .line-flaky { fill: none; stroke: var(--flaky); stroke-width: 2; stroke-linejoin: round; stroke-linecap: round; }
  .chart .marker.line-flaky { fill: var(--flaky); stroke: var(--bg); stroke-width: 2; }
  .chart .end-label { fill: var(--fg); font-size: 12px; font-variant-numeric: tabular-nums; }
  .chart .bar-merged { fill: var(--merged); }
  .chart .bar-runs { fill: var(--runs); }
  .chart .dot.line-flaky { fill: var(--flaky); stroke: none; }
  .chart .highlight { fill: var(--hover); }
  .chart .hit { fill: transparent; outline: none; }
  .chart .tooltip { box-sizing: border-box; position: absolute; top: 0; z-index: 1; max-width: min(420px, 100%); padding: 8px 10px; border: 1px solid var(--line); border-radius: 6px; background: var(--tooltip-bg); box-shadow: 0 2px 8px rgb(0 0 0 / 15%); font-size: 13px; pointer-events: none; }
  .chart .tooltip > div { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .chart .tooltip-date { font-weight: 600; }
  .chart .tooltip-heading { margin-top: 6px; color: var(--muted); }
  td { overflow-wrap: anywhere; }
  .table-scroll { overflow-x: auto; }
  details { margin: 8px 0; }
  summary { cursor: pointer; }
</style>
</head>
<body>
<h1>CI reliability</h1>
<p class="muted">Generated ${escapeHtml(generatedAt)}.</p>
${chartSection(
  dailySeries(flakyTests, testRuns, mergedMergeRequests, {
    today: new Date(generatedAt),
  }),
)}
${flakyTestsSection(flakyTests, testRuns, scope)}
${infrastructureFailuresSection(infrastructureFailures, scope)}
</body>
</html>
`;
}

async function request(url, headers = {}) {
  for (let attempt = 1; ; attempt++) {
    const response = await fetch(url, { headers });
    if (response.status !== 429 || attempt === 5) {
      return response;
    }
    // GitLab throttles unauthenticated API requests per minute.
    const seconds = Number(response.headers.get('retry-after')) || 60;
    console.log(`Rate limited; waiting ${seconds}s.`);
    await new Promise((resolve) => setTimeout(resolve, seconds * 1000));
  }
}

async function api(path, { allowNotFound = false } = {}) {
  const headers = process.env.FLAKY_TESTS_API_TOKEN
    ? { 'PRIVATE-TOKEN': process.env.FLAKY_TESTS_API_TOKEN }
    : {};
  const response = await request(
    `${process.env.CI_API_V4_URL}${path}`,
    headers,
  );
  if (allowNotFound && response.status === 404) {
    return null;
  }
  if (!response.ok) {
    throw new Error(
      `GitLab API error for ${path}: ${response.status} ${response.statusText}`,
    );
  }
  return response;
}

async function paginate(path) {
  const items = [];
  for (let page = 1; ; page++) {
    const separator = path.includes('?') ? '&' : '?';
    const response = await api(`${path}${separator}per_page=100&page=${page}`);
    const batch = await response.json();
    items.push(...batch);
    if (!response.headers.get('x-next-page')) {
      return items;
    }
  }
}

/**
 * Fetches a job log, or its last `tailBytes` bytes.
 *
 * @returns {Promise<string|null>}
 */
async function fetchJobLog(job, tailBytes = 0) {
  // The web route serves public job logs without a token, unlike the API.
  const response = await request(
    `${job.web_url}/raw`,
    tailBytes ? { Range: `bytes=-${tailBytes}` } : {},
  );
  return response.ok ? response.text() : null;
}

async function fetchJobFailureMessage(job) {
  const log = await fetchJobLog(job, LOG_TAIL_BYTES);
  return log ? extractJobFailureMessage(log) : '';
}

async function fetchShardSummary(job) {
  // A job that passed has no failed tests, even if its summary is missing.
  if (job.status === 'success') {
    return [];
  }
  const tail = await fetchJobLog(job, SHARD_SUMMARY_TAIL_BYTES);
  const summary = tail ? parseShardSummary(tail) : null;
  if (summary || !tail?.includes(`${SHARD_SUMMARY_MARKER} end`)) {
    return summary;
  }
  // The summary starts before the fetched tail.
  const log = await fetchJobLog(job);
  return log ? parseShardSummary(log) : null;
}

async function fetchPreviousRows(file) {
  const base = process.env.FLAKY_TESTS_PREVIOUS_URL;
  if (!base) {
    return [];
  }
  const url = `${base.replace(/\/$/, '')}/${file}`;
  const response = await request(url);
  if (response.status === 404) {
    console.log(`No previous record at ${url}; starting a new one.`);
    return [];
  }
  // Any other failure aborts, so a transient error never replaces the
  // published history with a partial record.
  if (!response.ok) {
    throw new Error(
      `Could not fetch the previous record from ${url}: ${response.status} ${response.statusText}`,
    );
  }
  return parseCsv(await response.text());
}

async function main(outputDirectory) {
  const projectPath = `/projects/${encodeURIComponent(process.env.CI_PROJECT_ID)}`;
  const defaultBranch = process.env.CI_DEFAULT_BRANCH;
  const previousFlakyTests = await fetchPreviousRows(FLAKY_TESTS_FILE);
  const previousInfrastructureFailures = await fetchPreviousRows(
    INFRASTRUCTURE_FAILURES_FILE,
  );
  const previousMergedMergeRequests = await fetchPreviousRows(
    MERGED_MERGE_REQUESTS_FILE,
  );
  const previousScannedPipelines = await fetchPreviousRows(
    SCANNED_PIPELINES_FILE,
  );
  // One row per pipeline; scanning it again replaces the row.
  const testRunsByPipeline = new Map(
    (await fetchPreviousRows(TEST_RUNS_FILE)).map((row) => [
      row.pipeline_id,
      row,
    ]),
  );
  const scannedPipelines = new Map(
    previousScannedPipelines.map((row) => [row.pipeline_id, row.updated_at]),
  );
  const flakyTestKey = (row) =>
    [row.pipeline_id, row.project, row.file, row.title].join('\u0000');
  const knownFlakyTests = new Set(previousFlakyTests.map(flakyTestKey));
  const knownFailedJobs = new Set(
    previousInfrastructureFailures.map((row) => row.job_id),
  );

  // The window overlaps previous runs, so pipelines that were still running
  // during a previous run are picked up. Recorded data is skipped.
  const days = Number(process.env.FLAKY_TESTS_DAYS || 7);
  const since = new Date(
    Date.now() - days * 24 * 60 * 60 * 1000,
  ).toISOString();

  const scope = 'all branches and merge requests';
  console.log(`Scanning pipelines updated since ${since}: ${scope}.`);

  const pipelines = await paginate(
    `${projectPath}/pipelines?updated_after=${encodeURIComponent(since)}`,
  );
  console.log(`${pipelines.length} pipeline(s) to scan.`);

  const newFlakyTests = [];
  const newInfrastructureFailures = [];
  const addFlakyTests = (pipeline, job, tests, detection) => {
    for (const test of tests) {
      const row = {
        pipeline_created_at: pipeline.created_at,
        pipeline_id: String(pipeline.id),
        pipeline_ref: pipeline.ref,
        pipeline_source: pipeline.source,
        commit_sha: pipeline.sha,
        job_id: job.id,
        job_url: job.web_url,
        project: test.project,
        file: test.file,
        line: test.line,
        title: test.title,
        failed_attempts: test.failedAttempts,
        first_error: test.firstError,
        detection,
      };
      if (!knownFlakyTests.has(flakyTestKey(row))) {
        knownFlakyTests.add(flakyTestKey(row));
        newFlakyTests.push(row);
      }
    }
  };
  let skipped = 0;
  for (const pipeline of pipelines) {
    if (scannedPipelines.get(String(pipeline.id)) === pipeline.updated_at) {
      skipped++;
      continue;
    }
    const jobs = await paginate(
      `${projectPath}/pipelines/${pipeline.id}/jobs?include_retried=true&scope[]=failed&scope[]=success`,
    );

    for (const job of jobs) {
      if (
        job.status !== 'failed' ||
        job.failure_reason === SCRIPT_FAILURE_REASON ||
        knownFailedJobs.has(String(job.id))
      ) {
        continue;
      }
      const message = await fetchJobFailureMessage(job);
      console.log(
        `Pipeline ${pipeline.id}: ${job.name} failed (${job.failure_reason}) on runner manager ${job.runner_manager?.system_id ?? '(unknown)'}: ${message}`,
      );
      newInfrastructureFailures.push({
        job_created_at: job.created_at,
        pipeline_id: pipeline.id,
        pipeline_ref: pipeline.ref,
        pipeline_source: pipeline.source,
        job_id: job.id,
        job_name: job.name,
        job_url: job.web_url,
        failure_reason: job.failure_reason ?? '',
        message,
        runner_id: job.runner?.id ?? '',
        runner_manager_system_id: job.runner_manager?.system_id ?? '',
        runner_version: job.runner_manager?.version ?? '',
      });
    }

    // A retried report job is listed too; the newest one has the final result.
    const reportJob = jobs
      .filter((job) => job.name === REPORT_JOB_NAME)
      .sort((a, b) => b.id - a.id)[0];
    const artifact = reportJob
      ? await api(
          `${projectPath}/jobs/${reportJob.id}/artifacts/${REPORT_ARTIFACT_PATH}`,
          { allowNotFound: true },
        )
      : null;
    // No artifact: expired, or from before the JSON report existed.
    if (artifact) {
      const report = await artifact.json();
      testRunsByPipeline.set(String(pipeline.id), {
        pipeline_created_at: pipeline.created_at,
        pipeline_id: String(pipeline.id),
        pipeline_ref: pipeline.ref,
        pipeline_source: pipeline.source,
        job_id: reportJob.id,
        tests: countTestRuns(report),
      });
      const flaky = extractFlakyTests(report);
      console.log(`Pipeline ${pipeline.id}: ${flaky.length} flaky test(s).`);
      addFlakyTests(pipeline, reportJob, flaky, DETECTION_TEST_RETRY);
    }

    // Playwright jobs that ran more than once, e.g. retried in the GitLab UI.
    const attemptsByName = Map.groupBy(
      jobs
        .filter((job) => PLAYWRIGHT_JOB_NAME.test(job.name))
        .sort((a, b) => a.id - b.id),
      (job) => job.name,
    );
    for (const attempts of attemptsByName.values()) {
      if (attempts.length < 2) {
        continue;
      }
      const summaries = [];
      for (const attempt of attempts) {
        summaries.push(await fetchShardSummary(attempt));
      }
      if (!summaries.at(-1)) {
        // The outcome of the last attempt is unknown.
        continue;
      }
      const recovered = findTestsPassingOnJobRetry(summaries);
      console.log(
        `Pipeline ${pipeline.id}: ${attempts[0].name} ran ${attempts.length} times; ${recovered.length} test(s) passed on a job retry.`,
      );
      addFlakyTests(
        pipeline,
        attempts.at(-1),
        recovered,
        DETECTION_JOB_RETRY,
      );
    }

    if (FINISHED_PIPELINE_STATUSES.includes(pipeline.status)) {
      scannedPipelines.set(String(pipeline.id), pipeline.updated_at);
    }
  }
  console.log(`Skipped ${skipped} pipeline(s) that did not change.`);

  const knownMergeRequests = new Set(
    previousMergedMergeRequests.map((row) => row.iid),
  );
  const newMergedMergeRequests = (
    await paginate(
      `${projectPath}/merge_requests?state=merged&target_branch=${encodeURIComponent(defaultBranch)}&updated_after=${encodeURIComponent(since)}`,
    )
  )
    .filter(
      (mr) =>
        mr.merged_at &&
        mr.merged_at >= since &&
        !knownMergeRequests.has(String(mr.iid)),
    )
    .map((mr) => ({
      merged_at: mr.merged_at,
      iid: mr.iid,
      title: mr.title,
      web_url: mr.web_url,
    }));

  const byDate = (key) => (a, b) =>
    String(a[key]).localeCompare(String(b[key]));
  const flakyTests = [...previousFlakyTests, ...newFlakyTests].sort(
    byDate('pipeline_created_at'),
  );
  const infrastructureFailures = [
    ...previousInfrastructureFailures,
    ...newInfrastructureFailures,
  ].sort(byDate('job_created_at'));
  const mergedMergeRequests = [
    ...previousMergedMergeRequests,
    ...newMergedMergeRequests,
  ].sort(byDate('merged_at'));
  const testRuns = [...testRunsByPipeline.values()].sort(
    byDate('pipeline_created_at'),
  );

  mkdirSync(outputDirectory, { recursive: true });
  // Pipelines last updated before the window are never listed again.
  writeFileSync(
    join(outputDirectory, SCANNED_PIPELINES_FILE),
    toCsv(
      SCANNED_PIPELINES_COLUMNS,
      [...scannedPipelines]
        .filter(([, updatedAt]) => updatedAt >= since)
        .map(([pipelineId, updatedAt]) => ({
          pipeline_id: pipelineId,
          updated_at: updatedAt,
        })),
    ),
  );
  writeFileSync(
    join(outputDirectory, FLAKY_TESTS_FILE),
    toCsv(FLAKY_TESTS_COLUMNS, flakyTests),
  );
  writeFileSync(
    join(outputDirectory, TEST_RUNS_FILE),
    toCsv(TEST_RUNS_COLUMNS, testRuns),
  );
  writeFileSync(
    join(outputDirectory, INFRASTRUCTURE_FAILURES_FILE),
    toCsv(INFRASTRUCTURE_FAILURES_COLUMNS, infrastructureFailures),
  );
  writeFileSync(
    join(outputDirectory, MERGED_MERGE_REQUESTS_FILE),
    toCsv(MERGED_MERGE_REQUESTS_COLUMNS, mergedMergeRequests),
  );
  writeFileSync(
    join(outputDirectory, 'index.html'),
    toHtml(
      { flakyTests, testRuns, infrastructureFailures, mergedMergeRequests },
      { generatedAt: new Date().toISOString(), scope },
    ),
  );
  console.log(
    `Recorded ${newFlakyTests.length} new flaky test run(s), ${flakyTests.length} in total.`,
  );
  console.log(
    `Recorded ${newInfrastructureFailures.length} new infrastructure failure(s), ${infrastructureFailures.length} in total.`,
  );
  console.log(
    `Recorded ${newMergedMergeRequests.length} new merged merge request(s), ${mergedMergeRequests.length} in total.`,
  );
}

if (
  process.argv[1] === fileURLToPath(import.meta.url) &&
  process.argv[2] === '--shard-summary'
) {
  const report = JSON.parse(readFileSync(process.argv[3], 'utf8'));
  console.log(shardSummaryLines(report).join('\n'));
} else if (process.argv[1] === fileURLToPath(import.meta.url)) {
  if (!process.argv[2]) {
    console.error(
      'Usage: node scripts/flaky-tests-report.js <output-directory>',
    );
    process.exit(1);
  }
  main(process.argv[2]).catch((error) => {
    console.error(error.message);
    process.exit(1);
  });
}
