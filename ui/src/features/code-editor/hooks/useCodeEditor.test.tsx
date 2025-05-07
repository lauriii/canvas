import { beforeEach, describe, it, vi, expect } from 'vitest';
import { act, renderHook } from '@testing-library/react';
import { useNavigate } from 'react-router-dom';
import { makeStore } from '@/app/store';
import AppWrapper from '@tests/vitest/components/AppWrapper';
import useCodeEditor from '@/features/code-editor/hooks/useCodeEditor';
import {
  useGetCodeComponentQuery,
  useGetCodeComponentsQuery,
  useGetAutoSaveQuery as useGetAutoSaveQueryCodeComponent,
  useUpdateAutoSaveMutation as useUpdateAutoSaveMutationCodeComponent,
} from '@/services/componentAndLayout';
import {
  useGetAssetLibraryQuery,
  useGetAutoSaveQuery as useGetAutoSaveQueryAssetLibrary,
  useUpdateAutoSaveMutation as useUpdateAutoSaveMutationAssetLibrary,
} from '@/services/assetLibrary';
import { compileCss } from 'tailwindcss-in-browser';
import { initialState as codeEditorInitialState } from '@/features/code-editor/codeEditorSlice';
import { setCodeComponentProperty } from '@/features/code-editor/codeEditorSlice';
import type { AppStore } from '@/app/store';

vi.mock('@/services/componentAndLayout', async () => {
  const originalModule = await vi.importActual('@/services/componentAndLayout');
  return {
    ...originalModule,
    useGetCodeComponentsQuery: vi.fn(),
    useGetCodeComponentQuery: vi.fn(),
    useGetAutoSaveQuery: vi.fn(),
    useUpdateAutoSaveMutation: vi.fn(),
  };
});

vi.mock('@/services/assetLibrary', async () => {
  const originalModule = await vi.importActual('@/services/assetLibrary');
  return {
    ...originalModule,
    useGetAssetLibraryQuery: vi.fn(),
    useGetAutoSaveQuery: vi.fn(),
    useUpdateAutoSaveMutation: vi.fn(),
  };
});

vi.mock('@/features/code-editor/hooks/useCompileJavaScript', () => ({
  default: () => ({
    isJavaScriptCompilerReady: true,
    compileJavaScript: vi.fn().mockReturnValue({ code: '// compiled JS' }),
  }),
}));

vi.mock('@/features/code-editor/hooks/useCompileCss', () => {
  return {
    default: () => ({
      extractClassNameCandidates: vi
        .fn()
        .mockReturnValue(['font-bold', 'text-2xl']),
      transformCss: vi.fn().mockReturnValue('/* compiled CSS */'),
      buildTailwindCssFromClassNameCandidates: vi
        .fn()
        .mockImplementation(async () => {
          await compileCss(['test'], '@theme {}');
          return '/* compiled TW CSS */';
        }),
    }),
  };
});

