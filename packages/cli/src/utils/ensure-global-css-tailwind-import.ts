const TAILWIND_ENTRY_IMPORT_RE = /@import\s+["']tailwindcss["']/;

/**
 * Ensures Tailwind v4 host entry CSS is present for local PostCSS/Vite builds.
 * Drupal-stored global CSS may omit this import because it is optional in the
 * in-browser editor.
 */
export function ensureTailwindImportAtTop(css: string): string {
  if (TAILWIND_ENTRY_IMPORT_RE.test(css)) {
    return css;
  }
  return `@import "tailwindcss";\n${css}`;
}
