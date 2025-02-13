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
import mdFile from './missingDefaultExportMessage.md';
import { ScrollArea } from '@radix-ui/themes';
import Markdown from 'markdown-to-jsx';
import { camelCase } from 'lodash';

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
  const [compiledJs, setCompiledJs] = useState<string>('');
  const [compiledCss, setCompiledCss] = useState<string>('');
  const [compiledTailwindCss, setCompiledTailwindCss] = useState<string>('');
  const [defaultExportName, setDefaultExportName] = useState<string>('');
  const [isDefaultExportMissingError, setIsDefaultExportMissingError] =
    useState(false);
  const iframeRef = useRef<HTMLIFrameElement>(null);
  const parentRef = useRef<HTMLDivElement>(null);
  const [markdown, setMarkdown] = useState('');
  const [isCompileError, setIsCompileError] = useState(false);

  const propsArray = props
    .filter((prop) => prop.name) // Filter out props with no name.
    .map((prop) => {
      const value = prop.example;
      if (prop.type === 'string') {
        // If the prop is a string, wrap it in quotes.
        return `${camelCase(prop.name)}: '${value}'`;
      }
      return `${camelCase(prop.name)}: ${value}`;
    });
  const slotsArray = slots
    .filter((slot) => slot.name) // Filter out slots with no name.
    .map(
      (slot) => `${camelCase(slot.name)}: h('Fragment', {
          dangerouslySetInnerHTML: {
            __html: '${slot?.example?.replace(/[\r\n]+/g, '')}'
          }
        })`,
    );
  const propsString = `{${[...propsArray, ...slotsArray].join(',')}}`;
  const iframeSrcDoc = `
    <script type="importmap">
        ${JSON.stringify(importMap)}
    </script>
     <style>${compiledCss}</style>
     <style>${compiledTailwindCss}</style>
    <script type="module">
      import { h, render } from 'preact';
      ${compiledJs}
      render(h(${defaultExportName}, ${propsString}), document.getElementById('xb-code-editor-preview-root'));
    </script>
    <div id="xb-code-editor-preview-root"></div>`;

  // Sets the name of the default export component from the JS AST
  // which is needed to render the component in the code editor preview iframe.
  const getDefaultExportNameFromAST = (ast: File) => {
    for (const node of ast.program.body) {
      if (node.type === 'ExportDefaultDeclaration') {
        // Case when JS is a function default export.
        if (node.declaration.type === 'FunctionDeclaration') {
          setDefaultExportName(node.declaration.id.name);
          setIsDefaultExportMissingError(false);
          return;
        } else if ('name' in node.declaration) {
          // Case when JS is an arrow function default export.
          setDefaultExportName(node.declaration.name);
          setIsDefaultExportMissingError(false);
          return;
        }
      }
    }
    setIsDefaultExportMissingError(true);
  };

  const compile = useCallback(async () => {
    if (!initialized || !js) {
      return;
    }
    try {
      const result = transformSync(js, swcConfig);
      const twCssResult = await buildCSS(js, globalCss);
      const cssResult = await transformCss(css);
      setCompiledTailwindCss(twCssResult);
      setCompiledCss(cssResult);
      const ast = parse(js, {
        sourceType: 'module',
        plugins: ['jsx'],
      });
      getDefaultExportNameFromAST(ast);
      setCompiledJs(result.code);
      setIsCompileError(false);
    } catch (error: any) {
      setIsCompileError(true);
      console.error('Compilation error:', error);
    }
  }, [initialized, js, css, globalCss]);

  useEffect(() => {
    const importAndRunSwcOnMount = async () => {
      try {
        // When served in production, the wasm asset URLs need to be relative to the Drupal web root, so
        // we pass that in to the initSwc function.
        if (import.meta.env.MODE === 'production') {
          const { drupalSettings } = window;
          const { xbModulePath } = drupalSettings.xb;
          const { baseUrl } = drupalSettings.path;
          const pathToWasm = `${baseUrl}${xbModulePath}/ui/dist/assets/wasm_bg.wasm`;
          await initSwc(pathToWasm);
        } else {
          await initSwc();
        }
        setInitialized(true);
      } catch (error) {
        console.error('Failed to initialize SWC:', error);
      }
    };
    importAndRunSwcOnMount();
    fetch(mdFile)
      .then((res) => res.text())
      .then((text) => setMarkdown(text));
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
      <Markdown>{markdown}</Markdown>
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
