import { useRef, useState } from 'react';
import { Provider } from 'react-redux';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { Theme } from '@radix-ui/themes';
import { configureStore } from '@reduxjs/toolkit';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';

import ColorFormPopover from '@/features/brandKit/components/ColorFormPopover';
import { brandKitApi } from '@/services/brandKit';
import { componentAndLayoutApi } from '@/services/componentAndLayout';

import type { Measurable } from '@radix-ui/rect';
import type { BrandKitColor } from '@/types/CodeComponent';

const color: BrandKitColor = {
  id: 'a',
  name: 'Red',
  cssVariable: '--color-red',
  weight: 0,
  value: {
    colorSpace: 'srgb',
    components: [1, 0, 0],
    alpha: null,
    hex: '#ff0000',
  },
};

// Flushes the pending update so the test can continue.
let releaseWrite: (status: number) => void = () => {};

const jsonResponse = (body: unknown, status: number) =>
  new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json' },
  });

beforeEach(() => {
  vi.stubGlobal(
    'fetch',
    async (input: RequestInfo | URL, init?: RequestInit) => {
      const request = new Request(input, init);
      const { url, method } = request;

      if (url.includes('session/token')) {
        return new Response('test-csrf-token', { status: 200 });
      }
      // On mount, this fetches folders and usage info, so provide a stub.
      if (method === 'GET' && url.includes('config/folder')) {
        return jsonResponse({}, 200);
      }
      if (method === 'GET' && url.includes('usage/color')) {
        return jsonResponse({}, 200);
      }
      // Keep the update request pending so this test can check the popover
      // state during save, then explicitly resolve or reject the request.
      if (method === 'PATCH' && url.includes('config/color')) {
        return new Promise<Response>((resolve) => {
          releaseWrite = (status) => {
            if (status >= 400) {
              resolve(jsonResponse({ errors: ['Rejected'] }, status));
              return;
            }
            resolve(jsonResponse({ ...color }, status));
          };
        });
      }
      throw new Error(`Unhandled request: ${method} ${url}`);
    },
  );
});

afterEach(() => {
  vi.unstubAllGlobals();
});

/**
 * Mirrors how `ColorRow` hosts this popover: mounted for the row lifetime,
 * with `open` controlled externally between opens.
 * This test confirms that flow works correctly across repeated opens.
 */
const Harness = ({ color: colorProp }: { color: BrandKitColor }) => {
  const [open, setOpen] = useState(true);
  const anchorRef = useRef<Measurable>({
    getBoundingClientRect: () => new DOMRect(),
  });

  return (
    <Theme>
      {/* Simulates the row-level Edit button that opens this popover, not the
          popover's own internal reopen path after a failed save. */}
      <button data-testid="reopen-edit-popover" onClick={() => setOpen(true)}>
        Edit
      </button>
      <ColorFormPopover
        operation="edit"
        color={colorProp}
        anchorRef={anchorRef}
        open={open}
        onOpenChange={setOpen}
      />
    </Theme>
  );
};

const setup = (initialColor: BrandKitColor = color) => {
  const store = configureStore({
    reducer: {
      [brandKitApi.reducerPath]: brandKitApi.reducer,
      [componentAndLayoutApi.reducerPath]: componentAndLayoutApi.reducer,
      configuration: () => ({ baseUrl: 'http://localhost/' }),
    },
    middleware: (getDefaultMiddleware) =>
      getDefaultMiddleware().concat(
        brandKitApi.middleware,
        componentAndLayoutApi.middleware,
      ),
  });

  return render(
    <Provider store={store}>
      <Harness color={initialColor} />
    </Provider>,
  );
};

describe('ColorFormPopover save failures', () => {
  it('still shows why the save failed after the optimistic close reopens the popover', async () => {
    setup();

    fireEvent.click(screen.getByTestId('canvas-color-save-button'));

    // The popover closes right away (the optimistic update already changed
    // the list), before the write finishes.
    await waitFor(() =>
      expect(
        screen.queryByTestId('canvas-color-form-popover'),
      ).not.toBeInTheDocument(),
    );

    releaseWrite(422);

    // If save fails, the popover reopens with the same values and shows the
    // error.
    expect(await screen.findByTestId('color-error-card')).toHaveTextContent(
      'Failed to update color',
    );
  });

  it('does not resurface a previous failure when the user cancels instead of retrying', async () => {
    setup();

    fireEvent.click(screen.getByTestId('canvas-color-save-button'));
    await waitFor(() =>
      expect(
        screen.queryByTestId('canvas-color-form-popover'),
      ).not.toBeInTheDocument(),
    );
    releaseWrite(422);
    expect(await screen.findByTestId('color-error-card')).toBeInTheDocument();

    // The user dismisses the reopened form instead of retrying the save.
    fireEvent.click(screen.getByTestId('canvas-color-cancel-button'));
    await waitFor(() =>
      expect(
        screen.queryByTestId('canvas-color-form-popover'),
      ).not.toBeInTheDocument(),
    );

    // Reopening for a fresh edit must not carry the old failure along.
    fireEvent.click(screen.getByTestId('reopen-edit-popover'));
    expect(
      await screen.findByTestId('canvas-color-form-popover'),
    ).toBeInTheDocument();
    expect(screen.queryByTestId('color-error-card')).not.toBeInTheDocument();
  });
});

describe('ColorFormPopover refetch resilience', () => {
  it('keeps in-progress edits when the color prop is replaced by a refetch', async () => {
    // Simulate an in-progress edit when the parent re-renders with a new color
    // object (e.g. after a background refetch invalidates the cache).
    const { rerender } = setup(color);

    const red = screen.getByLabelText('Red value');
    fireEvent.change(red, { target: { value: '0' } });
    expect(red).toHaveValue(0);

    // Parent re-renders with a new color object reference (same values) as if
    // a refetch just landed.
    rerender(
      <Provider
        store={configureStore({
          reducer: {
            [brandKitApi.reducerPath]: brandKitApi.reducer,
            [componentAndLayoutApi.reducerPath]: componentAndLayoutApi.reducer,
            configuration: () => ({ baseUrl: 'http://localhost/' }),
          },
          middleware: (getDefaultMiddleware) =>
            getDefaultMiddleware().concat(
              brandKitApi.middleware,
              componentAndLayoutApi.middleware,
            ),
        })}
      >
        <Harness color={{ ...color }} />
      </Provider>,
    );

    // The user's in-progress edit must survive the refetch.
    expect(screen.getByLabelText('Red value')).toHaveValue(0);
  });
});
