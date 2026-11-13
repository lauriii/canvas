---
'@drupal-canvas/create': patch
---

Update `simple-git` to version 4 to address security advisories.

- Git settings inherited from the environment, such as commit identity,
  certificates, proxies and LFS options, are still passed to git.
- Environment variables that point git at another repository, such as
  `GIT_DIR` or `GIT_WORK_TREE`, and git configuration overrides through
  `GIT_CONFIG_PARAMETERS` now stop the command with an error naming the
  variable.
