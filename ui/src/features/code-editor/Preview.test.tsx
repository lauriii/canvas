import { describe, expect, it, vi } from 'vitest';
import { act, render, screen } from '@testing-library/react';
import AppWrapper from '@tests/vitest/components/AppWrapper';

import { makeStore } from '@/app/store';
import { setCodeComponentProperty } from '@/features/code-editor/codeEditorSlice';
import Preview from '@/features/code-editor/Preview';
import { useGetCodeComponentsQuery } from '@/services/componentAndLayout';

vi.mock('@/services/componentAndLayout', async () => ({
  ...(await vi.importActual('@/services/componentAndLayout')),
  useGetCodeComponentsQuery: vi.fn(),
}));

vi.mock('@/utils/drupal-globals', async () => ({
  ...(await vi.importActual('@/utils/drupal-globals')),
  getBaseUrl: () => '/',
  getCanvasSettings: () => ({ canvasModulePath: 'modules/canvas' }),
  getDrupal: () => ({ url: (path: string) => `/${path}` }),
  getDrupalSettings: () => ({}),
}));

const hook = "import { useTranslation } from 'drupal-canvas/react';\n";
const component = (body: string) =>
  `${hook}export default function Greeting() {\n  const { t } = useTranslation();\n  return ${body};\n}\n`;

describe('Preview', () => {
  it('reports invalid translatable strings, but not incomplete source code', async () => {
    (useGetCodeComponentsQuery as ReturnType<typeof vi.fn>).mockReturnValue({
      data: {},
    });
    // Parse errors are logged until the compiler reports them.
    vi.spyOn(console, 'error').mockImplementation(() => {});
    const store = makeStore({});
    store.dispatch(setCodeComponentProperty(['machineName', 'greeting']));
    store.dispatch(
      setCodeComponentProperty([
        'sourceCodeJs',
        component("<p>{t('Hello')}</p>"),
      ]),
    );
    store.dispatch(setCodeComponentProperty(['compiledJs', '// compiled JS']));
    render(
      <AppWrapper store={store} location="/" path="/">
        <Preview />
      </AppWrapper>,
    );
    const preview = () => screen.queryByTitle('Canvas Code Editor Preview');
    const card = () =>
      screen.queryByText(
        'Error: The translatable strings could not be determined.',
      );
    expect(preview()).toBeInTheDocument();

    // While the user types, before the compiler catches up, the preview keeps
    // showing the last compiled result.
    for (const incomplete of [
      component("<p>{t('Hello')}</p"),
      component("<p>{t('Hello)}</p>"),
    ]) {
      await act(async () => {
        store.dispatch(setCodeComponentProperty(['sourceCodeJs', incomplete]));
      });
      expect(card()).not.toBeInTheDocument();
      expect(preview()).toBeInTheDocument();
    }

    await act(async () => {
      store.dispatch(
        setCodeComponentProperty(['sourceCodeJs', component("<p>{t('')}</p>")]),
      );
    });
    expect(card()).toBeInTheDocument();
    expect(
      screen.getByText(
        'Line 4, column 16: A translatable string must not be empty.',
      ),
    ).toBeInTheDocument();
    expect(preview()).not.toBeInTheDocument();
  });
});
