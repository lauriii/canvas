# Playwright

[Playwright](https://playwright.dev/) provides cross-browser functional testing.
These tests are in the `tests/src/Playwright` folder and make use of the recipes
located in `tests/fixtures/recipes`.

>>>
❗ Setup
First follow the global [setup instructions](./setup.md) to ensure you have all
the required PHP dependencies.
>>>

## Running Tests

If you are not using ddev, copy `.env.defaults` to `.env` and modify the values
as appropriate.

From the root of this module, install the dependencies:
```
npm install
# Install system dependencies
npx playwright install-deps chromium
```

Then run the tests:
```
npm run test:playwright
```
This is the same script which runs in CI. It installs the Chromium headless
shell, which is all that headless test runs use, and then runs the tests. You
can also run more specific Playwright commands. For example, a single test:
```
npx playwright test canary.spec.ts
```

Run your tests with UI Mode for a better developer experience with time travel
debugging, watch mode and more:

```
npx playwright test canary.spec.ts --ui
```

Headed runs (`--headed`, `--debug`, and the "show browser" option in UI Mode)
need the full Chromium browser, not only the headless shell. Install it once:
```
npx playwright install chromium
```

A full list of commands is available here:
https://playwright.dev/docs/running-tests#running-tests

## Writing Tests

Import the `DrupalSite` fixture and this will setup and manage a minimal Drupal
site install for testing. All tests in one file are run in serial, whilst
separate files are run in parallel. You can override this per [test file](https://playwright.dev/docs/test-parallel#parallelize-tests-in-a-single-file)
and opt into fully parallel mode, however keep in mind that for each test worker
that starts it will install a new Drupal site. You will also incur any
additional overhead required to set up Drupal Canvas for each worker.

However, you should still write each test as if it could run in parallel i.e.
do not rely on a state from the previous test. This is because when a test fails,
crashes, or flakes, then it can retry. It will do this by first running the
`beforeAll` function, and then skipping straight to the test in question.

```typescript
import { expect } from '@playwright/test';
import { test } from './fixtures/DrupalSite';

test.describe('Canary', () => {

  test('Login', async ({ page, drupal }) => {
    await page.goto('/user/login');
    await expect(page).toHaveTitle('Log in | Drupal');
    await drupal.loginAsAdmin();
    await expect(page.locator('h1')).not.toHaveText('admin');
  });
});
```

A [`drupal` object](../../tests/src/Playwright/objects/Drupal.ts) is available
using Playwright's [Page object model](https://playwright.dev/docs/pom)
structure that contains many useful functions.

## DDEV

No additional setup is needed for this to run within DDEV in headless mode,
however if you want to use the UI mode within DDEV or watch the tests run in
headed mode then you can install https://github.com/justafish/ddev-drupal-playwright

e.g.
```
ddev exec -d /var/www/html/modules/contrib/canvas npx playwright test canary.spec.ts --headed
```

and then watch via web VNC at http://drupal.ddev.site:7905/ or connect directly
on http://drupal.ddev.site:5905

## Test Recipe
If you would like to setup a copy of the site locally to be in the same state as
running `drupal.setupCanvasTestSite()` you can do so with the following commands:

```
drush site:install minimal
drush recipe modules/contrib/canvas/tests/fixtures/recipes/base
```
You will then need to allow test modules to be enabled, if you're using `ddev-drupal-xb-dev` you can do this with:
```
ddev drupal test:extensions-enable
```
or manually add the following to `settings.php`:
```
$settings['extension_discovery_scan_tests'] = TRUE;
```

Then install the test modules and test site content:
```
drush pm:install canvas_test_sdc canvas_test_code_components
drush recipe modules/contrib/canvas/tests/fixtures/recipes/test_site
```

See the [core recipes documentation](https://www.drupal.org/docs/extending-drupal/drupal-recipes)
if you would like to make changes to the test site.

## Flaky tests

On CI, each failing test is retried up to 3 times. A test that fails and then
passes on a retry is flaky. A test that fails all its retries, and passes after
its `playwright` job is retried in GitLab, is flaky too.

The nightly `flaky tests report` job records these tests. It reads
`test-results/playwright.json` from the `playwright-reports` job of all
recent pipelines: pushes to branches, and merge requests. Each row records the
pipeline ref, so results from the default branch and from merge requests can
be told apart. The same file gives the number of tests that ran in each
pipeline. A test counts once per pipeline, however often it was retried.
Skipped tests do not count.

Each `playwright` job prints the tests that failed in it to the end of its
log, between `CANVAS_PLAYWRIGHT_SHARD_SUMMARY` lines. When a `playwright` job
ran more than once in a pipeline, the report compares these lists. A test that
failed in an earlier attempt, but not in the last attempt, is recorded with
the detection `job retry`. Other flaky tests have the detection `test retry`.
Job logs are read because the blob report artifacts expire after one day.

The same job also records infrastructure failures in all pipelines: failed
jobs whose GitLab failure reason is not `script_failure`. For example, a runner
pod that was terminated (`runner_system_failure`) or a job that exceeded its
timeout (`job_execution_timeout`). Retried jobs are included. The message is
read from the end of the job log. Each failure records the runner, and the
runner manager that ran the job: its system ID and GitLab Runner version. All
jobs on drupalcode.org use the same instance runner, so the runner manager
identifies the host.

The job also records the merge requests merged into the default branch.

The job appends new results to the CSV files that are already published, and
publishes the result to GitLab Pages under `flaky-tests/`:

- `flaky-tests/index.html`:
  - a chart for the last 90 days with three panels that share one day axis:
    - the flaky test rate per day: flaky tests divided by test runs, where
      each test counts once per pipeline. More pipelines on a day do not
      raise the rate.
    - test runs per day.
    - merge requests merged per day.

    Hovering or focusing a day lists its rate, flaky tests and merge
    requests. A table below the chart lists the same data.
  - flaky tests, most frequently flaky first. The rate is the share of
    recorded pipelines that ran Playwright tests in which the test was flaky.
  - infrastructure failures counted by reason, message, job, and runner
    manager.
- `flaky-tests/flaky-tests.csv`: one row per flaky test per pipeline.
- `flaky-tests/test-runs.csv`: one row per pipeline with a Playwright report,
  with the number of tests that ran.
- `flaky-tests/infrastructure-failures.csv`: one row per failed job.
- `flaky-tests/merged-merge-requests.csv`: one row per merged merge request.
- `flaky-tests/scanned-pipelines.csv`: the pipelines already scanned, and when
  they were last updated. Pipelines that did not change since are skipped.

The job can also be run manually from a pipeline on the default branch started
in the GitLab UI. Variables:

- `FLAKY_TESTS_DAYS`: days of pipelines to scan. Defaults to 7. Set a
  higher value to backfill. Pipelines from before the JSON report was added
  have no flaky test data.
- `FLAKY_TESTS_API_TOKEN`: an access token. Only needed when pipelines are not
  public.

The script is [`scripts/flaky-tests-report.js`](../../scripts/flaky-tests-report.js).
