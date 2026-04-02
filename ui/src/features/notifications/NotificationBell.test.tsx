import { Provider } from 'react-redux';
import { describe, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';

import { makeStore } from '@/app/store';
import { getCanvasSettings } from '@/utils/drupal-globals';

import NotificationBell from './NotificationBell';

vi.mock('@assets/icons/bell.svg?react', () => ({
  default: (props: any) => <svg data-testid="bell-icon" {...props} />,
}));

vi.mock('@/utils/drupal-globals', async () => {
  const actual = await vi.importActual('@/utils/drupal-globals');
  return {
    ...actual,
    getCanvasSettings: vi.fn(),
    getBaseUrl: vi.fn().mockReturnValue('/'),
    getDrupal: vi.fn().mockReturnValue({}),
    getDrupalSettings: vi.fn().mockReturnValue({ canvas: {} }),
  };
});

vi.mock('@/hooks/useDocumentVisibility', () => ({
  useDocumentVisibility: vi.fn().mockReturnValue(true),
}));

const mockGetCanvasSettings = vi.mocked(getCanvasSettings);

const renderWithStore = () => {
  const store = makeStore();
  return render(
    <Provider store={store}>
      <NotificationBell />
    </Provider>,
  );
};

describe('NotificationBell', () => {
  it('renders bell icon when devMode is true', () => {
    mockGetCanvasSettings.mockReturnValue({ devMode: true } as any);
    renderWithStore();
    expect(screen.getByLabelText('Notifications')).toBeInTheDocument();
  });

  it('does not render when devMode is false', () => {
    mockGetCanvasSettings.mockReturnValue({ devMode: false } as any);
    renderWithStore();
    expect(screen.queryByLabelText('Notifications')).not.toBeInTheDocument();
  });

  it('does not render when canvasSettings is undefined', () => {
    mockGetCanvasSettings.mockReturnValue(undefined as any);
    renderWithStore();
    expect(screen.queryByLabelText('Notifications')).not.toBeInTheDocument();
  });
});
