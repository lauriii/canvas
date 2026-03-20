# Known Issues

## Unsupported Imports in Canvas Code Components

These import patterns are detected by the `drupal-canvas/component-imports`
ESLint rule and are not currently supported. Each entry includes the exact error
message and support status.

**Status values:**

- `not-yet-supported` — planned for a future release
- `wont-fix` — will not be supported

---

### CSS Side-Effect Import (Third-Party)

- **Status**: `not-yet-supported`
- **ESLint rule**: `drupal-canvas/component-imports`
- **Error message**:
  `CSS side-effect imports are not supported in components. Remove "<import-path>" and use the component's CSS file instead.`
- **Example**:
  ```js
  import 'swiper/css';
  import 'swiper/css/pagination';
  ```

---

### CSS Side-Effect Import (First-Party)

- **Status**: `not-yet-supported`
- **ESLint rule**: `drupal-canvas/component-imports`
- **Error message**:
  `CSS side-effect imports are not supported in components. Remove "<import-path>" and use the component's CSS file instead.`
- **Example**:
  ```js
  import '@/lib/styles/carousel.css';
  ```

---

### Asset Import (Images, videos, etc.)

- **Status**: `not-yet-supported`
- **ESLint rule**: `drupal-canvas/component-imports`
- **Error message**:
  `Importing asset files ("<file-path>") is not supported in components.`
- **Affected extensions**: `.jpg`, `.jpeg`, `.png`, `.gif`, `.webp`, `.avif`,
  `.ico`, `.mp4`, `.webm`, `.mov`, `.avi`
- **Example**:
  ```js
  import heroImg from '@/images/hero/hero.jpg';
  ```

---

### SVG Import

- **Status**: `not-yet-supported`
- **ESLint rule**: `drupal-canvas/component-imports`
- **Error message**:
  `Importing asset files ("<file-path>") is not supported in components.`
- **Example**:
  ```js
  import CartIcon from '@/assets/cart.svg';
  ```

---

### Local Module Import Inside a Component Directory

- **Status**: `not-yet-supported`
- **ESLint rule**: `drupal-canvas/component-imports`
- **Error message**:
  `Importing "<@/-path>" from a component directory is not supported. Use "@/" alias to import other components or helpers/utilities from shared locations outside component directories.`
- **Example**:
  ```js
  import { formatPrice } from '@/components/pricing-component/helpers';
  ```
- **Action**: Move the helper/utility file to a shared location outside
  `@/components` (e.g. `@/lib/helpers`) and update the import path.

---

### Relative Import

- **Status**: `wont-fix`
- **ESLint rule**: `drupal-canvas/component-imports`
- **Error message**:
  `Relative imports are not supported. Use '@/...' alias instead of '<relative-path>' to import other components or helpers/utilities from shared locations outside component dir.`
- **Example**:
  ```js
  import { formatPrice } from './utils';
  ```
- **Action**: Relative imports will not be supported. Use the `@/` alias to
  reference shared files.

---

### Font Import

- **Status**: `wont-fix`
- **ESLint rule**: `drupal-canvas/component-imports`
- **Error message**:
  `Importing font packages ("<package-name>") is not supported in components.`
- **Example**:
  ```js
  import '@fontsource/inter';
  ```
- **Action**: Font imports will not be supported.
