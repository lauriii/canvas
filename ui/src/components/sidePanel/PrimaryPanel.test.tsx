import { describe, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import AppWrapper from '@tests/vitest/components/AppWrapper';

import { makeStore } from '@/app/store';
import PrimaryPanel from '@/components/sidePanel/PrimaryPanel';

const hasPermissionMock = vi.fn<(permission: string) => boolean>(() => true);

vi.mock('@/hooks/useHidePanelClasses', () => ({
  default: () => [],
}));

vi.mock('@/utils/permissions', () => ({
  hasPermission: (...args: Parameters<typeof hasPermissionMock>) =>
    hasPermissionMock(...args),
}));

vi.mock('@/features/brandKit/BrandKitPanel', () => ({
  default: () => <div>Brand kit panel content</div>,
}));

vi.mock('@/components/extensions/ExtensionsList', () => ({
  default: () => <div>Extensions list</div>,
}));

vi.mock('@/components/aiExtension/AiWizard', () => ({
  default: () => <div>AI wizard</div>,
}));

vi.mock('@/utils/drupal-globals', () => ({
  getDrupal: () => ({}),
  getBaseUrl: () => '/',
  getCanvasSettings: () => ({ devMode: true }),
  getCanvasPermissions: () => ({}),
  getCanvasModuleBaseUrl: () => '/modules/contrib/canvas',
  getDrupalSettings: () => ({}),
  setCanvasDrupalSetting: () => undefined,
}));

describe('PrimaryPanel', () => {
  it('renders the Brand kit panel when active', () => {
    hasPermissionMock.mockReturnValue(true);
    const store = makeStore({
      primaryPanel: {
        activePanel: 'brandKit',
        isHidden: false,
        aiPanelOpen: false,
        manageLibraryTab: null,
      },
    });

    render(
      <AppWrapper store={store} location="/" path="/">
        <PrimaryPanel />
      </AppWrapper>,
    );

    expect(
      screen.getByRole('heading', { name: 'Brand kit' }),
    ).toBeInTheDocument();
    expect(screen.getByText('Brand kit panel content')).toBeInTheDocument();
  });

  it('does not render the Brand kit panel without permission', () => {
    hasPermissionMock.mockImplementation((permission: string) => {
      return permission !== 'brandKit';
    });
    const store = makeStore({
      primaryPanel: {
        activePanel: 'brandKit',
        isHidden: false,
        aiPanelOpen: false,
        manageLibraryTab: null,
      },
    });

    render(
      <AppWrapper store={store} location="/" path="/">
        <PrimaryPanel />
      </AppWrapper>,
    );

    expect(
      screen.queryByText('Brand kit panel content'),
    ).not.toBeInTheDocument();
  });
});
