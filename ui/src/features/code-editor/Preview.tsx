import { useEffect, useRef, useState, useCallback } from 'react';
import initSwc, { transformSync } from '@swc/wasm-web';
import type { Options } from '@swc/wasm-web';
import { useAppSelector } from '@/app/hooks';
import {
  selectJs,
  selectCss,
  selectGlobalCss,
  selectProps,
  selectSlots,
} from '@/features/code-editor/codeEditorSlice';
import { parse } from 'babylon';
import type { File } from 'babel-types';
import buildCSS, { transformCss } from 'tailwindcss-in-browser';
import styles from './Preview.module.css';
import ErrorCard from '@/components/error/ErrorCard';
import MissingDefaultExportMessage from './errors/MissingDefaultExportMessage';
import { ScrollArea } from '@radix-ui/themes';
import { camelCase } from 'lodash';
import { parsePropValue } from '@/features/code-editor/utils';

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
  },
};

const Preview = () => {
  const lastInvocationTimeoutRef = useRef<NodeJS.Timeout | null>(null);
  const [initialized, setInitialized] = useState(false);
  const js = useAppSelector(selectJs);
  const css = useAppSelector(selectCss);
  const globalCss = useAppSelector(selectGlobalCss);
  const props = useAppSelector(selectProps);
  const slots = useAppSelector(selectSlots);
  const [previewData, setPreviewData] = useState<string>('');
  const [compiledCss, setCompiledCss] = useState<string>('');
  const [compiledTailwindCss, setCompiledTailwindCss] = useState<string>('');
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
        <style>${compiledTailwindCss}</style>
        <script id="xb-code-editor-preview-data" type="application/json">
          ${previewData}
        </script>
        <script type="module" src="${XB_MODULE_UI_PATH}/${PREVIEW_LIB_PATH}"></script>
      </head>
      <body>
        <div id="xb-code-editor-preview-root"></div>
      </body>
    </html>`;

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

  const compile = useCallback(async () => {
    if (!initialized || !js) {
      return;
    }
    try {
      const jsForSlots = slots
        .filter((slot) => slot.name && slot.example)
        .map((slot) => {
          // Wrap the slot's example value in a function so that it can be
          // rendered by Preact.
          return `export function ${camelCase(slot.name)}() { return (${slot.example as string});}`;
        })
        .join('\n');
      const result = transformSync(`${js}\n${jsForSlots}`, swcConfig);
      const twCssResult = await buildCSS(js, globalCss);
      const cssResult = await transformCss(css);
      setCompiledTailwindCss(twCssResult);
      setCompiledCss(cssResult);
      const ast = parse(js, {
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
      let propValues = {} as Record<string, any>;
      props
        .filter((prop) => prop.name)
        .forEach((prop) => {
          propValues[camelCase(prop.name)] = parsePropValue(prop);
        });
      const slotNames = slots
        .filter((slot) => slot.name && slot.example)
        .map((slot) => camelCase(slot.name));
      setPreviewData(
        JSON.stringify({
          compiledJsUrl: URL.createObjectURL(
            new Blob([result.code], { type: 'text/javascript' }),
          ),
          propValues,
          slotNames,
        }),
      );
      setIsCompileError(false);
    } catch (error: any) {
      setIsCompileError(true);
      console.error('Compilation error:', error);
    }
  }, [initialized, js, css, globalCss, props, slots]);

  useEffect(() => {
    const importAndRunSwcOnMount = async () => {
      try {
        // When served in production, the wasm asset URLs need to be relative to the Drupal web root, so
        // we pass that in to the initSwc function.
        if (import.meta.env.MODE === 'production') {
          await initSwc(`${XB_MODULE_UI_PATH}/ui/dist/assets/wasm_bg.wasm`);
        } else {
          await initSwc();
        }
        setInitialized(true);
      } catch (error) {
        console.error('Failed to initialize SWC:', error);
      }
    };
    importAndRunSwcOnMount();
  }, []);

  useEffect(() => {
    if (lastInvocationTimeoutRef.current) {
      clearTimeout(lastInvocationTimeoutRef.current);
    }
    lastInvocationTimeoutRef.current = setTimeout(() => {
      void compile();
    }, 1000);

    return () => {
      if (lastInvocationTimeoutRef.current) {
        clearTimeout(lastInvocationTimeoutRef.current);
      }
    };
  }, [compile, initialized, js]);

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

  return (
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
  );
};

export default Preview;
