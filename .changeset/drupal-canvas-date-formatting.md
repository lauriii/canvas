---
"drupal-canvas": minor
---

Add a `useDateFormatter()` hook to `drupal-canvas/react` for displaying Canvas date props in the interface language of the site.

- It returns a function that formats an ISO date, date-time, or time string with `Intl.DateTimeFormat`. The value determines the parts shown: date, time, or both, in the `'short'` style by default.
- It reads the locale from the new `langcode` of the site context (`useSiteContext()`), which every rendering integration supplies, so it works in Drupal-rendered pages, Workbench, and Headless. It formats in `en` when no language is supplied. The `SiteContext` type includes the new optional `langcode` key, and `CanvasDataV0` includes the new `langcode` key that the Drupal adapter turns into it.
- Dates are rendered in UTC to prevent timezone-offset shifts on the viewer's device. A date-time value without a UTC offset is treated as UTC, and a value with an explicit offset is converted to UTC.
- An optional `options` argument (`CanvasDateFormatOptions`) sets the `dateStyle` and `timeStyle`. Only the parts it sets are shown, and a part the value lacks is ignored.
- It returns an empty string for `null` or `undefined`, and the original string if the value cannot be parsed. Format a date range prop by calling it on its `from` and `to` values.
