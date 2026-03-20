import { describe, expect, it } from 'vitest';

import { isSupportedPreviewModulePath, toViteFsUrl } from './preview-runtime';

describe('preview-runtime', () => {
  it('creates Vite fs URLs from absolute paths', () => {
    expect(toViteFsUrl('/Users/example/component.tsx')).toBe(
      '/@fs/Users/example/component.tsx',
    );
    expect(toViteFsUrl('C:\\workspace\\component.tsx')).toBe(
      '/@fs/C:/workspace/component.tsx',
    );
  });

  it('checks supported preview module extensions', () => {
    expect(isSupportedPreviewModulePath('/tmp/a.tsx')).toBe(true);
    expect(isSupportedPreviewModulePath('/tmp/a.jpg')).toBe(false);
  });
});
