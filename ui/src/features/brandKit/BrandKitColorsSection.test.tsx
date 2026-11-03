import { Provider } from 'react-redux';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { Theme } from '@radix-ui/themes';
import { act, fireEvent, render, screen } from '@testing-library/react';

import { makeStore } from '@/app/store';
import BrandKitColorsSection from '@/features/brandKit/BrandKitColorsSection';
import { initialState as configurationInitialState } from '@/features/configuration/configurationSlice';

vi.mock('@assets/icons/folder.svg?react', () => ({
  default: (props: Record<string, unknown>) => <svg {...props} />,
}));

vi.mock('@/features/brandKit/hooks/useBrandKitColors', () => ({
  useBrandKitColors: () => ({
    colors: [],
    errorMessage: null,
    isLoading: false,
  }),
}));

const jsonResponse = (body: unknown) =>
  new Response(JSON.stringify(body), {
    status: 200,
    headers: { 'Content-Type': 'application/json' },
  });

beforeEach(() => {
  vi.stubGlobal(
    'ResizeObserver',
    class {
      observe() {}
      unobserve() {}
      disconnect() {}
    },
  );
  vi.stubGlobal(
    'fetch',
    async (input: RequestInfo | URL, init?: RequestInit) => {
      const { url, method } = new Request(input, init);
      if (method === 'GET' && url.includes('config/folder')) {
        return jsonResponse({
          pocket: { name: 'Color Pocket', type: 'color', weight: 0, items: [] },
        });
      }
      if (method === 'GET') {
        return jsonResponse({});
      }
      throw new Error(`Unhandled request: ${method} ${url}`);
    },
  );
});

afterEach(() => {
  vi.useRealTimers();
  vi.unstubAllGlobals();
});

const openFolderMenu = async () => {
  render(
    <Provider
      store={makeStore({
        configuration: {
          ...configurationInitialState,
          baseUrl: 'http://localhost/',
        },
      })}
    >
      <Theme>
        <BrandKitColorsSection />
      </Theme>
    </Provider>,
  );
  const trigger = (
    await screen.findByText('Color Pocket')
  ).closest<HTMLElement>('[data-canvas-folder-name]')!;
  const menuTrigger = trigger.querySelector<HTMLElement>(
    '[aria-label="Open contextual menu"]',
  )!;
  menuTrigger.focus();
  fireEvent.keyDown(menuTrigger, { key: 'Enter' });
  await screen.findByTestId('canvas-brand-kit-colors-folder-add-color-button');
  return menuTrigger;
};

describe('BrandKitColorsSection folder menu', () => {
  it('keeps focus in the add color popover after the folder menu closes', async () => {
    await openFolderMenu();

    // The menu returns focus from a timer after it closes. Hold timers so the
    // invalid alpha is entered before that timer runs, as a fast user would.
    vi.useFakeTimers();
    fireEvent.click(
      screen.getByTestId('canvas-brand-kit-colors-folder-add-color-button'),
    );
    fireEvent.change(screen.getByTestId('canvas-color-name-input'), {
      target: { value: 'New Blue' },
    });
    const alpha = screen.getByLabelText('Alpha value');
    act(() => alpha.focus());
    fireEvent.change(alpha, { target: { value: '-0.5' } });
    act(() => vi.runAllTimers());

    expect(alpha).toHaveFocus();
    expect(alpha).toHaveValue(-0.5);
    expect(screen.getByTestId('canvas-color-save-button')).toBeDisabled();
  });

  it('still returns focus to the folder menu trigger on dismissal', async () => {
    const menuTrigger = await openFolderMenu();

    fireEvent.keyDown(
      screen.getByTestId('canvas-brand-kit-colors-folder-add-color-button'),
      { key: 'Escape' },
    );

    await vi.waitFor(() => expect(menuTrigger).toHaveFocus());
  });
});
