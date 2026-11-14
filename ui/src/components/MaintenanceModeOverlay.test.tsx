import { useHotkeys } from 'react-hotkeys-hook';
import { describe, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import AppWrapper from '@tests/vitest/components/AppWrapper';

import { makeStore } from '@/app/store';
import MaintenanceModeOverlay from '@/components/MaintenanceModeOverlay';
import UndoRedo from '@/components/UndoRedo';
import { setMaintenanceMessage } from '@/features/error-handling/queryErrorSlice';
import {
  selectPageData,
  setInitialPageData,
  setPageData,
} from '@/features/pageData/pageDataSlice';

// Registers the editor's document-level shortcuts the same way EditorFrame
// does.
const EditorShortcuts = ({ onShortcut }: { onShortcut: () => void }) => {
  useHotkeys(['Backspace', 'Delete', 'mod+c', 'mod+v'], onShortcut);
  return null;
};

const renderEditor = (maintenance: boolean) => {
  const store = makeStore();
  // An edit to undo.
  store.dispatch(
    setInitialPageData({ values: { title: 'Before' }, owner: null }),
  );
  store.dispatch(setPageData({ title: 'After' }));
  if (maintenance) {
    store.dispatch(setMaintenanceMessage('Back soon.'));
  }
  const onShortcut = vi.fn();
  render(
    <AppWrapper store={store} location="/" path="/">
      <UndoRedo />
      <EditorShortcuts onShortcut={onShortcut} />
      <MaintenanceModeOverlay />
    </AppWrapper>,
  );
  return { store, onShortcut };
};

const pressEditorShortcuts = () =>
  userEvent.keyboard(
    '{Delete}{Backspace}{Control>}c{/Control}{Control>}v{/Control}{Control>}z{/Control}',
  );

describe('MaintenanceModeOverlay', () => {
  it('renders nothing while the site is online', () => {
    const store = makeStore();

    render(
      <AppWrapper store={store} location="/" path="/">
        <MaintenanceModeOverlay />
      </AppWrapper>,
    );

    expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument();
  });

  it('keeps keyboard focus inside the dialog', async () => {
    const store = makeStore();
    store.dispatch(setMaintenanceMessage('Back soon.'));

    render(
      <AppWrapper store={store} location="/" path="/">
        <button>Behind the dialog</button>
        <MaintenanceModeOverlay />
      </AppWrapper>,
    );

    const dialog = await screen.findByRole('alertdialog', {
      name: 'Site is currently under maintenance',
    });
    expect(dialog).toHaveFocus();
    await userEvent.tab();
    expect(dialog).toHaveFocus();
    await userEvent.tab({ shift: true });
    expect(dialog).toHaveFocus();
  });

  it('runs editor keyboard shortcuts while the site is online', async () => {
    const { store, onShortcut } = renderEditor(false);

    await pressEditorShortcuts();

    expect(onShortcut).toHaveBeenCalledTimes(4);
    expect(selectPageData(store.getState()).title).toBe('Before');
  });

  it('keeps editor keyboard shortcuts from running behind the dialog', async () => {
    const { store, onShortcut } = renderEditor(true);
    expect(
      await screen.findByRole('alertdialog', {
        name: 'Site is currently under maintenance',
      }),
    ).toHaveFocus();

    await pressEditorShortcuts();

    expect(selectPageData(store.getState()).title).toBe('After');
    expect(onShortcut).not.toHaveBeenCalled();
  });
});
