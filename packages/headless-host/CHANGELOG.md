# @drupal-canvas/headless-host

## 0.6.2

### Patch Changes

- @drupal-canvas/headless@0.13.1

## 0.6.1

### Patch Changes

- Updated dependencies [c4e025b]
- Updated dependencies [c4e025b]
  - @drupal-canvas/headless@0.13.0

## 0.6.0

### Minor Changes

- e8b34a9: Add a `reset` flag to geometry events to distinguish host resets from
  valid empty frontend reports.

### Patch Changes

- ce07517: Fix the preview iframe staying at a probe height after the app
  reloads during a height probe.

  - The host restores the pre-probe height when the iframe loads a new document.
  - Height reports from the new document are applied instead of ignored.

- Updated dependencies [6573ef1]
- Updated dependencies [0c03680]
- Updated dependencies [0c03680]
- Updated dependencies [e8b34a9]
  - @drupal-canvas/headless@0.12.0

## 0.5.0

### Minor Changes

- aaaa869: Delegate eligible embedded link clicks in draft mode to the host when
  it advertises navigation support.

### Patch Changes

- Updated dependencies [aaaa869]
- Updated dependencies [aaaa869]
  - @drupal-canvas/headless@0.11.0

## 0.4.4

### Patch Changes

- Updated dependencies [ed541e3]
  - @drupal-canvas/headless@0.10.0

## 0.4.3

### Patch Changes

- Updated dependencies [e1fae30]
  - @drupal-canvas/headless@0.9.0

## 0.4.2

### Patch Changes

- Updated dependencies [252aa34]
  - @drupal-canvas/headless@0.8.0

## 0.4.1

### Patch Changes

- fc2cbd1: Retain the selected read-only preview language when renewing or
  recovering an embedded draft session.
- Updated dependencies [98b764a]
- Updated dependencies [fc2cbd1]
  - @drupal-canvas/headless@0.7.0

## 0.4.0

### Minor Changes

- e6a9c66: Preserve page variant identity when headless preview sessions renew
  or recover.

### Patch Changes

- Updated dependencies [24800cb]
- Updated dependencies [e6a9c66]
- Updated dependencies [78e3ff2]
  - @drupal-canvas/headless@0.6.0

## 0.3.0

### Minor Changes

- 71542ec: Add passive route attachment to headless preview hosts.

### Patch Changes

- Updated dependencies [71542ec]
  - @drupal-canvas/headless@0.5.0

## 0.2.0

### Minor Changes

- 761cfbb: Set minimum Node.js requirement: >=22.19.0 <23 || >=24.5.0.

### Patch Changes

- Updated dependencies [761cfbb]
  - @drupal-canvas/headless@0.4.0

## 0.1.3

### Patch Changes

- Updated dependencies [9c6de1e]
  - @drupal-canvas/headless@0.3.0

## 0.1.2

### Patch Changes

- 7f3da8f: Add content-template view mode support to live draft content
  requests.
- Updated dependencies [f16deaf]
- Updated dependencies [7f3da8f]
  - @drupal-canvas/headless@0.2.0

## 0.1.1

### Patch Changes

- ea4b308: Restore headless preview sessions after frontend framework refreshes.
- Updated dependencies [ea4b308]
  - @drupal-canvas/headless@0.1.1

## 0.1.0

### Minor Changes

- 4e4c6d0: Add Canvas editor frame editing capabilities for headless frontends.
- e2e3254: Set the Canvas editor frame height for the embedded headless
  application.

### Patch Changes

- Updated dependencies [4e4c6d0]
- Updated dependencies [e2e3254]
  - @drupal-canvas/headless@0.1.0
