import { useEffect, useRef, useState, useCallback } from 'react';
import initSwc, { transformSync } from '@swc/wasm-web';
import type { Options } from '@swc/wasm-web';
import { useAppDispatch, useAppSelector } from '@/app/hooks';
import {
  selectBlockOverride,
  selectCompiledCss,
  selectHasCompletedFirstCompilation,
  selectName,
  selectProps,
  selectSlots,
  selectSourceCodeCss,
  selectSourceCodeGlobalCss,
  selectSourceCodeJs,
  setCompiledCss,
  setCompiledJs,
  setHasCompletedFirstCompilation,
} from '@/features/code-editor/codeEditorSlice';
import { parse } from '@babel/parser';
import type { File } from '@babel/types';
import buildCSS, { transformCss } from 'tailwindcss-in-browser';
import styles from './Preview.module.css';
import ErrorCard from '@/components/error/ErrorCard';
import MissingDefaultExportMessage from './errors/MissingDefaultExportMessage';
import { ScrollArea, Spinner } from '@radix-ui/themes';
import {
  getExamplePropValuesForOverridePreview,
  getExampleSlotNamesForOverridePreview,
  getJsForExampleSlotsOverridePreview,
  getJsForSlotsPreview,
  getPropValuesForPreview,
  getSlotNamesForPreview,
} from '@/features/code-editor/utils';

const XB_MODULE_UI_PATH = (() => {
  const { drupalSettings } = window;
  if (!drupalSettings) {
    return '';
  }
  const { xbModulePath } = drupalSettings.xb;
  const { baseUrl } = drupalSettings.path;
  return `${baseUrl}${xbModulePath}/ui` as const;
})();

const PREVIEW_LIB_PATH = 'dist/assets/code-editor-preview.js' as const;

const swcConfig: Options = {
  jsc: {
    parser: {
      syntax: 'ecmascript' as const,
      jsx: true,
    },
    target: 'es2015',
    transform: {
      react: {
        pragma: 'h',
        pragmaFrag: 'Fragment',
        throwIfNamespace: true,
        development: false,
        runtime: 'automatic',
      },
    },
  },
  module: {
    type: 'es6',
  },
};

const importMap = {
  imports: {
    preact: 'https://esm.sh/preact',
    'preact/': 'https://esm.sh/preact/',
    react: 'https://esm.sh/preact/compat',
    'react/': 'https://esm.sh/preact/compat/',
    'react-dom': 'https://esm.sh/preact/compat',
    'react-dom/': 'https://esm.sh/preact/compat/',
    // @todo Remove hardcoding and allow components to nominate their own?
    clsx: 'https://esm.sh/clsx',
    'class-variance-authority': 'https://esm.sh/class-variance-authority',
    'tailwind-merge': 'https://esm.sh/tailwind-merge',
    '@/lib/utils': `${XB_MODULE_UI_PATH}/lib/astro-hydration/dist/utils.js`,
  },
};

