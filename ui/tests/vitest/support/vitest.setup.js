import { vi } from 'vitest';

const mockDrupalSettings = {
  path: {
    baseUrl: '/',
  },
  xb: {},
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
  getXbSettings: () => mockDrupalSettings.xb,
  getBasePath: () => mockDrupalSettings.path.baseUrl,
  setXbDrupalSetting: (property, value) => {
    if (mockDrupalSettings?.xb?.[property]) {
      mockDrupalSettings.xb[property] = {
        ...mockDrupalSettings.xb[property],
        ...value,
      };
    }
  },
  getXbModuleBaseUrl: () => '/modules/contrib/experience_builder',
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
