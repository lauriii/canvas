import { useMemo, useState } from 'react';
import { Provider } from 'react-redux';
import { describe, expect, it, vi } from 'vitest';
import { Provider as TooltipProvider } from '@radix-ui/react-tooltip';
import { Theme } from '@radix-ui/themes';
import { configureStore } from '@reduxjs/toolkit';
import { fireEvent, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import { uiSliceReducer } from '@/features/ui/uiSlice';

import { PageConflictComparisonView } from './ConflictResolutionView';

import type { ConflictVersionSelection } from './ConflictResolutionView';

import styles from './ConflictResolutionPage.module.css';

vi.mock('@assets/icons/justify-stretch.svg?react', () => ({
  default: () => <svg aria-hidden="true" />,
}));

const publishedVersion = {
  html: '<main><h1>Published headline</h1></main>',
  text: 'Published headline',
  updated: 'Updated 8/19/26 at 12:01 PM',
  props: {
    title: 'Published headline',
  },
};

const newVersion = {
  html: '<main><h1>New headline</h1></main>',
  text: 'New headline',
  updated: 'Updated 8/20/26 at 8:10 PM',
  props: {
    title: 'New headline',
  },
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
    useState<ConflictVersionSelection>();

  return (
    <Theme accentColor="blue" hasBackground={false}>
      <TooltipProvider>
        <Provider store={store}>
          <PageConflictComparisonView
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

describe('PageConflictComparisonView', () => {
  it('renders updated timestamps in the version headers', () => {
    render(<ComparisonHarness />);

    expect(screen.getByText('Updated 8/19/26 at 12:01 PM')).toBeInTheDocument();
    expect(screen.getByText('Updated 8/20/26 at 8:10 PM')).toBeInTheDocument();
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

  it('keeps the selected version when switching tabs', async () => {
    const user = userEvent.setup();
    render(<ComparisonHarness />);

    await user.click(
      screen.getByRole('button', {
        name: 'Select New version',
      }),
    );

    expect(screen.getByTestId('conflict-new-version-card')).toHaveClass(
      styles.cardSelected,
    );

    await user.click(screen.getByRole('tab', { name: /Text/ }));

    expect(screen.getByTestId('conflict-new-version-card')).toHaveClass(
      styles.cardSelected,
    );

    await user.click(screen.getByRole('tab', { name: /Props/ }));

    expect(screen.getByTestId('conflict-new-version-card')).toHaveClass(
      styles.cardSelected,
    );
  });

  it('only shows the viewport selector on the visual tab', async () => {
    const user = userEvent.setup();
    const viewportButtonName =
      /Mobile|Tablet|Desktop|Large Desktop|Select viewport/;
    render(<ComparisonHarness />);

    expect(
      screen.getByRole('button', { name: viewportButtonName }),
    ).toBeInTheDocument();

    await user.click(screen.getByRole('tab', { name: /Text/ }));

    expect(
      screen.queryByRole('button', { name: viewportButtonName }),
    ).not.toBeInTheDocument();

    await user.click(screen.getByRole('tab', { name: /Visual/ }));

    expect(
      screen.getByRole('button', { name: viewportButtonName }),
    ).toBeInTheDocument();
  });

  it('supports dragging the visual preview to scroll it', () => {
    render(<ComparisonHarness />);

    const [scrollArea, syncedScrollArea] = screen.getAllByTestId(
      'canvas-conflict-preview-scroll-area',
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

  it('keeps the visual preview scroll positions synchronized', () => {
    render(<ComparisonHarness />);

    const [publishedScrollArea, newScrollArea] = screen.getAllByTestId(
      'canvas-conflict-preview-scroll-area',
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
