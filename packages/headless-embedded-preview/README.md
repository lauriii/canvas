# @drupal-canvas/headless-embedded-preview

Private package providing the Drupal behavior and styles for headless frontend
previews embedded in Drupal entity pages. The `canvas_headless` module loads the
bundled assets through its `headless.preview` library.

Uses [`@drupal-canvas/headless-host`](../headless-host/README.md) for preview
authentication and session renewal. It routes iframe navigation through Drupal,
and excludes Canvas auto-saves so the embedded iframe displays the content
selected by Drupal.