const Preview = ({ isLoading = false }: { isLoading?: boolean }) => {
  const dispatch = useAppDispatch();
  const lastInvocationTimeoutRef = useRef<NodeJS.Timeout | null>(null);
  const [isSwcInitialized, setIsSwcInitialized] = useState(false);
  const componentName = useAppSelector(selectName);
  const blockOverride = useAppSelector(selectBlockOverride);
  const hasCompletedFirstCompilation = useAppSelector(
    selectHasCompletedFirstCompilation,
  );
  const sourceCodeJs = useAppSelector(selectSourceCodeJs);
  const sourceCodeCss = useAppSelector(selectSourceCodeCss);
  const compiledCss = useAppSelector(selectCompiledCss);
  const sourceCodeGlobalCss = useAppSelector(selectSourceCodeGlobalCss);
  const props = useAppSelector(selectProps);
  const slots = useAppSelector(selectSlots);
  const [previewData, setPreviewData] = useState<string>('');
  const [isDefaultExportMissingError, setIsDefaultExportMissingError] =
    useState(false);
  const iframeRef = useRef<HTMLIFrameElement>(null);
  const parentRef = useRef<HTMLDivElement>(null);
  const [isCompileError, setIsCompileError] = useState(false);

  const iframeSrcDoc = `
    <html>
      <head>
        <script type="importmap">
          ${JSON.stringify(importMap)}
        </script>
        <style>${compiledCss}</style>
        <script id="xb-code-editor-preview-data" type="application/json">
          ${previewData}
        </script>
        <script type="module" src="${XB_MODULE_UI_PATH}/${PREVIEW_LIB_PATH}"></script>
      </head>
      <body>
        <div id="xb-code-editor-preview-root"></div>
      </body>
    </html>`;

  const fallbackCompiledJs = `
    import { jsx as _jsx } from "react/jsx-runtime";
    export default function() {
      return /*#__PURE__*/ _jsx("div", {
          dangerouslySetInnerHTML: {
              __html: '<!-- The component ${componentName} failed to compile. -->'
          }
      });
    }`;

  // Verifies that the component's JS code has a default export.
  const hasDefaultExport = (ast: File) => {
    for (const node of ast.program.body) {
      if (node.type === 'ExportDefaultDeclaration') {
        // Case when JS is a function default export.
        if (node.declaration.type === 'FunctionDeclaration') {
          return true;
        } else if ('name' in node.declaration) {
          // Case when JS is an arrow function default export.
          return true;
        }
      }
    }
    return false;
  };

  const compile = useCallback(
    async () => {
      if (!isSwcInitialized || !sourceCodeJs) {
        return;
      }
      try {
        const jsForSlots = !blockOverride
          ? getJsForSlotsPreview(slots)
          : getJsForExampleSlotsOverridePreview(blockOverride);
        const result = transformSync(
          `${sourceCodeJs}\n${jsForSlots}`,
          swcConfig,
        );
        const twCssResult = await buildCSS(sourceCodeJs, sourceCodeGlobalCss);
        const cssResult = await transformCss(sourceCodeCss);
        dispatch(setCompiledCss(twCssResult + cssResult));
        const ast = parse(sourceCodeJs, {
          sourceType: 'module',
          plugins: ['jsx'],
        });
        if (hasDefaultExport(ast)) {
          setIsDefaultExportMissingError(false);
        } else {
          setIsDefaultExportMissingError(true);
        }
        // The following data is going to be embedded in the iframe as a JSON
        // object. It is used by a script that we load inside the iframe to
        // render the component. The script is loaded via an `src` attribute
        // instead of being added to the iframe inline because of Content
        // Security Policy (CSP) restrictions.
        // @see ui/lib/code-editor-preview.js
        const propValues = !blockOverride
          ? getPropValuesForPreview(props)
          : getExamplePropValuesForOverridePreview(blockOverride);
        const slotNames = !blockOverride
          ? getSlotNamesForPreview(slots)
          : getExampleSlotNamesForOverridePreview(blockOverride);
        setPreviewData(
          JSON.stringify({
            compiledJsUrl: URL.createObjectURL(
              new Blob([result.code], { type: 'text/javascript' }),
            ),
            propValues,
            slotNames,
          }),
        );
        dispatch(setCompiledJs(result.code));
        setIsCompileError(false);
        if (!hasCompletedFirstCompilation) {
          dispatch(setHasCompletedFirstCompilation(true));
        }
      } catch (error: any) {
        // Saving a fallback compiled JS in case of compilation error. Not doing
        // this would simply keep the previous compiled JS.
        dispatch(setCompiledJs(fallbackCompiledJs));
        setIsCompileError(true);
        if (!hasCompletedFirstCompilation) {
          dispatch(setHasCompletedFirstCompilation(true));
        }
        console.error('Compilation error:', error);
      }
    },
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [
      // Intentionally left out: hasCompletedFirstCompilation,
      dispatch,
      fallbackCompiledJs,
      isSwcInitialized,
      props,
      slots,
      sourceCodeCss,
      sourceCodeGlobalCss,
      sourceCodeJs,
    ],
  );

  useEffect(() => {
    const importAndRunSwcOnMount = async () => {
      try {
        // When served in production, the wasm asset URLs need to be relative to the Drupal web root, so
        // we pass that in to the initSwc function.
        if (import.meta.env.MODE === 'production') {
          await initSwc(`${XB_MODULE_UI_PATH}/dist/assets/wasm_bg.wasm`);
        } else {
          await initSwc();
        }
        setIsSwcInitialized(true);
      } catch (error) {
        console.error('Failed to initialize SWC:', error);
      }
    };
    importAndRunSwcOnMount();
  }, []);

  useEffect(
    () => {
      if (lastInvocationTimeoutRef.current) {
        clearTimeout(lastInvocationTimeoutRef.current);
      }
      lastInvocationTimeoutRef.current = setTimeout(
        () => {
          void compile();
        },
        // No delay if the component hasn't been compiled yet, which is the case
        // when the user navigates to a code component's edit page.
        hasCompletedFirstCompilation ? 1000 : 0,
      );

      return () => {
        if (lastInvocationTimeoutRef.current) {
          clearTimeout(lastInvocationTimeoutRef.current);
        }
      };
    },
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [compile, isSwcInitialized, sourceCodeJs],
    // Intentionally left out: hasCompletedFirstCompilation
  );

  // Add an invisible overlay to the iframe when the Mosaic window is being resized.
  // This prevents the iframe from intercepting mouse events from the parent Mosaic window.
  // This is necessary because when a user is resizing their preview window, and their mouse enters the iframe,
  // the parent window stops receiving mouse events so the resizing stops.
  useEffect(() => {
    const handleOnChange = () => {
      if (parentRef.current) {
        parentRef.current.classList.add('iframe-overlay');
      }
    };
    const handleOnRelease = () => {
      if (parentRef.current) {
        parentRef.current.classList.remove('iframe-overlay');
      }
    };

    window.addEventListener('mosaicOnChange', handleOnChange);
    window.addEventListener('mosaicOnRelease', handleOnRelease);

    return () => {
      window.removeEventListener('mosaicOnChange', handleOnChange);
      window.removeEventListener('mosaicOnRelease', handleOnRelease);
    };
  }, []);

  const renderCompileError = () => (
    <ErrorCard
      title="Error: There was an error compiling your code."
      error="Check your browser's developer console for more details."
    />
  );

  const renderExportMissingError = () => (
    <ErrorCard
      title="Error: Component is missing a default export."
      asChild={true}
    >
      <MissingDefaultExportMessage />
    </ErrorCard>
  );

  if (!hasCompletedFirstCompilation) {
    // If navigating from another code component's edit page, its preview would
    // be shown briefly before the new component's preview is compiled.
    return null;
  }

  return (
    <Spinner loading={isLoading}>
      <div className={styles.iframeContainer} ref={parentRef}>
        {(isCompileError || isDefaultExportMissingError) && (
          <ScrollArea>
            <div className={styles.errorContainer}>
              {isCompileError && renderCompileError()}
              {isDefaultExportMissingError && renderExportMissingError()}
            </div>
          </ScrollArea>
        )}
        {!isDefaultExportMissingError && !isCompileError && (
          <iframe
            className={styles.iframe}
            title="XB Code Editor Preview"
            ref={iframeRef}
            height="100%"
            width="100%"
            srcDoc={iframeSrcDoc}
            data-xb-iframe="xb-code-editor-preview"
          />
        )}
      </div>
    </Spinner>
  );
};

export default Preview;
