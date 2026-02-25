import { describe, expect, it } from 'vitest';

import { resolvePreviewComponent } from './preview-component-resolver';

describe('resolvePreviewComponent', () => {
  it('uses default function export when available', () => {
    const Component = () => null;
    const result = resolvePreviewComponent({ default: Component });
    expect(result.component).toBe(Component);
    expect(result.reason).toBeNull();
  });

  it('uses default React object export when available', () => {
    const MemoComponent = { $$typeof: Symbol.for('react.memo') };
    const result = resolvePreviewComponent({ default: MemoComponent });
    expect(result.component).toBe(MemoComponent);
    expect(result.reason).toBeNull();
  });

  it('returns failure reason when default export is not renderable', () => {
    const result = resolvePreviewComponent({
      default: { id: 'meta' },
      Component: () => null,
      HeroCard: () => null,
    });
    expect(result.component).toBeNull();
    expect(result.reason).toContain('No renderable default export found');
  });
});
