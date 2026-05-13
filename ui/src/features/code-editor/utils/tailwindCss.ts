// Moving generated utilities outside the `utilities` layer avoids unintended
// CSS precedence caused by the unlayered `.hidden` class defined in the System
// module.
// @see https://github.com/balintbrews/tailwindcss-in-browser/pull/20
export const UNLAYERED_DISPLAY_UTILITIES = [
  // `hidden` is added here in case System module removes it, or it is moved to
  // a layer in the future, or by a library override.
  'hidden',
  // The following utilities need to be able to override the effects of `hidden`
  // when it's unlayered. For example: `<div class="hidden md:block">...</div>`.
  'inline',
  'block',
  'inline-block',
  'flow-root',
  'flex',
  'inline-flex',
  'grid',
  'inline-grid',
  'contents',
  'table',
  'inline-table',
  'table-caption',
  'table-cell',
  'table-column',
  'table-column-group',
  'table-footer-group',
  'table-header-group',
  'table-row-group',
  'table-row',
  'list-item',
];
