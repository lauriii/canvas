---
"drupal-canvas": patch
---

Render an `Image` whose `src` has no `alternateWidths` query parameter unoptimized.

- Drupal generates no derivative images for an image its image toolkit cannot process, such as an SVG image. Such an image is now rendered as-is, without `srcset` and `sizes`, instead of with candidates that every point at a broken derivative.
- The default loader no longer logs an error per candidate width for such an image.
