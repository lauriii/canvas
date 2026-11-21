import { describe, expect, it, vi } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import AppWrapper from '@tests/vitest/components/AppWrapper';

import { makeStore } from '@/app/store';
import PageList from '@/components/pageInfo/PageList';
import { setConfiguration } from '@/features/configuration/configurationSlice';

import type { ContentStub } from '@/types/Content';

vi.mock('@assets/icons/folder.svg?react', () => ({
  default: () => null,
}));

vi.mock('@/utils/permissions', () => ({
  hasPermission: () => true,
  hasAnyPermission: () => true,
  hasPermissions: () => true,
}));

const page = (id: number, title: string, uuid: string) =>
  ({
    id,
    uuid,
    title,
    path: `/${title.toLowerCase()}`,
    internalPath: `/page/${id}`,
    status: true,
    autoSaveLabel: null,
    autoSavePath: '',
    links: {},
  }) as ContentStub;

// The folder holds one page that is not among the loaded pages.
const folder = {
  id: 'folder-1',
  name: 'Campaigns',
  type: 'canvas_page',
  weight: 0,
  items: ['uuid-careers', 'uuid-not-loaded'],
};

const renderList = (path?: string) => {
  const patched: unknown[] = [];
  vi.stubGlobal(
    'fetch',
    vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
      const request =
        input instanceof Request ? input : new Request(input, init);
      if (request.url.endsWith('session/token')) {
        return new Response('csrf-token');
      }
      if (request.method === 'PATCH') {
        patched.push(await request.clone().json());
        return Response.json(folder);
      }
      return Response.json({ [folder.id]: folder });
    }),
  );
  const store = makeStore();
  store.dispatch(
    setConfiguration({
      baseUrl: 'http://localhost/',
      entityType: 'none',
      entity: 'none',
      isNew: false,
      isPublished: false,
      devMode: false,
    }),
  );
  render(
    <AppWrapper store={store} location="/" path="/">
      <PageList
        pageItems={[
          page(1, 'About', 'uuid-about'),
          page(2, 'Careers', 'uuid-careers'),
        ]}
        folders={{
          folders: { [folder.id]: { ...folder, path } },
          componentIndexedFolders: {
            'uuid-careers': folder.id,
            'uuid-not-loaded': folder.id,
          },
        }}
      />
    </AppWrapper>,
  );
  return { patched };
};

describe('PageList with folders', () => {
  it('lists a page in its folder, and the others below the folders', () => {
    renderList();

    const loose = screen.getByTestId(
      'canvas-uncategorized-drop-zone-canvas_page',
    );
    expect(within(loose).getByText(/About/)).toBeInTheDocument();
    expect(within(loose).queryByText(/Careers/)).not.toBeInTheDocument();
    expect(screen.getByText('Campaigns')).toBeInTheDocument();
    expect(screen.getByText(/Careers/)).toBeInTheDocument();
  });

  it('keeps the pages that are not loaded when a folder is renamed', async () => {
    const user = userEvent.setup();
    const { patched } = renderList();

    await user.dblClick(screen.getByText('Campaigns'));
    const input = await screen.findByTestId('canvas-folder-rename-input');
    await user.clear(input);
    await user.type(input, 'Launches{Enter}');

    await waitFor(() => expect(patched).toHaveLength(1));
    expect(patched[0]).toMatchObject({
      name: 'Launches',
      items: ['uuid-careers', 'uuid-not-loaded'],
    });
  });

  it('moves a page from its menu', async () => {
    const user = userEvent.setup();
    const { patched } = renderList();

    const row = screen.getByText(/About/).closest('[data-canvas-page-id]');
    // The menu has no items until the folders have loaded.
    await user.click(
      await within(row as HTMLElement).findByLabelText('Open contextual menu'),
    );
    // The keyboard opens the submenu with its first folder focused.
    (await screen.findByText('Move to folder')).focus();
    await user.keyboard('{ArrowRight}');
    expect(
      await screen.findByRole('menuitem', { name: 'Campaigns' }),
    ).toHaveFocus();
    await user.keyboard('{Enter}');

    await waitFor(() => expect(patched).toHaveLength(1));
    expect(patched[0]).toMatchObject({
      items: ['uuid-careers', 'uuid-not-loaded', 'uuid-about'],
    });
  });

  it('focuses the name of a new folder, then the "New" button', async () => {
    const user = userEvent.setup();
    renderList();

    const newButton = screen.getByTestId('canvas-page-list-new-button');
    await user.click(newButton);
    await user.click(
      await screen.findByTestId('canvas-page-list-new-folder-button'),
    );
    const input = await screen.findByTestId(
      'canvas-manage-library-new-folder-name',
    );
    await waitFor(() => expect(input).toHaveFocus());
    // The name is selected, so typing replaces it.
    await user.keyboard('Launches');
    expect(input).toHaveValue('Launches');

    await user.keyboard('{Escape}');
    expect(input).not.toBeInTheDocument();
    expect(newButton).toHaveFocus();
  });

  it('sets the URL path of a folder, and keeps its items', async () => {
    const user = userEvent.setup();
    const { patched } = renderList();

    const folderRow = screen
      .getByText('Campaigns')
      .closest('[data-canvas-folder-name]');
    await user.click(
      within(folderRow as HTMLElement).getByLabelText('Open contextual menu'),
    );
    await user.click(await screen.findByText('Set URL path'));
    // The input replaces the name in the row, with a path made of the name
    // selected.
    const input = await screen.findByTestId('canvas-folder-path-input');
    await waitFor(() => expect(input).toHaveFocus());
    expect(input).toHaveValue('/campaigns');
    await user.keyboard('Offers{Enter}');
    expect(screen.getByText(/Start with a slash/)).toBeInTheDocument();
    expect(patched).toHaveLength(0);
    await user.clear(input);
    await user.type(input, '/offers{Enter}');

    await waitFor(() => expect(patched).toHaveLength(1));
    expect(patched[0]).toMatchObject({
      path: '/offers',
      items: ['uuid-careers', 'uuid-not-loaded'],
    });
    await waitFor(() => expect(input).not.toBeInTheDocument());
  });

  it('edits the URL path of a folder on a double-click', async () => {
    const user = userEvent.setup();
    renderList('/offers');

    // As a double-click on the name renames the folder.
    await user.dblClick(screen.getByText('/offers'));
    expect(await screen.findByTestId('canvas-folder-path-input')).toHaveValue(
      '/offers',
    );
  });
});
