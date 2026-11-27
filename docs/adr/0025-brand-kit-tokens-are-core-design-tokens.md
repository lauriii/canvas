# 25. Brand kit and style guide tokens are core design tokens

Date: 2026-11-21

## Status

Proposed

## Context

The Canvas brand kit stores colors as Canvas config entities. Each has a free-form CSS custom property name, a W3C Design Tokens Community Group (DTCG) color value, a display name, a weight, and a display format. Fonts are uploaded files listed on the global brand kit entity. Canvas emits both as one stylesheet. Color props reference colors by entity UUID. Themes, single-directory components, and other Drupal design tools cannot read these colors, and Canvas cannot read the theme's own custom properties. Other design decisions, such as type styles, spacing, and weights, have no editor at all.

Drupal core provides a design tokens API:

- **Declaration:** themes and modules declare DTCG tokens in `{provider}.tokens.yml`, discovered by a plugin manager.
- **Site values:** `design_token` config entities, each with an immutable machine-name ID, path, and type, and with values keyed by CSS selector.
- **CSS:** the CSS variable name is derived from the path, and a typed value plugin per DTCG type serializes each value.
- **Gaps:**
  - The API is marked experimental and not meant for production use.
  - It serializes dimension (px and rem), font family, font weight, number, and typography. Typography is output as the `font` shorthand, without letter spacing.
  - It has no color type and no alias support.
  - Its value schema does not constrain units or family names, and some invalid values throw errors that discovery does not catch.
  - Nothing attaches token CSS to pages, and there is no UI.
  - The entity has no label.
  - Removing a selector through the entity's plugin collection does not persist.
  - Discovery merges providers by top-level key, so one provider's group replaces another's.

A module library's stylesheet always loads before theme stylesheets, whatever its weight. Canvas supports core versions with and without this API. Canvas already stages config changes as staged config updates, published from Review changes, and brand kit fonts are already drafts.

## Decision

1. **Core `design_token` entities are the store for brand tokens.** Canvas keeps only what core has no place for: font files, display metadata as Canvas third-party settings on the entity, and, from the second step, folder membership.
2. **Adoption happens in two steps.**
   - **First step:** an experimental, reversible setting lists the theme's and modules' tokens of every type core serializes as a style guide in the brand kit. It makes colors pickable, and lets site builders override tokens in core entities: colors and font families from the UI first, the other types from the UI once a separate demand gate passes, and every type through the CLI. Existing site colors stay in their Canvas entities.
   - **Second step:** once Canvas requires a core version with the API and the API is accepted for production, a deterministic update moves site colors into core entities, keeping each UUID.
   - Turning the setting off deletes nothing and changes no site color.
3. **One Canvas service is the only caller of core's design token classes.** Everything else in Canvas talks to that service.
4. **When core lacks a color value type, Canvas registers one through core's alter hooks.**
   - It stores the DTCG color object Canvas already uses, accepts hex strings, and emits exactly the CSS Canvas emits for site colors.
   - Canvas registers nothing when core has the type, contributes the type to core, and blocks its own uninstall while entities depend on it.
5. **Listed tokens are those of enabled modules, the default theme, and its base themes.**
   - An override writes only the `:root` value of the entity at the token's path.
   - Reset removes that value, and deletes the entity only if Canvas created it and it has no other selector.
   - Canvas never writes other selectors, and preserves selectors other tools wrote.
   - Canvas does not emit declared defaults, because the theme's CSS owns them.
6. **The brand kit permission allows only those `:root` writes, through Canvas's API.** Every other change needs core's restricted design token permission. Canvas validates the font family names it writes, because core's font family type neither validates nor quotes them.
7. **A theme token reference is the DTCG alias of its path (`{path}`), and the server tells the client which reference string to store for every pickable color.**
   - The resolved value components receive does not change shape.
   - Path references add no config dependency, so uninstalling a theme or resetting a token never deletes or alters Canvas config.
   - Site color references keep their UUID form, and keep resolving after the second step through the preserved UUID.
8. **Canvas emits every `design_token` entity, whoever saved it, in the brand kit stylesheet.**
   - It uses core's serializer and skips entities that cannot be serialized.
   - While the setting is on, the stylesheet moves into the theme aggregate group, after theme stylesheets. Sites that do not opt in keep today's order.
   - Canvas stops emitting the entities once core attaches token CSS.
9. **The second step is ordered so that no reference is ever replaced by a literal value.**
   - Build and compare everything unsaved.
   - Snapshot the config.
   - Save the new entities.
   - Switch storage.
   - Recalculate dependents.
   - Assert that nothing depends on the old entities.
   - Delete the old entities with literal inlining suppressed.
