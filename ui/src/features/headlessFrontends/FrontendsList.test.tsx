import { beforeEach, describe, expect, it, vi } from 'vitest';
import { Provider as TooltipProvider } from '@radix-ui/react-tooltip';
import { Theme } from '@radix-ui/themes';
import { configureStore } from '@reduxjs/toolkit';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import {
  queryErrorSlice,
  selectLatestError,
} from '@/features/error-handling/queryErrorSlice';
import { rtkQueryErrorHandler } from '@/utils/rtkQuery-error';

import FrontendsList from './FrontendsList';
import HeadlessFrontendsPage from './HeadlessFrontendsPage';

import type * as FrontendsService from '@/services/headlessFrontends';

const pageMocks = vi.hoisted(() => ({ query: vi.fn(), save: vi.fn() }));

vi.mock('@/services/headlessFrontends', () => ({
  useGetFrontendsQuery: pageMocks.query,
  useSetFrontendsMutation: () => [pageMocks.save, { isLoading: false }],
}));
vi.mock('@/utils/drupal-globals', () => ({
  setCanvasHeadlessFrontends: vi.fn(),
}));

vi.mock('./AddFrontendDialog', () => ({
  default: ({
    open,
    onAdd,
  }: {
    open: boolean;
    onAdd: (url: string) => Promise<void>;
  }) =>
    open ? (
      <button onClick={() => void onAdd('https://new.example').catch(() => {})}>
        Save new frontend
      </button>
    ) : null,
}));

vi.mock('./SetupGuide', () => ({ default: () => null }));

vi.mock('./checkFrontendConnection', () => ({
  checkFrontendConnection: vi.fn(() => new Promise(() => {})),
}));

const requestMocks = vi.hoisted(() => ({ baseQuery: vi.fn() }));
vi.mock('@/services/baseQuery', () => ({ baseQuery: requestMocks.baseQuery }));

describe('FrontendsList', () => {
  it('opens the setup guide from the setup-needed tooltip', async () => {
    const user = userEvent.setup();
    const onOpenSetupGuide = vi.fn();

    render(
      <Theme>
        <TooltipProvider delayDuration={0}>
          <FrontendsList
            frontends={[
              {
                id: 'https://frontend.example',
                url: 'https://frontend.example',
                status: 'setup-needed',
              },
            ]}
            onAdd={vi.fn()}
            onReorder={vi.fn()}
            onRemove={vi.fn()}
            onStatusChange={vi.fn()}
            onOpenSetupGuide={onOpenSetupGuide}
            disabled={false}
          />
        </TooltipProvider>
      </Theme>,
    );

    await user.hover(screen.getByText('Setup needed'));
    await user.click(
      await screen.findByRole('link', {
        name: 'setup guide',
      }),
    );

    expect(onOpenSetupGuide).toHaveBeenCalledOnce();
  });
});

