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

const renderList = (onMove = vi.fn()) => {
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
        pageFolders={{
          folders: [folder],
          folderOfPage: { 'uuid-careers': folder.id },
          canManage: true,
          onMove,
        }}
      />
    </AppWrapper>,
  );
  return { onMove, patched };
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
    const { onMove } = renderList();

    const row = screen.getByText(/About/).closest('[data-canvas-page-id]');
    await user.click(
      within(row as HTMLElement).getByLabelText('Open contextual menu'),
    );
    // The keyboard opens the submenu with its first folder focused.
    (await screen.findByText('Move to folder')).focus();
    await user.keyboard('{ArrowRight}');
    expect(
      await screen.findByRole('menuitem', { name: 'Campaigns' }),
    ).toHaveFocus();
    await user.keyboard('{Enter}');

    expect(onMove).toHaveBeenCalledWith(
      expect.objectContaining({ uuid: 'uuid-about' }),
      folder.id,
    );
  });
});
