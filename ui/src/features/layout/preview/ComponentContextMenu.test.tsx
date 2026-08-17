import { Provider } from 'react-redux';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { DropdownMenu, Theme } from '@radix-ui/themes';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import { makeStore } from '@/app/store';
import {
  NodeType,
  personalizePage,
  setInitialLayoutModel,
} from '@/features/layout/layoutModelSlice';
import {
  findRootSwitch,
  getSwitchCases,
} from '@/features/layout/personalizationUtils';

import { ComponentContextMenuContent } from './ComponentContextMenu';

import type { AppStore } from '@/app/store';
import type { ComponentNode } from '@/features/layout/layoutModelSlice';
import type * as ComponentAndLayoutService from '@/services/componentAndLayout';

const mocks = vi.hoisted(() => {
  const baseComponents = {
    'p13n.switch': { id: 'p13n.switch', version: 'v1', name: 'Switch' },
    'p13n.case': { id: 'p13n.case', version: 'v1', name: 'Case' },
    'sdc.hero': { id: 'sdc.hero', version: '1', name: 'Hero' },
  };
  return {
    baseComponents,
    components: baseComponents as Partial<typeof baseComponents>,
  };
});

// The shared setup mock of drupal-globals lacks getCanvasPermissions, which
// PermissionCheck resolves at import time.
vi.mock('@/utils/drupal-globals', () => ({
  getDrupal: () => ({ url: (path: string) => `/${path}` }),
  getBasePath: () => '/',
  getBaseUrl: () => '/',
  getDrupalSettings: () => ({ path: { baseUrl: '/' }, canvas: {} }),
  getCanvasSettings: () => ({}),
  getCanvasPermissions: () => ({}),
  getCanvasModuleBaseUrl: () => '/modules/contrib/canvas',
  setCanvasDrupalSetting: () => undefined,
}));

vi.mock('@/services/componentAndLayout', async (importOriginal) => {
  const actual = await importOriginal<typeof ComponentAndLayoutService>();
  return {
    ...actual,
    useGetComponentsQuery: () => ({
      data: mocks.components,
      isLoading: false,
      error: undefined,
    }),
  };
});

const HERO_UUID = 'hero-uuid';

const buildStore = (): AppStore => {
  const store = makeStore();
  store.dispatch(
    setInitialLayoutModel({
      layout: [
        {
          nodeType: NodeType.Region,
          id: 'content',
          name: 'Content',
          components: [
            {
              nodeType: NodeType.Component,
              uuid: HERO_UUID,
              type: 'sdc.hero@1',
              slots: [],
            },
          ],
        },
      ],
      model: { [HERO_UUID]: { resolved: { title: 'Hello' } } },
      updatePreview: false,
      isInitialized: true,
      translations: {},
    }),
  );
  return store;
};

const getPresent = (store: AppStore) => store.getState().layoutModel.present;

const openMenu = async (store: AppStore, component: ComponentNode) => {
  const user = userEvent.setup();
  render(
    <Provider store={store}>
      <Theme>
        <MemoryRouter>
          <DropdownMenu.Root>
            <DropdownMenu.Trigger>
              <button>Open menu</button>
            </DropdownMenu.Trigger>
            <ComponentContextMenuContent
              component={component}
              menuType="dropdown"
            />
          </DropdownMenu.Root>
        </MemoryRouter>
      </Theme>
    </Provider>,
  );
  await user.click(screen.getByRole('button', { name: 'Open menu' }));
  return user;
};

const queryPersonalizeItem = () =>
  screen.queryByRole('menuitem', { name: 'Personalize component' });

describe('ComponentContextMenu personalize item', () => {
  beforeEach(() => {
    mocks.components = mocks.baseComponents;
  });

  it('offers to personalize a regular component and asks for confirmation', async () => {
    const store = buildStore();
    const hero = getPresent(store).layout[0].components[0];
    const user = await openMenu(store, hero);

    const item = queryPersonalizeItem();
    expect(item).toBeInTheDocument();
    await user.click(item!);

    expect(store.getState().dialog.personalizeComponentConfirm).toEqual({
      open: true,
      data: { componentUuid: HERO_UUID },
    });
  });

  it('is absent for the personalization components themselves', async () => {
    const store = buildStore();
    store.dispatch(
      personalizePage({
        switchComponentType: 'p13n.switch@v1',
        caseComponentType: 'p13n.case@v1',
      }),
    );
    const rootSwitch = findRootSwitch(getPresent(store).layout[0]);
    await openMenu(store, rootSwitch!);

    expect(queryPersonalizeItem()).toBeNull();
  });

  it('is absent for components inside a switch subtree', async () => {
    const store = buildStore();
    store.dispatch(
      personalizePage({
        switchComponentType: 'p13n.switch@v1',
        caseComponentType: 'p13n.case@v1',
      }),
    );
    const rootSwitch = findRootSwitch(getPresent(store).layout[0]);
    const hero = getSwitchCases(rootSwitch!)[0].slots[0].components[0];
    expect(hero.uuid).toBe(HERO_UUID);
    await openMenu(store, hero);

    expect(queryPersonalizeItem()).toBeNull();
  });

  it('is absent when the personalization components are unavailable', async () => {
    mocks.components = {
      'sdc.hero': mocks.baseComponents['sdc.hero'],
    };
    const store = buildStore();
    const hero = getPresent(store).layout[0].components[0];
    await openMenu(store, hero);

    expect(queryPersonalizeItem()).toBeNull();
  });
});
