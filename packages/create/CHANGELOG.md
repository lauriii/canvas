# @drupal-canvas/create

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
