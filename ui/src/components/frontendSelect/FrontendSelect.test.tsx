import { Provider } from 'react-redux';
import { Link, MemoryRouter, useLocation } from 'react-router';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { Theme } from '@radix-ui/themes';
import { act, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import { makeStore } from '@/app/store';
import { FORM_TYPES } from '@/features/form/constants';
import { clearFieldValues } from '@/features/form/formStateSlice';
import { setInitialized } from '@/features/layout/layoutModelSlice';
import {
  clearSelection,
  EditorFrameContext,
  selectSelectedComponentUuid,
  setSelection,
} from '@/features/ui/uiSlice';
import { useCanvasHeadlessSettings } from '@/hooks/useCanvasHeadlessSettings';
import { componentAndLayoutApi } from '@/services/componentAndLayout';
import { componentInstanceFormApi } from '@/services/componentInstanceForm';
import { getCanvasSettings } from '@/utils/drupal-globals';

import FrontendSelect from './FrontendSelect';

import styles from './FrontendSelect.module.css';

const { mockSyncComponents, syncState } = vi.hoisted(() => ({
  mockSyncComponents: vi.fn(() => ({
    unwrap: () => Promise.resolve({}),
  })),
  syncState: { isLoading: false },
}));

vi.mock('@/services/headlessComponentSync', async () => {
  const actual = await vi.importActual('@/services/headlessComponentSync');
  return {
    ...actual,
    useSyncComponentsMutation: () => [mockSyncComponents, syncState],
  };
});

const FIRST_FRONTEND = 'https://first.example';
const SECOND_FRONTEND = 'https://second.example/app';

const store = makeStore();

const PAGE_URL = '/editor/canvas_page/1';

const LocationDisplay = () => {
  const { pathname, search, hash } = useLocation();
  return <div data-testid="current-location">{pathname + search + hash}</div>;
};
const currentUrl = () => screen.getByTestId('current-location').textContent;

const FrontendSelectHarness = ({ url = PAGE_URL }: { url?: string }) => {
  const settings = useCanvasHeadlessSettings();
  return settings ? (
    <Provider store={store}>
      <MemoryRouter initialEntries={[url]}>
        <Theme>
          <FrontendSelect settings={settings} />
        </Theme>
        <Link to="/editor/canvas_page/2/component/other-component">
          Other page
        </Link>
        <LocationDisplay />
      </MemoryRouter>
    </Provider>
  ) : null;
};

const syncResult = (created: number, updated: number) => ({
  created,
  updated,
  unchanged: 0,
  warnings: [],
  errors: [],
});

const reloadLayoutActions = [
  componentAndLayoutApi.util.invalidateTags([{ type: 'Layout' }]),
  componentInstanceFormApi.util.invalidateTags(['ComponentInstanceForm']),
  setInitialized(false),
  clearFieldValues(FORM_TYPES.COMPONENT_INSTANCE_FORM),
  // Clearing the selection through the selection hook.
  setSelection({ items: [], consecutive: true }),
];

const SELECTED_COMPONENT = 'a1b2c3d4-0000-4000-8000-000000000001';
const SELECTED_COMPONENT_URL = `${PAGE_URL}/component/${SELECTED_COMPONENT}`;
const formArgs = {
  queryString: `?form_canvas_selected=${SELECTED_COMPONENT}`,
  type: EditorFrameContext.ENTITY,
};
const cachedForm = () =>
  componentInstanceFormApi.endpoints.getComponentInstanceForm.select(formArgs)(
    store.getState(),
  ).data;

// Simulates an editor with a selected component whose form was already
// fetched, before a synchronization runs.
const selectComponentWithCachedForm = async () => {
  store.dispatch(setSelection({ items: [SELECTED_COMPONENT] }));
  await store.dispatch(
    componentInstanceFormApi.util.upsertQueryData(
      'getComponentInstanceForm',
      formArgs,
      { html: '<label>Heading (A)</label>', transforms: {} },
    ),
  );
};

const synchronizeFirstFrontend = async (url = SELECTED_COMPONENT_URL) => {
  render(<FrontendSelectHarness url={url} />);
  await waitFor(() => expect(mockSyncComponents).toHaveBeenCalledOnce());
  await act(() => new Promise((resolve) => setTimeout(resolve, 0)));
};

describe('FrontendSelect', () => {
  beforeEach(() => {
    getCanvasSettings().canAdministerHeadlessFrontends = true;
    getCanvasSettings().headless = {
      frontendUrl: FIRST_FRONTEND,
      frontends: [FIRST_FRONTEND, SECOND_FRONTEND],
      frontendOrigin: FIRST_FRONTEND,
      draftUrl: `${FIRST_FRONTEND}/api/draft`,
      assertionUrl: '/canvas-headless/assertion',
    };
  });

  afterEach(() => {
    delete getCanvasSettings().headless;
    delete getCanvasSettings().canAdministerHeadlessFrontends;
    window.localStorage.clear();
    mockSyncComponents.mockClear();
    syncState.isLoading = false;
    vi.useRealTimers();
    store.dispatch(clearSelection());
    store.dispatch(componentInstanceFormApi.util.resetApiState());
  });

  it('disables switching while component synchronization is running', () => {
    syncState.isLoading = true;

    render(<FrontendSelectHarness />);

    expect(screen.getByTestId('frontend-select-trigger')).toBeDisabled();
  });

  it('shows the sync indicator for at least two seconds, then fades it out', () => {
    vi.useFakeTimers();
    syncState.isLoading = true;
    const { rerender } = render(<FrontendSelectHarness />);
    expect(screen.getByTestId('frontend-sync-indicator')).toBeInTheDocument();

    syncState.isLoading = false;
    rerender(<FrontendSelectHarness />);
    act(() => vi.advanceTimersByTime(1999));
    expect(screen.getByTestId('frontend-sync-indicator')).toBeInTheDocument();

    act(() => vi.advanceTimersByTime(1));
    expect(screen.getByTestId('frontend-sync-indicator')).toHaveClass(
      styles.syncIndicatorFading,
    );

    act(() => vi.advanceTimersByTime(300));
    expect(
      screen.queryByTestId('frontend-sync-indicator'),
    ).not.toBeInTheDocument();
  });

  it('switches the embedded frontend', async () => {
    const user = userEvent.setup();
    render(<FrontendSelectHarness />);

    await user.click(screen.getByTestId('frontend-select-trigger'));
    await user.click(screen.getByTestId('frontend-option-1'));

    expect(screen.getByTestId('frontend-select-trigger')).toHaveTextContent(
      'second.example/app',
    );
    expect(screen.getByTestId('frontend-select-trigger')).not.toHaveTextContent(
      'https://',
    );
    expect(getCanvasSettings().headless?.draftUrl).toBe(
      `${SECOND_FRONTEND}/api/draft`,
    );
    expect(window.localStorage.getItem('canvas-headless-active-frontend')).toBe(
      SECOND_FRONTEND,
    );
    expect(mockSyncComponents).toHaveBeenCalledWith(FIRST_FRONTEND);
    expect(mockSyncComponents).toHaveBeenCalledWith(SECOND_FRONTEND);
  });
  it.each([
    ['creates', syncResult(1, 0), true],
    ['updates', syncResult(0, 1), true],
    ['changes nothing in', syncResult(0, 0), false],
  ])(
    'reloads the layout only when a synchronization %s components',
    async (_label, result, reloads) => {
      mockSyncComponents.mockImplementation(() => ({
        unwrap: () => Promise.resolve(result),
      }));
      const dispatch = vi.spyOn(store, 'dispatch');

      await synchronizeFirstFrontend(PAGE_URL);

      const actions = dispatch.mock.calls
        .map(([action]) => action)
        .filter((action) =>
          reloadLayoutActions.some(
            (expected) => JSON.stringify(expected) === JSON.stringify(action),
          ),
        );
      // The layout refetch starts before the editor re-seeds from it.
      expect(actions).toEqual(reloads ? reloadLayoutActions : []);
      // Without a selected component, the editor stays on the page URL.
      expect(currentUrl()).toBe(PAGE_URL);
      dispatch.mockRestore();
    },
  );

  it('does not reload the layout when the synchronization fails', async () => {
    mockSyncComponents.mockImplementation(() => ({
      unwrap: () => Promise.reject(new Error('The metadata endpoint failed.')),
    }));
    await selectComponentWithCachedForm();
    const dispatch = vi.spyOn(store, 'dispatch');

    await synchronizeFirstFrontend();

    const actions = dispatch.mock.calls.map(([action]) => action);
    expect(actions).not.toContainEqual(setInitialized(false));
    expect(selectSelectedComponentUuid(store.getState())).toBe(
      SELECTED_COMPONENT,
    );
    expect(currentUrl()).toBe(SELECTED_COMPONENT_URL);
    dispatch.mockRestore();
  });

  it('drops cached component forms when a synchronization changes only metadata', async () => {
    // Changing only prop titles updates the component without a new version,
    // so the layout and the form request stay the same.
    mockSyncComponents.mockImplementation(() => ({
      unwrap: () => Promise.resolve(syncResult(0, 1)),
    }));
    await selectComponentWithCachedForm();
    expect(cachedForm()).toBeDefined();

    await synchronizeFirstFrontend();

    expect(cachedForm()).toBeUndefined();
  });

  it('clears the selection and its URL when a synchronization changes components', async () => {
    // The upgrade can remove the selected component, for example when a slot
    // holding it no longer exists.
    mockSyncComponents.mockImplementation(() => ({
      unwrap: () => Promise.resolve(syncResult(0, 1)),
    }));
    await selectComponentWithCachedForm();

    await synchronizeFirstFrontend(`${SELECTED_COMPONENT_URL}?view=a#top`);

    expect(selectSelectedComponentUuid(store.getState())).toBeUndefined();
    expect(currentUrl()).toBe(`${PAGE_URL}?view=a#top`);
  });

  it('clears the selection on the page the editor navigated to during the synchronization', async () => {
    let finishSync: (result: ReturnType<typeof syncResult>) => void = () => {};
    mockSyncComponents.mockImplementation(() => ({
      unwrap: () =>
        new Promise<ReturnType<typeof syncResult>>((resolve) => {
          finishSync = resolve;
        }),
    }));
    await selectComponentWithCachedForm();
    await synchronizeFirstFrontend();

    await userEvent.click(screen.getByRole('link', { name: 'Other page' }));
    await act(async () => {
      finishSync(syncResult(0, 1));
      await new Promise((resolve) => setTimeout(resolve, 0));
    });

    expect(currentUrl()).toBe('/editor/canvas_page/2');
  });

  it('keeps the selection and cached forms when a synchronization changes nothing', async () => {
    mockSyncComponents.mockImplementation(() => ({
      unwrap: () => Promise.resolve(syncResult(0, 0)),
    }));
    await selectComponentWithCachedForm();

    await synchronizeFirstFrontend();

    expect(selectSelectedComponentUuid(store.getState())).toBe(
      SELECTED_COMPONENT,
    );
    expect(cachedForm()).toBeDefined();
    expect(currentUrl()).toBe(SELECTED_COMPONENT_URL);
  });
});
