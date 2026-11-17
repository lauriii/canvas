# @drupal-canvas/create

## 1.9.2

### Patch Changes

- 6573ef1: Update `simple-git` to version 4 to address security advisories.

  - Git settings inherited from the environment, such as commit identity,
    certificates, proxies and LFS options, are still passed to git.
  - Environment variables that point git at another repository, such as
    `GIT_DIR` or `GIT_WORK_TREE`, and git configuration overrides through
    `GIT_CONFIG_PARAMETERS` now stop the command with an error naming the
    variable.

## 1.9.1

### Patch Changes

- 614dfb4: Update runtime dependencies to compatible versions.

## 1.9.0

### Minor Changes

- 00fae2f: Make all headless templates available in the interactive template
  picker without an experimental flag.
  - Remove `--experimental-headless` from existing commands; the flag is no
    longer supported.
  - Keep selecting the `default` template in non-interactive runs that omit
    `--template`.

## 1.8.0

### Minor Changes

- 732b7e5: Add the experimental Angular starter to the template registry and
  framework selection flow.

## 1.7.0

### Minor Changes

- 761cfbb: Do not rewrite \*.ddev.site URLs to plain HTTP in the created .env
  files.
- 761cfbb: Set minimum Node.js requirement: >=22.19.0 <23 || >=24.5.0.

## 1.6.0

### Minor Changes

- 0b5b0b6: Introduce experimental headless frontends.
