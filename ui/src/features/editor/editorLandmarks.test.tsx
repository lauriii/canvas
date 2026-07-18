import { describe, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import AppWrapper from '@tests/vitest/components/AppWrapper';

import { makeStore } from '@/app/store';
import Topbar from '@/components/topbar/Topbar';
import EditorLayout from '@/features/editor/EditorLayout';
import { EditorFrameContext } from '@/features/ui/uiSlice';

// `drupal-globals` is mocked globally in the vitest setup; `getCanvasSettings`
// there returns an empty object, which keeps the topbar to its base controls.

// The vitest config has no svgr transform, so `?react` SVG imports resolve to a
// URL string; rendering that as an element throws. Stub the topbar's SVG icon.
vi.mock('@assets/icons/drop.svg?react', () => ({
  default: (props: Record<string, unknown>) => <svg {...props} />,
}));

// The editor canvas and the topbar children are heavy and not under test here;
// stub them so we can assert the landmark/heading scaffolding around them.
vi.mock('@/hooks/useCanvasHeadlessSettings', () => ({
  useCanvasHeadlessSettings: () => undefined,
}));
vi.mock('@/features/editor/Editor', () => ({
  default: () => <div data-testid="editor-stub" />,
}));
vi.mock('@/components/PageDataForm', () => ({
  default: () => <div data-testid="page-data-stub" />,
}));
// PageInfo also exports a constant that services import, so preserve named
// exports and only stub the heavy default component.
vi.mock('@/components/pageInfo/PageInfo', async (importOriginal) => {
  const actual = await importOriginal();
  return {
    ...(actual as object),
    default: () => <div data-testid="page-info-stub" />,
  };
});
vi.mock('@/components/UndoRedo', () => ({ default: () => <div /> }));
vi.mock('@/components/PreviewControls', () => ({ default: () => <div /> }));
vi.mock('@/features/notifications/NotificationBell', () => ({
  default: () => <div />,
}));
vi.mock('@/components/review/UnpublishedChanges', () => ({
  default: () => <div />,
}));
vi.mock('@/hooks/useHidePanelClasses', () => ({ default: () => [] }));

describe('Canvas editor landmarks and headings', () => {
  it('exposes the topbar as a banner landmark with a level-2 heading', () => {
    render(
      <AppWrapper store={makeStore()} location="/" path="/">
        <Topbar />
      </AppWrapper>,
    );
    // The name comes from the heading via aria-labelledby, so asserting it here
    // also guards the label association, not just the presence of the landmark.
    expect(screen.getByRole('banner', { name: 'Toolbar' })).toBeInTheDocument();
    expect(
      screen.getByRole('heading', { level: 2, name: 'Toolbar' }),
    ).toBeInTheDocument();
  });

  it('exposes the canvas as a main landmark and the right sidebar as a complementary landmark, each with a level-2 heading', () => {
    render(
      <AppWrapper store={makeStore()} location="/" path="/">
        <EditorLayout context={EditorFrameContext.ENTITY} />
      </AppWrapper>,
    );
    // Main editing area.
    expect(screen.getByRole('main', { name: 'Canvas' })).toBeInTheDocument();
    expect(
      screen.getByRole('heading', { level: 2, name: 'Canvas' }),
    ).toBeInTheDocument();
    // Right sidebar (contextual settings panel).
    expect(
      screen.getByRole('complementary', { name: 'Settings' }),
    ).toBeInTheDocument();
    expect(
      screen.getByRole('heading', { level: 2, name: 'Settings' }),
    ).toBeInTheDocument();
  });
});
