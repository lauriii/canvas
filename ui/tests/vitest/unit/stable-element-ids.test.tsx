/**
 * Guards the stable element identifiers documented in docs/ui-element-ids.md.
 *
 * External tooling selects these elements by `data-canvas-element`, so the
 * whole point of the convention is that an unrelated refactor cannot quietly
 * drop one. These assertions cover affordances that have no other component
 * test; the panel, library, and publish identifiers are asserted in the tests
 * colocated with those components.
 */

import { describe, expect, it, vi } from 'vitest';
import { render } from '@testing-library/react';
import AppWrapper from '@tests/vitest/components/AppWrapper';

import { makeStore } from '@/app/store';
import AiToggleButton from '@/components/aiExtension/AiToggleButton';
import EmptyStateCallout from '@/components/EmptyStateCallout';
import ErrorCard from '@/components/error/ErrorCard';
import ExtensionButton from '@/components/extensions/ExtensionButton';
import PreviewControls from '@/components/PreviewControls';
import UndoRedo from '@/components/UndoRedo';
import ZoomControl from '@/components/zoom/ZoomControl';

import type { ReactNode } from 'react';

const renderInApp = (
  children: ReactNode,
  location = '/',
  path = '*',
): HTMLElement => {
  const { container } = render(
    <AppWrapper store={makeStore()} location={location} path={path}>
      {children}
    </AppWrapper>,
  );
  return container;
};

const elementIds = (container: HTMLElement): string[] =>
  Array.from(container.querySelectorAll('[data-canvas-element]')).map(
    (element) => element.getAttribute('data-canvas-element') as string,
  );

describe('stable element identifiers', () => {
  it('identifies undo and redo', () => {
    const container = renderInApp(<UndoRedo />);

    expect(elementIds(container)).toEqual(
      expect.arrayContaining(['undo', 'redo']),
    );
  });

  it('identifies the zoom control', () => {
    const container = renderInApp(<ZoomControl buttonClass="" />);

    expect(
      container.querySelector('[data-canvas-element="zoom-level"]'),
    ).not.toBeNull();
  });

  it('identifies entering the preview', () => {
    const container = renderInApp(
      <PreviewControls isPreview={false} />,
      '/editor/canvas_page/1',
      '/editor/:entityType/:entityId',
    );

    expect(
      container.querySelector('[data-canvas-element="preview-enter"]'),
    ).not.toBeNull();
  });

  it('identifies leaving the preview and the width selector', () => {
    const container = renderInApp(
      <PreviewControls isPreview={true} />,
      '/preview/canvas_page/1/full',
      '/preview/:entityType/:entityId/:width',
    );

    expect(elementIds(container)).toEqual(
      expect.arrayContaining(['preview-width', 'preview-exit']),
    );
  });

  it('identifies the error card and its retry button', () => {
    const container = renderInApp(
      <ErrorCard error="Something broke" resetErrorBoundary={vi.fn()} />,
    );

    expect(elementIds(container)).toEqual(
      expect.arrayContaining(['error-card', 'error-retry']),
    );
  });

  it('identifies the empty state callout', () => {
    const container = renderInApp(<EmptyStateCallout title="Nothing here" />);

    expect(
      container.querySelector('[data-canvas-element="empty-state"]'),
    ).not.toBeNull();
  });

  it('identifies the AI panel toggle', () => {
    const container = renderInApp(<AiToggleButton />);

    expect(
      container.querySelector('[data-canvas-element="ai-panel-toggle"]'),
    ).not.toBeNull();
  });

  it('keys each extension button by its machine name', () => {
    const container = renderInApp(
      <ExtensionButton
        extension={{
          id: 'my_extension',
          name: 'My extension',
          description: 'An extension',
          icon: '/icon.svg',
          url: '/app/my_extension',
          api_version: '1.0',
        }}
      />,
    );

    const button = container.querySelector(
      '[data-canvas-element="extension-open"]',
    );
    expect(button).not.toBeNull();
    expect(button).toHaveAttribute('data-canvas-element-key', 'my_extension');
  });
});
