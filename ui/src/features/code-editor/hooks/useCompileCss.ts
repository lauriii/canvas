/**
 * @file
 * Compiles CSS code for individual components or global Tailwind CSS.
 *
 * Utility functions are provided by our `tailwindcss-in-browser` package.
 * @see https://www.npmjs.com/package/tailwindcss-in-browser
 */

import { useCallback } from 'react';
import {
  compilePartialCss,
  compileCss as compileTailwindCss,
  extractClassNameCandidates,
  transformCss,
} from 'tailwindcss-in-browser';

const useCompileCss = (): {
  extractClassNameCandidates: (markup: string) => string[];
  transformCss: (css: string) => Promise<string>;
  buildTailwindCssFromClassNameCandidates: (
    classNameCandidates: string[],
    configurationCss: string,
  ) => Promise<{ css: string; error?: string }>;
  buildComponentCss: (
    componentCss: string,
    configurationCss: string,
  ) => Promise<{ css: string; error?: string }>;
} => ({
  /**
   * Extracts class names candidates from markup.
   *
   * They can be used to build CSS with Tailwind CSS.
   */
  extractClassNameCandidates,

  /**
   * The transformCss() function transforms modern CSS syntax into
   * browser-compatible code.
   *
   * It uses Lightning CSS under the hood with the same configuration
   * as Tailwind CSS does internally to transform CSS syntax.
   */
  transformCss,

  /**
   * Builds CSS with Tailwind CSS using class name candidates.
   *
   * @param classNameCandidates - Class name candidates.
   * @param configurationCss - Global CSS / Tailwind CSS configuration.
   *
   * @see https://www.npmjs.com/package/tailwindcss-in-browser#tailwind-css-4-configuration
   */
  buildTailwindCssFromClassNameCandidates: useCallback(
    async (classNameCandidates: string[], configurationCss: string) => {
      try {
        const compiledCss = await compileTailwindCss(
          classNameCandidates,
          configurationCss,
          {
            // Moving generated utilities outside the `utilities` layer to
            // avoid unintended CSS precedence caused by the unlayered `.hidden`
            // class defined in the System module.
            // @see https://github.com/balintbrews/tailwindcss-in-browser/pull/20
            unlayeredUtilities: [
              // `hidden is added here in case System module removes it, or it is
              // moved to a layer in the future, or by a library override.
              'hidden',
              // The following utilities need to be able to override the effects
              // of `hidden` when it's unlayered. For example:
              // `<div class="hidden md:block"> … </div>`
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
            ],
          },
        );
        // The CSS syntax needs to be transformed.
        const transformedCss = await transformCss(compiledCss);
        return { css: transformedCss };
      } catch (error) {
        console.error('Failed to compile Tailwind CSS:', error);
        return {
          css: '/*! Compiling Tailwind CSS failed. */',
          error: `Failed to compile Tailwind CSS:', ${error}`,
        };
      }
    },
    [],
  ),

  /**
   * Builds Component CSS with Tailwind CSS resolving @apply directives.
   *
   * @param componentCss - Component CSS.
   * @param configurationCss - Global CSS / Tailwind CSS configuration.
   *
   * @see https://www.npmjs.com/package/tailwindcss-in-browser#tailwind-css-4-configuration
   */
  buildComponentCss: useCallback(async (componentCss, configurationCss) => {
    try {
      const compiledComponentCss = await compilePartialCss(
        componentCss,
        configurationCss,
      );
      // The CSS syntax needs to be transformed.
      const transformedComponentCss = await transformCss(compiledComponentCss);
      return { css: transformedComponentCss };
    } catch (error) {
      console.error('Failed to compile component CSS:', error);
      return {
        css: '/*! Compiling component CSS failed. */',
        error: `Failed to compile component CSS:', ${error}`,
      };
    }
  }, []),
});

export default useCompileCss;
