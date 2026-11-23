---
'drupal-canvas': minor
---

JSON:API reads and writes now reject with `JsonApiError` when Drupal answers with an HTTP error status, instead of resolving `undefined`.

- `JsonApiError` exposes `status`, `response`, the JSON:API `errors` array, and the parsed error `document`. `isJsonApiError()` checks for it.
- Applies to public and draft clients. Raw responses (`rawResponse: true`) bypass `JsonApiError` handling for the requested resource or collection response; body parsing can still fail. HTTP errors during a preliminary `indexLookup: true` request still throw `JsonApiError`, even for raw reads. Successful reads, including empty collections, are unchanged.

Code that relies on failed reads resolving `undefined` must add error handling. See [Handling HTTP errors](https://project.pages.drupalcode.org/canvas/code-components/data-fetching/#handling-http-errors) in the data-fetching docs for examples.