describe('frontend list saves', () => {
  beforeEach(() => {
    pageMocks.query.mockReturnValue({
      data: { frontends: [], frontendUrlsHash: 'loaded-hash' },
      isLoading: false,
    });
  });

  it('sends only URLs and the loaded URL-list hash', async () => {
    const existing = { url: 'https://first.example', components: ['js.hero'] };
    pageMocks.query.mockReturnValue({
      data: { frontends: [existing], frontendUrlsHash: 'loaded-hash' },
      isLoading: false,
    });
    pageMocks.save.mockReturnValue({
      unwrap: () =>
        Promise.resolve({
          frontends: [existing, { url: 'https://new.example', components: [] }],
          frontendUrlsHash: 'saved-hash',
        }),
    });
    render(
      <Theme>
        <HeadlessFrontendsPage />
      </Theme>,
    );
    const user = userEvent.setup();
    await user.click(screen.getByRole('button', { name: 'Add frontend' }));
    await user.click(screen.getByRole('button', { name: 'Save new frontend' }));
    expect(pageMocks.save).toHaveBeenCalledWith({
      frontendUrlsHash: 'loaded-hash',
      frontends: [{ url: existing.url }, { url: 'https://new.example' }],
    });
  });

  it('blocks stale edits with a refresh action rather than retrying', async () => {
    pageMocks.save.mockReturnValue({
      unwrap: () => Promise.reject({ status: 409 }),
    });
    render(
      <Theme>
        <HeadlessFrontendsPage />
      </Theme>,
    );
    const user = userEvent.setup();
    await user.click(screen.getByRole('button', { name: 'Add frontend' }));
    await user.click(screen.getByRole('button', { name: 'Save new frontend' }));
    expect(
      await screen.findByRole('button', { name: 'Refresh' }),
    ).toBeInTheDocument();
    expect(
      screen.getByText(/your latest change was not saved/i),
    ).toBeInTheDocument();
    expect(
      screen.queryByRole('button', { name: 'Add frontend' }),
    ).not.toBeInTheDocument();
    expect(pageMocks.save).toHaveBeenCalledOnce();
  });

  it('rolls back a conflict without refetching, retrying, or setting a global error', async () => {
    const { headlessFrontendsApi } = await vi.importActual<
      typeof FrontendsService
    >('@/services/headlessFrontends');
    const initial = {
      frontends: [{ url: 'https://first.example', components: ['js.hero'] }],
      frontendUrlsHash: 'loaded-hash',
    };
    requestMocks.baseQuery.mockResolvedValueOnce({ data: initial });
    const store = configureStore({
      reducer: {
        [headlessFrontendsApi.reducerPath]: headlessFrontendsApi.reducer,
        [queryErrorSlice.reducerPath]: queryErrorSlice.reducer,
      },
      middleware: (getDefaultMiddleware) =>
        getDefaultMiddleware().concat(
          headlessFrontendsApi.middleware,
          rtkQueryErrorHandler,
        ),
    });
    const read = store.dispatch(
      headlessFrontendsApi.endpoints.getFrontends.initiate(),
    );
    await read;
    let rejectSave: (value: {
      error: { status: number; data: { error: string } };
    }) => void = () => {};
    requestMocks.baseQuery.mockImplementationOnce(
      () =>
        new Promise((resolve) => {
          rejectSave = resolve;
        }),
    );
    const desired = {
      frontends: [
        { url: 'https://new.example' },
        { url: 'https://first.example' },
      ],
      frontendUrlsHash: initial.frontendUrlsHash,
    };
    const write = store.dispatch(
      headlessFrontendsApi.endpoints.setFrontends.initiate(desired),
    );
    const select = () =>
      headlessFrontendsApi.endpoints.getFrontends.select()(store.getState())
        .data;
    expect(select()).toEqual({
      frontendUrlsHash: initial.frontendUrlsHash,
      frontends: [
        { url: 'https://new.example', components: [] },
        ...initial.frontends,
      ],
    });
    rejectSave({
      error: { status: 409, data: { error: 'The frontend list has changed.' } },
    });
    await write;
    expect(requestMocks.baseQuery).toHaveBeenLastCalledWith(
      expect.objectContaining({ method: 'PATCH', body: desired }),
      expect.anything(),
      undefined,
    );
    expect(select()).toEqual(initial);
    expect(selectLatestError(store.getState())).toBeUndefined();
    expect(requestMocks.baseQuery).toHaveBeenCalledTimes(2);
    read.unsubscribe();
    store.dispatch(headlessFrontendsApi.util.resetApiState());
  });

  it('uses the successful save response as the cached baseline without refetching', async () => {
    const { headlessFrontendsApi } = await vi.importActual<
      typeof FrontendsService
    >('@/services/headlessFrontends');
    const initial = { frontends: [], frontendUrlsHash: 'loaded-hash' };
    requestMocks.baseQuery.mockResolvedValueOnce({ data: initial });
    const store = configureStore({
      reducer: {
        [headlessFrontendsApi.reducerPath]: headlessFrontendsApi.reducer,
      },
      middleware: (getDefaultMiddleware) =>
        getDefaultMiddleware().concat(headlessFrontendsApi.middleware),
    });
    const read = store.dispatch(
      headlessFrontendsApi.endpoints.getFrontends.initiate(),
    );
    await read;
    const saved = {
      frontends: [{ url: 'https://new.example', components: ['js.fresh'] }],
      frontendUrlsHash: 'saved-hash',
    };
    requestMocks.baseQuery.mockResolvedValueOnce({ data: saved });
    await store.dispatch(
      headlessFrontendsApi.endpoints.setFrontends.initiate({
        frontends: [{ url: 'https://new.example' }],
        frontendUrlsHash: initial.frontendUrlsHash,
      }),
    );
    expect(
      headlessFrontendsApi.endpoints.getFrontends.select()(store.getState())
        .data,
    ).toEqual(saved);
    expect(requestMocks.baseQuery).toHaveBeenCalledTimes(2);
    expect(requestMocks.baseQuery).toHaveBeenNthCalledWith(
      1,
      '/canvas/api/v0/headless/frontends',
      expect.anything(),
      undefined,
    );
    expect(requestMocks.baseQuery).toHaveBeenNthCalledWith(
      2,
      expect.objectContaining({ method: 'PATCH' }),
      expect.anything(),
      undefined,
    );
    read.unsubscribe();
    store.dispatch(headlessFrontendsApi.util.resetApiState());
  });
});
