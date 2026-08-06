# GitLab CI

This project includes automated testing with GitLab CI, configured through
[.gitlab-ci.yml](../../.gitlab-ci.yml),.

Some jobs extend from the [drupal.org GitLab CI templates](https://git.drupalcode.org/project/gitlab_templates/-/blob/main/includes/include.drupalci.main.yml).

If you want to run a test locally exactly as it would be run in the CI, you can
do so by installing [gitlab-ci-local](https://github.com/firecow/gitlab-ci-local).
Then, run the following, replacing the job name where appropriate:

```shell
gitlab-ci-local \
        --remote-variables git@git.drupal.org:project/gitlab_templates=includes/include.drupalci.variables.yml=main \
        --variable="_GITLAB_TEMPLATES_REPO=project/gitlab_templates" "lint (php)"
```

## Tracked Files

Untracked and ignored files will not be synced inside isolated jobs, only tracked
files are synced, so remember to `git add` first.

## Cypress E2E parallelization

The `cypress E2E` job runs the specs in `ui/tests/e2e/` across several parallel
nodes (`parallel:` in [.gitlab-ci.yml](../../.gitlab-ci.yml)).
[`ui/tests/support/parallel.js`](../../ui/tests/support/parallel.js) decides which
specs each node runs, balancing them by expected runtime rather than by count.

Because that script runs before the tests, it can't measure timings — instead it
reads an optional marker on the first line of a spec:

```js
// @canvas-ci weight=12
// @canvas-ci flaky
// @canvas-ci weight=13 flaky
```

- `weight=N` — the spec's relative runtime, one unit ≈ 30 seconds (default 1).
  Tag a spec once it runs longer than ~2 minutes so it is spread across nodes
  instead of stacking on one; seed the number from the spec's CI duration.
- `flaky` — the spec occasionally needs retries. Flaky specs are spread across
  nodes so their retries don't pile up, and the job auto-retries when one fails.
  The retry guard reads the tagged specs via
  [`flaky-specs.js`](../../ui/tests/support/flaky-specs.js), so this marker is the
  single source of truth — there is no separate flaky list in `.gitlab-ci.yml`.
