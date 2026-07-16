import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { afterEach, describe, expect, it } from 'vitest';
import { render, screen } from '@testing-library/react';

import CodeEditorRouteGuard from '@/app/CodeEditorRouteGuard';
import { getCanvasSettings } from '@/utils/drupal-globals';

const renderGuardedRoute = () =>
  render(
    <MemoryRouter
      initialEntries={['/code-editor/component/heading']}
      future={{ v7_relativeSplatPath: true, v7_startTransition: true }}
    >
      <Routes>
        <Route path="/" element={<div>Canvas home</div>} />
        <Route
          path="/code-editor/component/:codeComponentId"
          element={
            <CodeEditorRouteGuard>
              <div>Code editor</div>
            </CodeEditorRouteGuard>
          }
        />
      </Routes>
    </MemoryRouter>,
  );

describe('CodeEditorRouteGuard', () => {
  afterEach(() => {
    delete getCanvasSettings().headless;
  });

  it('allows the code editor without a configured headless frontend', () => {
    renderGuardedRoute();

    expect(screen.getByText('Code editor')).toBeInTheDocument();
  });

  it('redirects direct code editor routes in configured headless mode', () => {
    getCanvasSettings().headless = {
      frontendUrl: 'https://frontend.example',
      frontends: ['https://frontend.example'],
      frontendOrigin: 'https://frontend.example',
      draftUrl: 'https://frontend.example/api/draft',
      assertionUrl: '/canvas-headless/assertion',
    };

    renderGuardedRoute();

    expect(screen.getByText('Canvas home')).toBeInTheDocument();
    expect(screen.queryByText('Code editor')).not.toBeInTheDocument();
  });
});
