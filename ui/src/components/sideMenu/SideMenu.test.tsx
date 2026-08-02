import {
  afterAll,
  afterEach,
  beforeAll,
  describe,
  expect,
  it,
  vi,
} from 'vitest';
import { render, screen } from '@testing-library/react';
import AppWrapper from '@tests/vitest/components/AppWrapper';

import { makeStore } from '@/app/store';
import { getCanvasSettings } from '@/utils/drupal-globals';

import type { ComponentType } from 'react';

vi.mock('@/utils/permissions', () => ({
  hasPermission: () => true,
}));

vi.mock('@assets/icons/brand-kit.svg?react', () => ({
  default: () => <svg />,
}));

vi.mock('@assets/icons/extension-sm.svg?react', () => ({
  default: () => <svg />,
}));

vi.mock('@assets/icons/template.svg?react', () => ({
  default: () => <svg />,
}));

let SideMenu: ComponentType;
let originalDrupalSettings: typeof window.drupalSettings;

const renderSideMenu = () => {
  const store = makeStore();
  return render(
    <AppWrapper store={store} location="/" path="*">
      <SideMenu />
    </AppWrapper>,
  );
};

describe('SideMenu', () => {
  beforeAll(async () => {
    originalDrupalSettings = window.drupalSettings;
    window.drupalSettings = {
      canvas: {
        extensionsAvailable: false,
      },
    } as typeof window.drupalSettings;
    ({ SideMenu } = await import('./SideMenu'));
  });

  afterAll(() => {
    window.drupalSettings = originalDrupalSettings;
  });

  afterEach(() => {
    delete getCanvasSettings().headless;
  });

  it('shows the Code option without a configured headless frontend', () => {
    renderSideMenu();

    expect(screen.getByRole('button', { name: 'Code' })).toBeInTheDocument();
  });

  it('hides the Code option when a headless frontend is configured', () => {
    getCanvasSettings().headless = {
      frontendUrl: 'https://frontend.example',
      frontends: ['https://frontend.example'],
      frontendOrigin: 'https://frontend.example',
      draftUrl: 'https://frontend.example/api/draft',
      assertionUrl: '/canvas-headless/assertion',
    };

    renderSideMenu();

    expect(
      screen.queryByRole('button', { name: 'Code' }),
    ).not.toBeInTheDocument();
  });
  it('does not offer comments, whose panel is on the other side', () => {
    // Every other item in this rail opens the panel beside it. Comments lives
    // in the contextual panel on the right, so a button here would send the
    // user across the screen; it is reached from that panel and from the
    // comment tool in the canvas toolbar instead.
    renderSideMenu();

    expect(
      screen.queryByRole('button', { name: 'Comments' }),
    ).not.toBeInTheDocument();
  });
});
