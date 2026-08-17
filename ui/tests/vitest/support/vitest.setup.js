import { vi } from 'vitest';

import '@testing-library/jest-dom/vitest';

import { installDrupalTranslationStub } from '@tests/support/drupal-translation-stub';

// Components call Drupal.t() directly, which core/misc/drupal.js provides in
// the browser but nothing provides here.
installDrupalTranslationStub();

const mockDrupalSettings = {
  path: {
    baseUrl: '/',
  },
  canvas: {},
};

const RealURL = globalThis.URL;

vi.stubGlobal(
  'URL',
  class MockURL extends RealURL {
    static createObjectURL = vi.fn().mockImplementation((blob) => {
      return `mock-object-url/${blob.name}`;
    });
  },
);

vi.mock('@/utils/drupal-globals', () => ({
  getDrupal: () => ({
    url: (path) => `http://mock-drupal-url/${path}`,
  }),
  getDrupalSettings: () => mockDrupalSettings,
  getCanvasSettings: () => mockDrupalSettings.canvas,
  getCanvasHeadlessSettings: () => mockDrupalSettings.canvas.headless,
  getBasePath: () => mockDrupalSettings.path.baseUrl,
  CANVAS_HEADLESS_SETTINGS_CHANGE: 'canvas:headless-settings-change',
  CANVAS_HEADLESS_FRONTEND_STORAGE_KEY: 'canvas-headless-active-frontend',
  setCanvasHeadlessFrontend: (frontendUrl, configuredFrontends) => {
    if (!frontendUrl) {
      delete mockDrupalSettings.canvas.headless;
      window.localStorage.removeItem('canvas-headless-active-frontend');
      window.dispatchEvent(new Event('canvas:headless-settings-change'));
      return;
    }
    const frontend = new URL(frontendUrl);
    const baseUrl = `${frontend.origin}${frontend.pathname === '/' ? '' : frontend.pathname}`;
    mockDrupalSettings.canvas.headless = {
      frontendUrl: baseUrl,
      frontends: configuredFrontends ??
        mockDrupalSettings.canvas.headless?.frontends ?? [baseUrl],
      frontendOrigin: frontend.origin,
      draftUrl: `${baseUrl}/api/draft`,
      assertionUrl: `${mockDrupalSettings.path.baseUrl}canvas-headless/assertion`,
    };
    window.localStorage.setItem('canvas-headless-active-frontend', baseUrl);
    window.dispatchEvent(new Event('canvas:headless-settings-change'));
  },
  setCanvasHeadlessFrontends: (configuredFrontends) => {
    const normalizedFrontends = configuredFrontends.map((frontendUrl) => {
      const frontend = new URL(frontendUrl);
      return `${frontend.origin}${frontend.pathname === '/' ? '' : frontend.pathname}`;
    });
    const activeFrontend = mockDrupalSettings.canvas.headless?.frontendUrl;
    const nextActiveFrontend =
      activeFrontend && normalizedFrontends.includes(activeFrontend)
        ? activeFrontend
        : normalizedFrontends[0];
    if (!nextActiveFrontend) {
      delete mockDrupalSettings.canvas.headless;
      window.localStorage.removeItem('canvas-headless-active-frontend');
      window.dispatchEvent(new Event('canvas:headless-settings-change'));
      return;
    }
    const frontend = new URL(nextActiveFrontend);
    mockDrupalSettings.canvas.headless = {
      frontendUrl: nextActiveFrontend,
      frontends: normalizedFrontends,
      frontendOrigin: frontend.origin,
      draftUrl: `${nextActiveFrontend}/api/draft`,
      assertionUrl: `${mockDrupalSettings.path.baseUrl}canvas-headless/assertion`,
    };
    window.localStorage.setItem(
      'canvas-headless-active-frontend',
      nextActiveFrontend,
    );
    window.dispatchEvent(new Event('canvas:headless-settings-change'));
  },
  restoreCanvasHeadlessFrontend: () => {
    const settings = mockDrupalSettings.canvas.headless;
    const storedFrontend = window.localStorage.getItem(
      'canvas-headless-active-frontend',
    );
    if (!settings || !storedFrontend) return;
    if (!settings.frontends.includes(storedFrontend)) {
      window.localStorage.removeItem('canvas-headless-active-frontend');
      return;
    }
    if (settings.frontendUrl !== storedFrontend) {
      const frontend = new URL(storedFrontend);
      settings.frontendUrl = storedFrontend;
      settings.frontendOrigin = frontend.origin;
      settings.draftUrl = `${storedFrontend}/api/draft`;
    }
  },
  setCanvasDrupalSetting: (property, value) => {
    if (mockDrupalSettings?.canvas?.[property]) {
      mockDrupalSettings.canvas[property] = {
        ...mockDrupalSettings.canvas[property],
        ...value,
      };
    }
  },
  getCanvasModuleBaseUrl: () => '/modules/contrib/canvas',
}));

vi.mock('@swc/wasm-web', () => ({
  default: vi.fn().mockReturnValue(Promise.resolve()),
  transformSync: vi.fn(() => ({
    code: '',
  })),
}));

vi.mock('tailwindcss-in-browser', () => ({
  default: vi.fn().mockReturnValue(Promise.resolve('')),
  extractClassNameCandidates: vi.fn().mockReturnValue([]),
  compileCss: vi.fn().mockImplementation(() => Promise.resolve('')),
  compilePartialCss: vi.fn().mockImplementation(() => Promise.resolve('')),
  transformCss: vi.fn().mockReturnValue(Promise.resolve('')),
}));

vi.stubGlobal(
  'ResizeObserver',
  class MockResizeObserver {
    observe = vi.fn();

    unobserve = vi.fn();

    disconnect = vi.fn();
  },
);

class MockPointerEvent extends Event {
  constructor(type, props) {
    super(type, props);
    this.button = props.button || 0;
    this.ctrlKey = props.ctrlKey || false;
    this.pointerType = props.pointerType || 'mouse';
  }
}
window.PointerEvent = MockPointerEvent;
window.HTMLElement.prototype.scrollIntoView = vi.fn();
window.HTMLElement.prototype.releasePointerCapture = vi.fn();
window.HTMLElement.prototype.hasPointerCapture = vi.fn();

/**
 * Mock getBoundingClientRect() for @uiw/react-codemirror
 * https://github.com/jsdom/jsdom/issues/3729
 */

function getBoundingClientRect() {
  const rec = {
    x: 0,
    y: 0,
    bottom: 0,
    height: 0,
    left: 0,
    right: 0,
    top: 0,
    width: 0,
  };
  return { ...rec, toJSON: () => rec };
}

class FakeDOMRectList extends Array {
  item(index) {
    return this[index];
  }
}

document.elementFromPoint = () => null;
window.HTMLElement.prototype.getBoundingClientRect = getBoundingClientRect;
window.HTMLElement.prototype.getClientRects = () => new FakeDOMRectList();
window.Range.prototype.getBoundingClientRect = getBoundingClientRect;
window.Range.prototype.getClientRects = () => new FakeDOMRectList();
