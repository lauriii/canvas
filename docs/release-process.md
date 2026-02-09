# Release process

To create a new release of the Canvas module:

1. Go to https://git.drupalcode.org/project/canvas/-/tags/new.
2. Create a new tag prefixed with `v`, for example, `v1.0.3`.
3. Track the resulting pipeline at https://git.drupalcode.org/project/canvas/-/pipelines.
4. After the pipeline succeeds, use the created `1.0.3`-style tag to publish the release on Drupal.org by following the [usual instructions](https://www.drupal.org/docs/develop/git/git-for-drupal-project-maintainers/creating-a-project-release#s-publishing-a-release).

## How the release works

When a `v`-prefixed tag (for example, `v1.0.3`) is pushed, the `release` job in `.gitlab-ci.yml`:

1. Builds UI assets by running `npm ci` and `npm run build` in `ui/`.
2. Creates a release commit. Built assets (`ui/dist/`, `packages/astro-hydration/dist/`) are normally ignored, so the job temporarily un-ignores them, stages them, and commits them.
3. Creates a tag without the `v` prefix. The `VERSION` variable strips the first character from `CI_COMMIT_TAG` with `cut -c2-`, so `v1.0.3` becomes `1.0.3`. This tag points to the release commit that contains built assets.
4. Pushes only the new release tag. GitLab CI checks out tags in detached HEAD state, so this release commit is not added to `1.x`.

This produces two tags:

- `v1.0.3`: the trigger tag, which points to the clean commit on `1.x`.
- `1.0.3`: the release tag, which points to the commit that contains pre-built assets. Drupal.org expects this unprefixed format to recognize a project release.

This keeps `1.x` clean while ensuring release archives include built assets for installation and Drupal.org packaging.
