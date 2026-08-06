import { describe, expect, it } from 'vitest';

import { isPreviewPath } from './topbarPreviewMode';

describe('isPreviewPath', () => {
  it('treats standard and version preview routes as preview mode', () => {
    expect(isPreviewPath('/canvas/preview/canvas_page/1/full')).toBe(true);
    expect(isPreviewPath('/canvas/version-preview/canvas_page/1/full')).toBe(
      true,
    );
    expect(isPreviewPath('/canvas/review/canvas_page/1')).toBe(false);
    expect(isPreviewPath('/canvas/editor/canvas_page/1')).toBe(false);
  });
});