describe('useCodeEditor hook', () => {
  let store: AppStore;

  beforeEach(() => {
    vi.useFakeTimers();
    store = makeStore({});

    (useGetCodeComponentsQuery as ReturnType<typeof vi.fn>).mockReturnValue({
      currentData: [],
      error: null,
      isFetching: false,
      isSuccess: true,
    });

    (useGetCodeComponentQuery as ReturnType<typeof vi.fn>).mockReturnValue({
      currentData: {
        ...codeEditorInitialState.codeComponent,
        machineName: 'test_component',
        name: 'Test Component',
        source_code_css: '/* source CSS */',
        source_code_js: '// source JS',
        compiled_css: '/* compiled CSS */',
        compiled_js: '// compiled JS',
      },
      error: null,
      isFetching: false,
      isSuccess: true,
    });
    (
      useGetAutoSaveQueryCodeComponent as ReturnType<typeof vi.fn>
    ).mockReturnValue({
      currentData: null,
      error: null,
      isFetching: false,
      isSuccess: true,
    });
    (
      useUpdateAutoSaveMutationCodeComponent as ReturnType<typeof vi.fn>
    ).mockReturnValue([
      vi.fn(),
      { isLoading: false, isError: false, isSuccess: true },
    ]);
    (
      useGetAssetLibraryQuery as unknown as ReturnType<typeof vi.fn>
    ).mockReturnValue({
      currentData: {
        ...codeEditorInitialState.globalAssetLibrary,
        css: {
          ...codeEditorInitialState.globalAssetLibrary.css,
          original: '/* source CSS global */',
          compiled: '/* compiled CSS global */',
        },
      },
      error: null,
      isFetching: false,
      isSuccess: true,
    });
    (
      useGetAutoSaveQueryAssetLibrary as ReturnType<typeof vi.fn>
    ).mockReturnValue({
      currentData: null,
      error: null,
      isFetching: false,
      isSuccess: true,
    });
    (
      useUpdateAutoSaveMutationAssetLibrary as ReturnType<typeof vi.fn>
    ).mockReturnValue([
      vi.fn(),
      { isLoading: false, isError: false, isSuccess: true },
    ]);
  });

  afterEach(() => {
    vi.useRealTimers();
  });

  it('initializes code editor data', async () => {
    await act(async () => {
      renderHook(() => useCodeEditor(), {
        wrapper: ({ children }) => (
          <AppWrapper
            store={store}
            location="/code-editor/code/test_component"
            path="/code-editor/code/:codeComponentId"
          >
            {children}
          </AppWrapper>
        ),
      });
    });
    expect(store.getState().codeEditor).toMatchObject({
      codeComponent: {
        source_code_css: '/* source CSS */',
        source_code_js: '// source JS',
        compiled_css: '', // Needs to be re-compiled.
        compiled_js: '', // Needs to be re-compiled.
      },
      globalAssetLibrary: {
        css: {
          original: '/* source CSS global */',
          compiled: '', // Needs to be re-compiled.
        },
      },
      status: {
        needsAutoSave: false,
      },
    });
  });

  it('compiles once on mount', async () => {
    await act(async () => {
      renderHook(() => useCodeEditor(), {
        wrapper: ({ children }) => (
          <AppWrapper
            store={store}
            location="/code-editor/code/test_component"
            path="/code-editor/code/:codeComponentId"
          >
            {children}
          </AppWrapper>
        ),
      });
    });
    await act(async () => {
      vi.advanceTimersByTime(1500);
    });
    expect(compileCss).toHaveBeenCalledTimes(1);
    expect(store.getState().codeEditor).toMatchObject({
      codeComponent: {
        compiled_css: '/* compiled CSS */',
        compiled_js: '// compiled JS',
      },
      globalAssetLibrary: {
        css: { compiled: '/* compiled TW CSS */' },
        js: {
          original:
            '// @classNameCandidates {"test_component":["font-bold","text-2xl"]}\n',
        },
      },
    });
  });

  it('initializes and compiles once for new code editor routes', async () => {
    let navigate: ReturnType<typeof useNavigate>;

    await act(async () => {
      renderHook(
        () => {
          navigate = useNavigate();
          return useCodeEditor();
        },
        {
          wrapper: ({ children }) => (
            <AppWrapper
              store={store}
              location="/code-editor/code/test_component"
              path="/code-editor/code/:codeComponentId"
            >
              {children}
            </AppWrapper>
          ),
        },
      );
    });
    await act(async () => {
      vi.advanceTimersByTime(1500);
    });
    // We started on /code-editor/code/test_component.
    expect(store.getState().codeEditor.codeComponent.machineName).toBe(
      'test_component',
    );
    expect(compileCss).toHaveBeenCalledTimes(1);

    (compileCss as ReturnType<typeof vi.fn>).mockClear();
    // Before navigating away, adjust our mock query to return a different code
    // component.
    (useGetCodeComponentQuery as ReturnType<typeof vi.fn>).mockReturnValue({
      currentData: {
        ...codeEditorInitialState.codeComponent,
        machineName: 'test_component_2',
      },
      error: null,
      isFetching: false,
      isSuccess: true,
    });

    // Navigate away to /code-editor/code/test_component_2.
    await act(async () => {
      navigate('/code-editor/code/test_component_2');
    });

    await act(async () => {
      vi.advanceTimersByTime(1500);
    });
    expect(store.getState().codeEditor.codeComponent.machineName).toBe(
      'test_component_2',
    );
    expect(compileCss).toHaveBeenCalledTimes(1);
  });

  it('debounces compilation', async () => {
    await act(async () => {
      renderHook(() => useCodeEditor(), {
        wrapper: ({ children }) => (
          <AppWrapper
            store={store}
            location="/code-editor/code/test_component"
            path="/code-editor/code/:codeComponentId"
          >
            {children}
          </AppWrapper>
        ),
      });
    });
    await act(async () => {
      vi.runAllTimers();
    });
    await act(async () => {
      store.dispatch(
        setCodeComponentProperty(['source_code_js', '// source JS v2']),
      );
    });
    await act(async () => {
      vi.advanceTimersByTime(1500);
    });
    await act(async () => {
      store.dispatch(
        setCodeComponentProperty(['source_code_js', '// source JS v3']),
      );
    });
    expect(compileCss).toHaveBeenCalledTimes(2);
    expect(store.getState().codeEditor.codeComponent.source_code_js).toBe(
      '// source JS v3',
    );
  });

  it('first compilation bypasses auto-save', async () => {
    const [updateAutoSaveMutation] = useUpdateAutoSaveMutationCodeComponent();
    await act(async () => {
      renderHook(() => useCodeEditor(), {
        wrapper: ({ children }) => (
          <AppWrapper
            store={store}
            location="/code-editor/code/test_component"
            path="/code-editor/code/:codeComponentId"
          >
            {children}
          </AppWrapper>
        ),
      });
    });
    // No compiled JS to start with.
    expect(store.getState().codeEditor.codeComponent.compiled_js).toBe('');
    // Run timer to trigger compilation.
    await act(async () => {
      vi.advanceTimersByTime(1500);
    });
    // We now have compiled JS.
    expect(store.getState().codeEditor.codeComponent.compiled_js).toBe(
      '// compiled JS',
    );
    // No need to auto-save on first compilation.
    expect(
      store.getState().codeEditor.status.needsAutoSaveOnFirstCompilation,
    ).toBe(false);
    await act(async () => {
      vi.advanceTimersByTime(1500);
    });
    expect(updateAutoSaveMutation).not.toHaveBeenCalled();
    // Change the source JS to trigger a new compilation.
    await act(async () => {
      store.dispatch(
        setCodeComponentProperty(['source_code_js', '// source JS v2']),
      );
    });
    await act(async () => {
      vi.advanceTimersByTime(2500);
    });
    expect(updateAutoSaveMutation).toHaveBeenCalledOnce();
  });

  it('first compilation auto-saves if compiled JS was previously empty', async () => {
    const [updateAutoSaveMutation] = useUpdateAutoSaveMutationCodeComponent();
    (useGetCodeComponentQuery as ReturnType<typeof vi.fn>).mockReturnValue({
      currentData: {
        ...codeEditorInitialState.codeComponent,
        machineName: 'test_component',
        source_code_js: '// source JS',
        compiled_js: '',
      },
      error: null,
      isFetching: false,
      isSuccess: true,
    });

    await act(async () => {
      renderHook(() => useCodeEditor(), {
        wrapper: ({ children }) => (
          <AppWrapper
            store={store}
            location="/code-editor/code/test_component"
            path="/code-editor/code/:codeComponentId"
          >
            {children}
          </AppWrapper>
        ),
      });
    });
    await act(async () => {
      vi.runAllTimers();
    });
    expect(store.getState().codeEditor.codeComponent.compiled_js).toBe(
      '// compiled JS',
    );
    expect(
      store.getState().codeEditor.status.needsAutoSaveOnFirstCompilation,
    ).toBe(true);
    await act(async () => {
      vi.advanceTimersByTime(2500);
    });
    expect(updateAutoSaveMutation).toHaveBeenCalledOnce();
    // Change the source JS to trigger a new compilation.
    (updateAutoSaveMutation as ReturnType<typeof vi.fn>).mockClear();
    await act(async () => {
      store.dispatch(
        setCodeComponentProperty(['source_code_js', '// source JS v2']),
      );
    });
    await act(async () => {
      vi.advanceTimersByTime(1500);
    });
    expect(updateAutoSaveMutation).toHaveBeenCalledOnce();
  });
  it('first compilation auto-saves if compiled JS was previously an error fallback', async () => {
    const [updateAutoSaveMutation] = useUpdateAutoSaveMutationCodeComponent();
    (useGetCodeComponentQuery as ReturnType<typeof vi.fn>).mockReturnValue({
      currentData: {
        ...codeEditorInitialState.codeComponent,
        machineName: 'test_component',
        source_code_js: '// source JS',
        compiled_js: '// @error', // @see useCompileJavaScript.ts
      },
      error: null,
      isFetching: false,
      isSuccess: true,
    });

    await act(async () => {
      renderHook(() => useCodeEditor(), {
        wrapper: ({ children }) => (
          <AppWrapper
            store={store}
            location="/code-editor/code/test_component"
            path="/code-editor/code/:codeComponentId"
          >
            {children}
          </AppWrapper>
        ),
      });
    });
    await act(async () => {
      vi.runAllTimers();
    });
    expect(store.getState().codeEditor.codeComponent.compiled_js).toBe(
      '// compiled JS',
    );
    expect(
      store.getState().codeEditor.status.needsAutoSaveOnFirstCompilation,
    ).toBe(true);
    await act(async () => {
      vi.advanceTimersByTime(2500);
    });
    expect(updateAutoSaveMutation).toHaveBeenCalledOnce();
    // Change the source JS to trigger a new compilation.
    (updateAutoSaveMutation as ReturnType<typeof vi.fn>).mockClear();
    await act(async () => {
      store.dispatch(
        setCodeComponentProperty(['source_code_js', '// source JS v2']),
      );
    });
    await act(async () => {
      vi.advanceTimersByTime(1500);
    });
    expect(updateAutoSaveMutation).toHaveBeenCalledOnce();
  });
});
