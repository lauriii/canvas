import type { AgentsContextProvider } from '../agents-context';

/**
 * How code components receive styles. Written next to the snapshot so
 * agents use real style IDs and do not turn looks into props.
 */
export const STYLES_CONTRACT = [
  'Styles are named looks from the core Style API (for example surface, spacing) that site builders pick per component instance.',
  "A code component opts in with `styles` in component.yml: a list of style IDs, or '*' for every style that sets a class or inline style. Omitted or [] accepts none.",
  "A component that accepts styles receives React's standard `className` and `style` props and must apply both on its root element, for example `<div className={cn('…', className)} style={style}>`.",
  'Do not declare props named `className` or `style` in component.yml for a component that accepts styles: Canvas rejects them.',
  "Read the selected option IDs with `useStyleOptions()` from 'drupal-canvas' when logic depends on them, for example `useStyleOptions().tone === 'dark'`. Styles that set attributes other than class or style (data-*, aria-*) need this hook.",
  'Use styles for cross-cutting looks shared by many components (color scheme, spacing, width). Keep component-specific variants, such as a card layout or a button kind, as enum props.',
  'Only use style IDs listed in this file or in .canvas/styles.json. Run `canvas pull` to refresh them, and `canvas validate` to check usage.',
];

export const stylesContextProvider: AgentsContextProvider = {
  name: 'styles',
  description:
    'Writes the styles the site offers and the code component styles contract.',
  default: true,
  execute: async ({ apiService, writeContextFile, writeMessage }) => {
    const response = await apiService.getStyles();
    const active = response?.active ?? false;
    const styles = (active ? (response?.styles ?? []) : []).map((style) => ({
      id: style.id,
      label: style.label,
      ...(style.description ? { description: style.description } : {}),
      ...(style.category ? { category: style.category } : {}),
      providerType: style.providerType,
      options: style.options.map((option) => ({
        id: option.id,
        label: option.label,
        attribute: option.attribute,
      })),
    }));
    const filePath = await writeContextFile('styles.json', {
      active,
      contract: STYLES_CONTRACT,
      styles,
    });
    writeMessage(
      active
        ? `Saved ${styles.length} ${styles.length === 1 ? 'style' : 'styles'} and the code component styles contract to ${filePath}.`
        : `Component styles are not active on the site. Saved the code component styles contract to ${filePath}.`,
    );
  },
};
