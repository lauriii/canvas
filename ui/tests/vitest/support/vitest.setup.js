import { vi } from 'vitest';

vi.stubGlobal('URL', {
  createObjectURL: vi.fn().mockImplementation((blob) => {
    return `mock-object-url/${blob.name}`;
  }),
});

vi.mock('@/utils/drupal-globals', () => ({
  getDrupal: () => ({
    url: (path) => `http://mock-drupal-url/${path}`,
  }),
  getDrupalSettings: () => ({
    path: {
      baseUrl: '/',
    },
    xb: {},
  }),
  getXbSettings: () => ({}),
  getBasePath: () => '/',
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
  transformCss: vi.fn().mockReturnValue(Promise.resolve('')),
}));
