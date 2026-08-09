/**
 * The `Drupal` translation global, declared without pulling in anything else.
 *
 * `ui/global.d.ts` describes the whole `window.Drupal` surface, but it imports
 * React and Redux types, so packages that only type-check a few shared files
 * from `ui/src` cannot include it. Some of those shared files, such as
 * `features/code-editor/component-data/derivedPropTypes.ts`, translate their
 * display names, so those packages still have to know what `Drupal` is.
 *
 * This file has no imports, which makes it safe for any package to add to its
 * tsconfig `include`. Nothing here affects runtime: the packages that reference
 * these files do so with `import type`, so the modules are erased and no
 * translation call ever executes outside the browser.
 *
 * @see docs/react-codebase/translation.md
 */

interface DrupalTranslationPlaceholderOptions {
  context?: string;
}

declare const Drupal: {
  t: (
    str: string,
    args?: Record<string, string | number>,
    options?: DrupalTranslationPlaceholderOptions,
  ) => string;
  formatPlural: (
    count: number,
    singular: string,
    plural: string,
    args?: Record<string, string | number>,
    options?: DrupalTranslationPlaceholderOptions,
  ) => string;
};
