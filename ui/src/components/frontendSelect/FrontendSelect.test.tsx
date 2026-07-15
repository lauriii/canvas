import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { Theme } from '@radix-ui/themes';
import { act, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import { useCanvasHeadlessSettings } from '@/hooks/useCanvasHeadlessSettings';
import { getCanvasSettings } from '@/utils/drupal-globals';

import FrontendSelect from './FrontendSelect';

import styles from './FrontendSelect.module.css';

const { mockSyncComponents, syncState } = vi.hoisted(() => ({
  mockSyncComponents: vi.fn(() => ({
    unwrap: () => Promise.resolve({}),
  })),
  syncState: { isLoading: false },
}));

vi.mock('@/services/headlessComponentSync', () => ({
  useSyncComponentsMutation: () => [mockSyncComponents, syncState],
}));

const FIRST_FRONTEND = 'https://first.example';
const SECOND_FRONTEND = 'https://second.example/app';

const FrontendSelectHarness = () => {
  const settings = useCanvasHeadlessSettings();
  return settings ? (
    <Theme>
      <FrontendSelect settings={settings} />
    </Theme>
  ) : null;
};

describe('FrontendSelect', () => {
  beforeEach(() => {
    getCanvasSettings().headlessEnabled = true;
  });

  afterEach(() => {
    delete getCanvasSettings().headless;
    delete getCanvasSettings().headlessEnabled;
    window.localStorage.clear();
    mockSyncComponents.mockClear();
    syncState.isLoading = false;
    vi.useRealTimers();
  });

  it('disables switching while component synchronization is running', () => {
    syncState.isLoading = true;
    getCanvasSettings().headless = {
      frontendUrl: FIRST_FRONTEND,
      frontends: [FIRST_FRONTEND, SECOND_FRONTEND],
      frontendOrigin: FIRST_FRONTEND,
      draftUrl: `${FIRST_FRONTEND}/api/draft`,
      assertionUrl: '/canvas-headless/assertion',
    };

    render(<FrontendSelectHarness />);

    expect(screen.getByTestId('frontend-select-trigger')).toBeDisabled();
  });

  it('shows the sync indicator for at least two seconds, then fades it out', () => {
    vi.useFakeTimers();
    syncState.isLoading = true;
    getCanvasSettings().headless = {
      frontendUrl: FIRST_FRONTEND,
      frontends: [FIRST_FRONTEND, SECOND_FRONTEND],
      frontendOrigin: FIRST_FRONTEND,
      draftUrl: `${FIRST_FRONTEND}/api/draft`,
      assertionUrl: '/canvas-headless/assertion',
    };
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
    getCanvasSettings().headless = {
      frontendUrl: FIRST_FRONTEND,
      frontends: [FIRST_FRONTEND, SECOND_FRONTEND],
      frontendOrigin: FIRST_FRONTEND,
      draftUrl: `${FIRST_FRONTEND}/api/draft`,
      assertionUrl: '/canvas-headless/assertion',
    };
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
});
