import { useMemo, useState } from 'react';
import { Provider } from 'react-redux';
import { describe, expect, it, vi } from 'vitest';
import { Provider as TooltipProvider } from '@radix-ui/react-tooltip';
import { Theme } from '@radix-ui/themes';
import { configureStore } from '@reduxjs/toolkit';
import { fireEvent, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import {
  setViewportMinHeight,
  setViewportWidth,
  uiSliceReducer,
} from '@/features/ui/uiSlice';

import { PageVersionComparisonView } from './PageVersionComparisonView';

import type React from 'react';
import type { PageVersionSelection } from './PageVersionComparisonView';

import styles from './VersionComparisonPage.module.css';

vi.mock('@assets/icons/justify-stretch.svg?react', () => ({
  default: () => <svg aria-hidden="true" />,
}));

const publishedVersion = {
  html: '<main><h1>Published headline</h1></main>',
  updated: 'Updated 8/19/26 at 12:01 PM',
};

const newVersion = {
  html: '<main><h1>New headline</h1></main>',
  updated: 'Updated 8/20/26 at 8:10 PM',
  changed: true,
};

const createStore = () =>
  configureStore({
    reducer: {
      ui: uiSliceReducer,
    },
  });

const firePointerEvent = (
  element: HTMLElement,
  type: string,
  init: {
    button?: number;
    pointerId: number;
    clientX: number;
    clientY: number;
  },
) => {
  const event = new Event(type, { bubbles: true, cancelable: true });
  Object.defineProperties(event, {
    button: { value: init.button ?? 0 },
    pointerId: { value: init.pointerId },
    clientX: { value: init.clientX },
    clientY: { value: init.clientY },
  });
  fireEvent(element, event);
};

const setScrollMetrics = (
  element: HTMLElement,
  metrics: {
    clientHeight: number;
    clientWidth: number;
    scrollHeight: number;
    scrollWidth: number;
  },
) => {
  Object.defineProperties(element, {
    clientHeight: { configurable: true, value: metrics.clientHeight },
    clientWidth: { configurable: true, value: metrics.clientWidth },
    scrollHeight: { configurable: true, value: metrics.scrollHeight },
    scrollWidth: { configurable: true, value: metrics.scrollWidth },
  });
};

const ComparisonHarness = () => {
  const store = useMemo(() => createStore(), []);
  const [selectedVersion, setSelectedVersion] =
    useState<PageVersionSelection>();

  return (
    <Theme accentColor="blue" hasBackground={false}>
      <TooltipProvider>
        <Provider store={store}>
          <PageVersionComparisonView
            entityId="1"
            entityType="canvas_page"
            publishedVersion={publishedVersion}
            newVersion={newVersion}
            selectedVersion={selectedVersion}
            onSelectVersion={(version) =>
              setSelectedVersion((currentVersion) =>
                currentVersion === version ? undefined : version,
              )
            }
          />
        </Provider>
      </TooltipProvider>
    </Theme>
  );
};

const renderComparison = (
  props: Partial<React.ComponentProps<typeof PageVersionComparisonView>> = {},
) => {
  const store = createStore();
  const result = render(
    <Theme accentColor="blue" hasBackground={false}>
      <TooltipProvider>
        <Provider store={store}>
          <PageVersionComparisonView
            entityId="1"
            entityType="canvas_page"
            publishedVersion={publishedVersion}
            newVersion={newVersion}
            {...props}
          />
        </Provider>
      </TooltipProvider>
    </Theme>,
  );
  return { ...result, store };
};

describe('PageVersionComparisonView', () => {
  it('renders updated timestamps in the version headers', () => {
    render(<ComparisonHarness />);

    expect(screen.getByText('Updated 8/19/26 at 12:01 PM')).toBeInTheDocument();
    expect(screen.getByText('Updated 8/20/26 at 8:10 PM')).toBeInTheDocument();
  });

  it('only renders the visual comparison tab', () => {
    render(<ComparisonHarness />);

    expect(screen.getByRole('tab', { name: /Visual/ })).toBeInTheDocument();
    expect(screen.queryByRole('tab', { name: /Text/ })).not.toBeInTheDocument();
    expect(
      screen.queryByRole('tab', { name: /Props/ }),
    ).not.toBeInTheDocument();
  });

  it('applies the selected card style when a version is selected', async () => {
    const user = userEvent.setup();
    render(<ComparisonHarness />);

    const publishedSelect = screen.getByRole('button', {
      name: 'Select Published version',
    });
    const publishedCard = screen.getByTestId('conflict-published-version-card');
    const newCard = screen.getByTestId('conflict-new-version-card');

    expect(publishedCard).not.toHaveClass(styles.cardSelected);
    expect(newCard).not.toHaveClass(styles.cardSelected);

    await user.click(publishedSelect);

    expect(publishedCard).toHaveClass(styles.cardSelected);
    expect(newCard).not.toHaveClass(styles.cardSelected);
    expect(publishedSelect).toHaveAttribute('aria-pressed', 'true');
  });

  it('renders global regions in the visual comparison iframe', () => {
    renderComparison({
      publishedVersion: {
        ...publishedVersion,
        html: `<!doctype html>
          <html>
            <body class="path-canvas toolbar-fixed">
              <header class="site-header">Published header</header>
              <main><h1>Published headline</h1></main>
              <footer class="site-footer">Published footer</footer>
            </body>
          </html>`,
      },
      newVersion: {
        ...newVersion,
        html: `<!doctype html>
          <html>
            <body class="path-canvas toolbar-fixed">
              <header class="site-header">New header</header>
              <main><h1>New headline</h1></main>
              <footer class="site-footer">New footer</footer>
            </body>
          </html>`,
      },
    });

    const [publishedFrame, newFrame] = screen.getAllByTitle(
      'Page version preview',
    );
    const publishedSrcDoc = publishedFrame.getAttribute('srcdoc') ?? '';
    const newSrcDoc = newFrame.getAttribute('srcdoc') ?? '';

    expect(publishedSrcDoc).toContain('Published header');
    expect(publishedSrcDoc).toContain('Published footer');
    expect(publishedSrcDoc).toContain('class="path-canvas"');
    expect(newSrcDoc).toContain('New header');
    expect(newSrcDoc).toContain('New footer');
    expect(newSrcDoc).toContain('class="path-canvas"');
  });

  it('expands visual iframe content so the preview box scrolls', () => {
    renderComparison();

    const [publishedFrame] = screen.getAllByTitle(
      'Page version preview',
    ) as HTMLIFrameElement[];
    const previewDocument = {
      documentElement: {
        offsetHeight: 960,
        scrollHeight: 960,
      },
      body: {
        offsetHeight: 960,
        scrollHeight: 960,
      },
      images: [],
    };
    Object.defineProperty(publishedFrame, 'contentDocument', {
      configurable: true,
      value: previewDocument,
    });

    fireEvent.load(publishedFrame);

    expect(publishedFrame).toHaveAttribute('scrolling', 'no');
    expect(publishedFrame.parentElement).toHaveStyle({
      height: '960px',
      minHeight: '960px',
    });
  });

  it('supports dragging the visual preview box to scroll it', () => {
    render(<ComparisonHarness />);

    const [scrollArea, syncedScrollArea] = screen.getAllByTestId(
      'canvas-version-preview-scroll-area',
    ) as HTMLDivElement[];
    scrollArea.scrollLeft = 10;
    scrollArea.scrollTop = 20;

    firePointerEvent(scrollArea, 'pointerdown', {
      button: 0,
      pointerId: 1,
      clientX: 100,
      clientY: 100,
    });
    firePointerEvent(scrollArea, 'pointermove', {
      pointerId: 1,
      clientX: 70,
      clientY: 60,
    });

    expect(scrollArea).toHaveAttribute('data-dragging', 'true');
    expect(scrollArea.scrollLeft).toBe(40);
    expect(scrollArea.scrollTop).toBe(60);
    expect(syncedScrollArea.scrollLeft).toBe(40);
    expect(syncedScrollArea.scrollTop).toBe(60);

    firePointerEvent(scrollArea, 'pointerup', {
      pointerId: 1,
      clientX: 70,
      clientY: 60,
    });

    expect(scrollArea).not.toHaveAttribute('data-dragging');
  });

  it('zooms the visual previews with editor keyboard shortcuts', () => {
    const { store } = renderComparison();

    expect(store.getState().ui.editorViewPort.scale).toBe(1);

    fireEvent.keyDown(window, { code: 'Equal' });

    expect(store.getState().ui.editorViewPort.scale).toBe(1.1);

    fireEvent.keyDown(window, { code: 'Minus' });

    expect(store.getState().ui.editorViewPort.scale).toBe(1);
  });

  it('zooms the visual previews with modifier wheel gestures', () => {
    const { store } = renderComparison();
    const [scrollArea] = screen.getAllByTestId(
      'canvas-version-preview-scroll-area',
    ) as HTMLDivElement[];
    const [publishedFrame] = screen.getAllByTitle(
      'Page version preview',
    ) as HTMLIFrameElement[];

    store.dispatch(setViewportWidth(1024));
    store.dispatch(setViewportMinHeight(768));

    fireEvent.wheel(scrollArea, { ctrlKey: true, deltaY: -100 });

    expect(store.getState().ui.editorViewPort.scale).toBeCloseTo(1.1);
    expect(publishedFrame.parentElement).toHaveStyle({
      width: '1024px',
      height: '768px',
    });
    expect(
      parseFloat(
        publishedFrame.parentElement?.parentElement?.style.width ?? '',
      ),
    ).toBeCloseTo(1126.4);
    expect(
      parseFloat(
        publishedFrame.parentElement?.parentElement?.style.height ?? '',
      ),
    ).toBeCloseTo(844.8);
  });

  it('keeps the visual preview box scroll positions synchronized', () => {
    render(<ComparisonHarness />);

    const [publishedScrollArea, newScrollArea] = screen.getAllByTestId(
      'canvas-version-preview-scroll-area',
    ) as HTMLDivElement[];
    setScrollMetrics(publishedScrollArea, {
      clientHeight: 200,
      clientWidth: 200,
      scrollHeight: 1000,
      scrollWidth: 800,
    });
    setScrollMetrics(newScrollArea, {
      clientHeight: 200,
      clientWidth: 200,
      scrollHeight: 600,
      scrollWidth: 500,
    });

    publishedScrollArea.scrollLeft = 300;
    publishedScrollArea.scrollTop = 400;
    fireEvent.scroll(publishedScrollArea);

    expect(newScrollArea.scrollLeft).toBe(150);
    expect(newScrollArea.scrollTop).toBe(200);
  });
});
