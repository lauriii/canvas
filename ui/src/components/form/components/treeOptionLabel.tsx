import type { ReactNode } from 'react';

/**
 * Separates the levels in the label of a nested option: "Design › Typography".
 *
 * @see \Drupal\canvas\Plugin\Canvas\OptionsSource\EntityOptionsSource::labelTermsWithTheirPath()
 */
export const PATH_SEPARATOR = ' › ';

const VISUALLY_HIDDEN = {
  position: 'absolute',
  width: '1px',
  height: '1px',
  overflow: 'hidden',
  clipPath: 'inset(50%)',
  whiteSpace: 'nowrap',
} as const;

/**
 * Says why a react-select menu is empty.
 */
export const noOptionsMessage = ({ inputValue }: { inputValue: string }) =>
  inputValue ? 'No matches' : 'Nothing to choose from yet';

/**
 * Shows nested options as an indented tree in the menu of a react-select.
 *
 * The chosen value, and options while searching, keep their full path, with
 * the parents muted, so the parents stay visible.
 */
export const formatTreeOptionLabel = (
  option: { label: string },
  meta: { context: 'menu' | 'value'; inputValue: string },
): ReactNode => {
  const levels = option.label.split(PATH_SEPARATOR);
  if (levels.length === 1) {
    return option.label;
  }
  const parents = levels.slice(0, -1).join(PATH_SEPARATOR) + PATH_SEPARATOR;
  if (meta.context !== 'menu' || meta.inputValue) {
    // The parents give context; the option itself stands out.
    return (
      <span>
        <span style={{ color: 'var(--slate-11)' }}>{parents}</span>
        {levels[levels.length - 1]}
      </span>
    );
  }
  return (
    <span style={{ paddingInlineStart: `${(levels.length - 1) * 16}px` }}>
      {/* Screen readers announce the parents, which sighted users see. */}
      <span style={VISUALLY_HIDDEN}>{parents}</span>
      {levels[levels.length - 1]}
    </span>
  );
};
