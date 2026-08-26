# @drupal-canvas/preview-geometry

Private, framework-neutral utilities shared by Drupal-rendered and headless
Canvas previews.

It provides:

- marker formatting plus discovery and measurement of component, slot, and
  region boundaries;
- an observer that reports geometry when preview layout changes;
- validation for serializable geometry snapshots;
- the shared empty-slot and empty-region placeholder stylesheet.

Boundaries may use Drupal preview comments or `<template>` markers for
frameworks without native support for rendering comment nodes. Only complete
start/end pairs are measured. Rectangles use viewport-relative CSS pixels,
matching `getBoundingClientRect()`.

Geometry uses the rendered element boxes between each pair of boundary markers.
Boundaries without measurable element output fall back to their rendered text
range. The shared placeholders give empty slots and regions measurable minimum
sizes.

The Canvas UI uses these utilities for its standard preview. The headless SDK
re-exports the geometry observer, types, validation, and placeholder stylesheet
for decoupled previews. Drupal's preview library loads the same stylesheet
directly from this package.

Coordinate conversion, cross-window transport, overlays, and drag-and-drop
behavior belong to the consumers.