10. **The style guide's structure is the theme's `tokens.yml`.**
    - Groups become sections and descriptions become help text, in declaration order.
    - Canvas has no form builder and stores no structure.
    - Each type gets one editor: colors and font families first, the other types after a separate demand gate. Canvas validates the units, numbers, weights, family names, and complete typography values that core does not, for every type from the start.
    - Canvas polyfills only the color type. Other types wait for core.
11. **Changes in the style guide are drafts.**
    - Each edited token is one staged config update. It targets the brand kit and carries a Canvas config action with the new value and the published value it started from.
    - Drafts show in the editor preview and the draft stylesheet, and publish from Review changes.
    - The server records the published value when a draft is created. Validation before publish rejects the draft if that value has changed since, and, because publish is all or nothing, nothing in that publish is saved.
    - On sites whose config is deployed from code, a deploy reverts published values unless they are config-ignored.
    - The action applies only with the brand kit as its target.
    - CLI pushes write published values directly, as brand kit pushes do today.
12. **Modes and per-theme values are out of scope.** Core plans modes through its Style API, and its entities are site-wide.
13. **One token format, DTCG, wherever tokens are written down.**
    - It covers theme and module `tokens.yml` files, a code project's `canvas.tokens.json`, the CLI's site values in `canvas.brand-kit.json`, generated files, and style guide import and export.
    - All of them share the same paths, and therefore the same CSS variables.
    - YAML is used only where core discovery requires it.
14. **Code projects can declare tokens.**
    - A code project's `canvas.tokens.json` is sent by the CLI, or synced from a headless app's component manifest.
    - Canvas stores the synced copy and hands it to core as raw DTCG through core's alter hook, so core parses it.
    - Canvas emits its declared defaults with zero specificity.
    - In headless mode, these are the only listed tokens.
15. **Headless apps receive the brand kit CSS through the content API.**
    - It arrives as a `brandKit` response field, behind a client capability: a cacheable stylesheet link for published renders, and inline draft CSS in preview sessions.
    - It does not depend on the design tokens API, so fonts and site colors reach headless apps on any core version.
    - The editor sends draft values to the app's preview iframe at once.
16. **Setting up a site happens in a full-canvas style guide workspace.**
    - It has a specimen and a page preview, editing by group, and DTCG import staged as drafts.

## Consequences

In order of importance, with the following markers:
- positives (`+`) vs negatives (`-`) vs status quo (`≃`)
- impact types: technical (`T`) vs operational (`O`) vs business (`B`)

1. `+TOB` **One brand definition across Drupal.** Tokens a theme declares appear in Canvas with no Canvas configuration. Values set in Canvas are core entities that other design tools can read.
2. `+O` **Low-risk adoption.** The first step migrates no data and can be turned off without a visual change to site colors.
3. `+T` **No content rewrite.** Theme references are new. Site color references survive the second step through preserved UUIDs.
4. `+T` **Values set in Canvas win over theme defaults on opted-in sites.** The stylesheet loads after theme CSS, which also changes the outcome for existing site colors that share a name with a theme variable. Opting in reports those colors first.
5. `+T` **Less Canvas-specific storage after the second step.** The Canvas color entity type, its constraints, and its HTTP API can then be removed.
6. `-T` **Dependence on an experimental core API.** Canvas mitigates this with one service, testing against core's next minor, and a reversible first step.
7. `-B` **Semantic theme tokens that use aliases stay invisible until core supports aliases.** Theme authors must use literal values in the meantime.
8. `-T` **Workarounds for core gaps until they are fixed upstream:** a polyfilled color type, saving in syncing mode to remove a selector, read-only listing of declared paths the entity cannot store, and a CSS group change.
9. `+O` **Style guide changes can be previewed before they go live.** Drafts publish with other Canvas changes.
10. `+B` **Typed design values without a form builder.** Site builders get typed, previewed values, and themers keep structure in code. Adding a token still needs a themer.
11. `-B` **No per-theme values, no editor hints, and no types beyond core's five plus color.** A site with several front-end themes shares one value per path. Range limits and options wait for core to keep `$extensions`.
12. `-T` **Typography tokens copy font stacks instead of linking them,** and letter spacing is not output, until core adds aliases and fixes its serializer.
13. `+T` **One token format across themes, code projects, the CLI, and design tools.** They share DTCG files and paths. Import reads the export forms that a spike against real design tool files confirms.
14. `+B` **Headless and code-component sites get the style guide too,** declared in their own repository, without a Drupal theme.
15. `-T` **The content API carries one stylesheet again,** after it deliberately dropped them. It is limited to Canvas-owned brand data, and opt-in through a capability.
16. `≃T` **Font files stay Canvas-owned.** Core's font family type names families but does not load them.
