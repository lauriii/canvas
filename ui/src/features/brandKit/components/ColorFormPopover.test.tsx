import { describe, expect, it, vi } from 'vitest';
import { Provider as TooltipProvider } from '@radix-ui/react-tooltip';
import { Theme } from '@radix-ui/themes';
import { fireEvent, render, screen } from '@testing-library/react';

import ColorFormPopover from '@/features/brandKit/components/ColorFormPopover';

import type { BrandKitColor } from '@/types/CodeComponent';

vi.mock('@/services/brandKit', () => {
  const mutation = () => [vi.fn(), { reset: vi.fn() }];
  return {
    useCreateColorMutation: mutation,
    useUpdateColorMutation: mutation,
    useGetColorUsageDetailsQuery: () => ({ data: undefined }),
  };
});
vi.mock('@/services/componentAndLayout', () => ({
  useGetFoldersQuery: () => ({ data: undefined }),
  useUpdateFolderMutation: () => [vi.fn()],
}));

const makeColor = (): BrandKitColor => ({
  id: 'brand-blue',
  name: 'Brand Blue',
  cssVariable: '--brand-blue',
  weight: 0,
  value: {
    colorSpace: 'srgb',
    components: [0, 0.2667, 0.8],
    alpha: null,
    hex: '#0044cc',
  },
});

const renderPopover = (color: BrandKitColor) => (
  <Theme>
    <TooltipProvider delayDuration={0}>
      <ColorFormPopover
        operation="edit"
        color={color}
        anchorRef={{ current: { getBoundingClientRect: () => new DOMRect() } }}
        open
        onOpenChange={vi.fn()}
      />
    </TooltipProvider>
  </Theme>
);

describe('ColorFormPopover', () => {
  it('keeps in-progress edits when the color prop is replaced by a refetch', () => {
    const { rerender } = render(renderPopover(makeColor()));
    const red = screen.getByLabelText('Red value');
    fireEvent.change(red, { target: { value: '255' } });
    expect(red).toHaveValue(255);

    rerender(renderPopover(makeColor()));

    expect(screen.getByLabelText('Red value')).toHaveValue(255);
    expect(screen.getByTestId('color-preview-hex')).toHaveTextContent(
      '#FF44CC',
    );
  });
});
