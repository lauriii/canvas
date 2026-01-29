import { vi } from 'vitest';

import '@testing-library/jest-dom/vitest';

const mockDrupalSettings = {
  path: {
    baseUrl: '/',
  },
  canvas: {},
};

vi.stubGlobal('URL', {
  createObjectURL: vi.fn().mockImplementation((blob) => {
    return `mock-object-url/${blob.name}`;
  }),
});

vi.mock('@/utils/drupal-globals', () => ({
  getDrupal: () => ({
    url: (path) => `http://mock-drupal-url/${path}`,
  }),
  getDrupalSettings: () => mockDrupalSettings,
  getCanvasSettings: () => mockDrupalSettings.canvas,
  getBasePath: () => mockDrupalSettings.path.baseUrl,
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
  vi.fn(() => ({
    observe: vi.fn(),
    unobserve: vi.fn(),
    disconnect: vi.fn(),
  })),
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
