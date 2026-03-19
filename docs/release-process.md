# Release process

## Determining next version

The Canvas project uses
[conventional commits](https://www.conventionalcommits.org/en/v1.0.0/) and
[semantic versioning](https://semver.org/).

- **Patch** (e.g. `1.0.3` → `1.0.4`): bug fixes and task updates only.
- **Minor** (e.g. `1.0.3` → `1.1.0`): the release includes one or more `feat`
  commits. (Note that the default suggested commit message by Drupal.org's
  Contribution records UI will always suggest `feat`.)
- **Major** (e.g. `1.1.0` → `2.0.0`): reserved for breaking changes. A major
  version bump should be discussed and agreed upon by the team before tagging.

## How to do a release

### 1. Pre-release maintenance of JavaScript dependencies

Before creating a release, audit the JavaScript packages in the Canvas repo. Run
the following commands from the project root:

```bash
nvm use \
  && npm ci \
  && npm audit fix
```

If `npm audit fix` reports vulnerabilities that cannot be resolved
automatically, investigate and fix them manually. Once all issues are resolved,
create a Drupal.org issue titled "Update JS packages", submit a merge request,
and get it merged. After the issue is marked as fixed, you can proceed with
triggering the release automation.

### 2. Triggering the release

1. Go to https://git.drupalcode.org/project/canvas/-/tags/new.
2. Create a new tag from the branch you are releasing (`1.x` for a standard
   release, or the hotfix branch for a hotfix release).
3. Name the tag according to your version, prefixed with a `v` (for example,
   `v1.0.4`).
4. Track the resulting pipeline at
   https://git.drupalcode.org/project/canvas/-/pipelines. Make sure it succeeds.

### 3. Publishing on Drupal.org

After the pipeline succeeds, it automatically creates and pushes a new
unprefixed tag (without `v`) to the repository. Drupal.org reads this tag from
the repository and makes it available in the release publish form.

1. Publish the release following the
   [usual instructions](https://www.drupal.org/docs/develop/git/git-for-drupal-project-maintainers/creating-a-project-release#s-publishing-a-release).
2. Write 1-3 short paragraphs for the release notes.
3. Edit the body field's summary to make sure the content is right for social
   previews.
4. Review draft change records; publish any that should be included in this
   release.
5. Review published change records and update the "Introduced in version"
   field as needed so each record is assigned to the correct release.
6. Use [Drupal MRN](https://drupal-mrn.dev) to generate the list of contributors
   and changelog, then manually remove duplicates or unwanted items.

## Creating a hotfix release

Use this process when you need to release one or a small subset of recent
commits, instead of everything currently on `1.x`.

Example:

```text
12345 a very important fix ← release this on top of v1.0.0
67891 another feature
23456 some feature
67890 v1.0.0
```

1. In the main Canvas repository, create a new branch from the previous
   release tag (make sure you select the `v`-prefixed tag):
   https://git.drupalcode.org/project/canvas/-/branches/new.
2. Name the branch using the `hotfix-` prefix and date, for example
   `hotfix-20260210`.
3. Check out the new branch, then cherry-pick the required commit(s):

   ```bash
   git pull
   git checkout hotfix-20260210
   git cherry-pick 12345
   ```

4. Create a new branch and push it to the main canvas repo (or open an issue for
   the release and create a fork if you prefer):

   ```bash
   git checkout -b hotfix-20260210-remove-big-bug
   git push
   ```

5. Open a merge request with your proposed changes, make sure tests pass, and
   target `hotfix-20260210` (not `1.x`).
6. When the hotfix branch is ready, follow the standard release process above,
   but create the new `v` tag from the hotfix branch instead of `1.x`.

If you want to bypass the MR process, you can push directly to the hotfix branch
and verify the branch pipeline manually:
https://git.drupalcode.org/project/canvas/-/pipelines?page=1&scope=branches&ref=hotfix-20260210

## How the release automation works

The `release` job in `.gitlab-ci.yml` is triggered by `vX.Y.Z` tags (for
example, `v1.0.4`).

1. The release version is parsed from `CI_COMMIT_TAG` by stripping the leading
   `v` to derive `VERSION` (`X.Y.Z`), for example `v1.0.4` → `1.0.4`.
2. The source commit is prepared in detached HEAD from the trigger tag commit
   (`vX.Y.Z`), for example `v1.0.4`.
3. JavaScript dependencies are installed in the checked-out source, and the UI
   build is run.
4. A release commit is created with built assets.
   - Built assets are normally ignored, so the job temporarily un-ignores,
     stages, and commits the assets the UI app needs.
5. The unprefixed release tag (`X.Y.Z`) is created and pushed to point to the
   release commit, for example `1.0.4`.

Resulting tags:

- `v1.0.4`: trigger tag on a commit from the source branch (usually `1.x`, but
  for hotfixes a `hotfix-...` branch).
- `1.0.4`: Drupal.org release tag on the generated detached release commit with
  pre-built assets.

This keeps source branches clean while ensuring release archives include built
assets for installation and Drupal.org packaging.
