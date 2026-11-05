---
'drupal-canvas': patch
---

Keep the JSON:API client in `jsonapi-client.js` instead of a separate hashed file.

- The package entry imports `./jsonapi-client.js` directly, so bundlers that map package files to import-map names, such as Canvas's astro-hydration build, can map it to `@drupal-api-client/json-api-client`.
- Exports are unchanged.
