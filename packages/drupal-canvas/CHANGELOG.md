# drupal-canvas

## 0.8.0

### Minor Changes

- d678d4d: Add a `useDateFormatter()` hook to `drupal-canvas/react` for
  displaying Canvas date props in the interface language of the site.

  - It returns a function that formats an ISO date, date-time, or time string
    with `Intl.DateTimeFormat`. The value determines the parts shown: date,
    time, or both, in the `'short'` style by default.
  - It reads the locale from the new `langcode` of the site context
    (`useSiteContext()`), which every rendering integration supplies, so it
    works in Drupal-rendered pages, Workbench, and Headless. It formats in `en`
    when no language is supplied. The `SiteContext` type includes the new
    optional `langcode` key, and `CanvasDataV0` includes the new `langcode` key
    that the Drupal adapter turns into it.
  - Dates are rendered in UTC to prevent timezone-offset shifts on the viewer's
    device. A date-time value without a UTC offset is treated as UTC, and a
    value with an explicit offset is converted to UTC.
  - An optional `options` argument (`CanvasDateFormatOptions`) sets the
    `dateStyle` and `timeStyle`. Only the parts it sets are shown, and a part
    the value lacks is ignored.
  - It returns an empty string for `null` or `undefined`, and the original
    string if the value cannot be parsed. Format a date range prop by calling it
    on its `from` and `to` values.

- 0c03680: Add a `useTranslation()` hook to `drupal-canvas/react` for
  translating interface strings in Code Components.

  - Its `t()` and `formatPlural()` functions take the same arguments as
    `Drupal.t()` and `Drupal.formatPlural()`. Without a translation, `t()`
    returns the source string, and `formatPlural()` the singular for a count of
    1 and the plural otherwise. A translation can have more forms:
    `formatPlural()` picks one with the language's plural rules, and replaces
    `@count`.
  - Only `@` placeholders, such as `@name`, are supported. Unlike Drupal's
    functions, which return HTML, they return text for React to render:
    placeholder values appear exactly as given, and HTML in a string is shown as
    text.
  - It reads the translations from the new optional `translations` key of
    `CanvasContext` (type `Translations`, shaped like Drupal's
    `drupalTranslations`: `strings` and an optional `pluralFormula`), so the
    same component works in Drupal-rendered pages, Workbench, and Headless.
    `CanvasDataV0` includes the new `translations` key that the Drupal adapter
    turns into it.
  - `TranslationsProvider` replaces the translations for the components below
    it, e.g. with an application's own dictionary, instead of merging with them;
    `null` means none. The nearest provider takes precedence over the context's
    translations.

### Patch Changes

- eb96892: Keep the JSON:API client in `jsonapi-client.js` instead of a separate
  hashed file.

  - The package entry imports `./jsonapi-client.js` directly, so bundlers that
    map package files to import-map names, such as Canvas's astro-hydration
    build, can map it to `@drupal-api-client/json-api-client`.
  - Exports are unchanged.

- 7b4dfd4: Fix draft reads of resource types without revisions, such as users,
  which Drupal rejected with 501 Not Implemented.

  - `getResource()` repeats the read once without the resource version when
    Drupal answers 501 Not Implemented, and remembers the type when Drupal
    returns the resource without a `working-copy` link.
  - Collection reads fetch working copies only for items that link to one.

## 0.7.1

### Patch Changes

- 73d9fb8: Reverted !1632

## 0.7.0

### Minor Changes

- ed541e3: Add the shared runtime APIs for React Code Components that run in
  Drupal and in React-based headless frontends. New React hooks/providers and
  their provider prop types are exported from `drupal-canvas/react`; existing
  components and utility import paths remain unchanged. Framework-neutral
  data/client types remain at the root.
  - `usePageContext()` and `useSiteContext()` replace `getPageData()` and
    `getSiteData()`; `CanvasContextProvider` supplies the values, and the
    `PageContext`, `SiteContext`, and `CanvasContext` types describe them.
  - `useJsonApiClient()` and `JsonApiClientProvider` replace
    `new JsonApiClient()` in components; `createJsonApiClient()` (from
    `drupal-canvas/jsonapi-client`) is the shared, framework-agnostic client
    with draft reads at a resource version, same-origin proxy mapping,
    `DraftSessionError`, and scoped caches.
  - `getPageData()`, `getSiteData()`, and `new JsonApiClient()` are deprecated.
    They keep working in Drupal-rendered Code Components and Canvas Workbench
    previews and throw an actionable migration error elsewhere.
  - `Region` and `RegionsProvider` are deprecated in favor of page variants.

  The shared client treats a 401 answered to an authenticated direct request as
  a rejected session (`DraftSessionError`), caches authenticated responses only
  with an explicit session-unique `cacheScope`, keeps the applicable read
  options (locale, sparse fieldsets, includes, an explicit resource version) for
  working-copy item reads and merges their included resources, and accepts
  `apiUrl` for JSON:API served from a separate base URL while the Decoupled
  Router stays on `baseUrl`.

  Caching is disabled without an explicit scope for every client that carries a
  session (cookies, the proxy, previews, resource versions), 401s are classified
  by the credentials the request actually carried, selected working copies take
  precedence over included copies of the same resource, and URLs keep the
  backend's site path for JSON:API, the Decoupled Router and index lookups.

  Shared caching also stays off, absent an explicit scope, for the legacy
  client, custom transports and browser clients with default credentials; a 401
  is classified by the configured credential lifecycle (with
  `fetchAuthenticates` for custom transports) and credential acquisition
  failures never downgrade a draft read; `apiSiteUrl` localizes a JSON:API base
  on another site.

## 0.6.0

### Minor Changes

- f65ea20: Add `canvasFormatDate`, `canvasFormatDateTime`, `canvasFormatTime`,
  and `canvasFormatDateRange` utilities for displaying Canvas date props in the
  active Drupal locale.
  - Each function reads `drupalSettings.canvasData.v0.langcode` and formats an
    ISO date/time string using `Intl.DateTimeFormat`.
  - Dates are rendered in UTC to prevent timezone-offset shifts on the viewer's
    device. A date-time or time value without a UTC offset is treated as UTC.
  - An optional `options` argument (`CanvasDateFormatOptions`) overrides the
    default `'short'` style for the date and/or time portion.

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
