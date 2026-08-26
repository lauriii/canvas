import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { describe, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';

import HeadlessComponentPreview from './HeadlessComponentPreview';

import type { HeadlessSettings } from '@drupal-canvas/types';
import type { JSComponent } from '@/types/Component';

const sessionMock = vi.hoisted(() => vi.fn());

vi.mock('@/features/layout/preview/useHeadlessDraftSession', () => ({
  useHeadlessDraftSession: sessionMock,
}));

const settings: HeadlessSettings = {
  frontendUrl: 'https://frontend.example',
  frontends: ['https://frontend.example'],
  frontendOrigin: 'https://frontend.example',
  draftUrl: 'https://frontend.example/api/draft',
  assertionUrl: '/canvas-headless/assertion',
};

const component: JSComponent = {
  id: 'js.example',
  name: 'Example',
  library: 'primary_components',
  source: 'Code component',
  type: 'external',
  default_markup: '',
  css: '',
  js_header: '',
  js_footer: '',
  version: '1',
  broken: false,
  transforms: [],
};

describe('HeadlessComponentPreview', () => {
  it('keeps the preview hidden until component geometry is available', () => {
    sessionMock.mockReturnValue({
      geometry: [],
    });

    render(
      <MemoryRouter initialEntries={['/editor/canvas_page/7']}>
        <Routes>
          <Route
            path="/editor/:entityType/:entityId"
            element={
              <HeadlessComponentPreview
                component={component}
                settings={settings}
              />
            }
          />
        </Routes>
      </MemoryRouter>,
    );

    expect(sessionMock).toHaveBeenCalledWith(
      expect.any(Object),
      settings,
      'canvas_page',
      '7',
      undefined,
      800,
      { componentPreviewId: 'js.example' },
    );
    const preview = screen.getByLabelText('Example headless preview thumbnail');
    expect(preview).toHaveStyle({
      width: '300px',
      height: '200px',
      visibility: 'hidden',
    });
    expect(screen.getByTitle('Example')).toHaveAttribute('height', '800');
  });

  it('crops the iframe to a compact component instead of shrinking the full page', () => {
    sessionMock.mockReturnValue({
      geometry: [
        {
          type: 'component',
          id: 'component-uuid',
          markerFormat: 'template',
          rect: {
            top: 30,
            right: 120,
            bottom: 70,
            left: 20,
            width: 100,
            height: 40,
          },
        },
      ],
    });

    render(
      <MemoryRouter initialEntries={['/editor/canvas_page/7']}>
        <Routes>
          <Route
            path="/editor/:entityType/:entityId"
            element={
              <HeadlessComponentPreview
                component={component}
                settings={settings}
              />
            }
          />
        </Routes>
      </MemoryRouter>,
    );

    const preview = screen.getByLabelText('Example headless preview thumbnail');
    expect(preview).toHaveStyle({
      width: '100px',
      height: '40px',
      visibility: 'visible',
    });
    expect(preview.firstElementChild).toHaveStyle({
      transform: 'translate(-20px, -30px) scale(1)',
    });
  });

  it('uses the visible portion of a component that intersects the iframe', () => {
    sessionMock.mockReturnValue({
      geometry: [
        {
          type: 'component',
          id: 'offscreen-component',
          markerFormat: 'template',
          rect: {
            top: 900,
            right: 2400,
            bottom: 1900,
            left: 1400,
            width: 1000,
            height: 1000,
          },
        },
        {
          type: 'component',
          id: 'partially-visible-component',
          markerFormat: 'template',
          rect: {
            top: 780,
            right: 1230,
            bottom: 830,
            left: 1170,
            width: 60,
            height: 50,
          },
        },
      ],
    });

    render(
      <MemoryRouter initialEntries={['/editor/canvas_page/7']}>
        <Routes>
          <Route
            path="/editor/:entityType/:entityId"
            element={
              <HeadlessComponentPreview
                component={component}
                settings={settings}
              />
            }
          />
        </Routes>
      </MemoryRouter>,
    );

    const preview = screen.getByLabelText('Example headless preview thumbnail');
    expect(preview).toHaveStyle({
      width: '30px',
      height: '20px',
      visibility: 'visible',
    });
    expect(preview.firstElementChild).toHaveStyle({
      transform: 'translate(-1170px, -780px) scale(1)',
    });
  });

  it('keeps the preview hidden when all component geometry is offscreen', () => {
    sessionMock.mockReturnValue({
      geometry: [
        {
          type: 'component',
          id: 'offscreen-component',
          markerFormat: 'template',
          rect: {
            top: 100,
            right: 1500,
            bottom: 200,
            left: 1300,
            width: 200,
            height: 100,
          },
        },
      ],
    });

    render(
      <MemoryRouter initialEntries={['/editor/canvas_page/7']}>
        <Routes>
          <Route
            path="/editor/:entityType/:entityId"
            element={
              <HeadlessComponentPreview
                component={component}
                settings={settings}
              />
            }
          />
        </Routes>
      </MemoryRouter>,
    );

    expect(
      screen.getByLabelText('Example headless preview thumbnail'),
    ).toHaveStyle({
      width: '300px',
      height: '200px',
      visibility: 'hidden',
    });
  });

  it('uses the selected preview entity in the template editor', () => {
    sessionMock.mockReturnValue({
      geometry: [],
    });

    render(
      <MemoryRouter initialEntries={['/template/node/article/full/42']}>
        <Routes>
          <Route
            path="/template/:entityType/:bundle/:viewMode/:previewEntityId"
            element={
              <HeadlessComponentPreview
                component={component}
                settings={settings}
              />
            }
          />
        </Routes>
      </MemoryRouter>,
    );

    expect(sessionMock).toHaveBeenCalledWith(
      expect.any(Object),
      settings,
      'node',
      '42',
      undefined,
      800,
      { componentPreviewId: 'js.example' },
    );
  });
});
