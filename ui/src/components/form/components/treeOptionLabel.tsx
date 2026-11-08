import type { ReactNode } from 'react';

/**
 * Separates the levels in the label of a nested option: "Design › Typography".
 *
 * @see \Drupal\canvas\Plugin\Canvas\OptionsSource\EntityOptionsSource::labelTermsWithTheirPath()
 */
export const PATH_SEPARATOR = ' › ';

/**
 * Shows nested options as an indented tree in the menu of a react-select.
 *
 * The chosen value, and options while searching, keep their full path, so the
 * parent stays visible.
 */
export const formatTreeOptionLabel = (
  option: { label: string },
  meta: { context: 'menu' | 'value'; inputValue: string },
): ReactNode => {
  const levels = option.label.split(PATH_SEPARATOR);
  if (meta.context !== 'menu' || meta.inputValue || levels.length === 1) {
    return option.label;
  }
  return (
    <span style={{ paddingInlineStart: `${(levels.length - 1) * 16}px` }}>
      {levels[levels.length - 1]}
    </span>
  );
};
