# drupal-canvas

## 0.5.2

### Patch Changes

- 3ed0539: Render an `Image` whose `src` has no `alternateWidths` query
  parameter unoptimized.
  - Drupal generates no derivative images for an image its image toolkit cannot
    process, such as an SVG image. Such an image is now rendered as-is, without
    `srcset` and `sizes`, instead of with candidates that every point at a
    broken derivative.
  - The default loader no longer logs an error per candidate width for such an
    image.

## 0.5.1

### Patch Changes

- 108e9d4: Ship the document schema in `json-render-utils` so `document` prop
  refs resolve during CLI validation.

## 0.5.0

### Minor Changes

- 761cfbb: Set minimum Node.js requirement: >=22.19.0 <23 || >=24.5.0.
