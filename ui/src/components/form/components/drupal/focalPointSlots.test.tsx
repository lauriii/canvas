import { describe, expect, it } from 'vitest';
import { act, render } from '@testing-library/react';

import {
  imagePropFromInputName,
  registerFocalPointSlot,
  useFocalPointSlot,
} from './focalPointSlots';

const Claimant = ({ id, slotKey }: { id: string; slotKey: string }) => {
  const slot = useFocalPointSlot(slotKey);
  return <span data-testid={id}>{slot ? 'has slot' : 'no slot'}</span>;
};

describe('focalPointSlots', () => {
  it('gives the slot to the most recent claimant until it unmounts', () => {
    const element = document.createElement('div');
    act(() => registerFocalPointSlot('image:0', element));

    const first = render(<Claimant id="first" slotKey="image:0" />);
    expect(first.getByTestId('first').textContent).toBe('has slot');

    // A form rebuilt by AJAX mounts a new widget without unmounting the old.
    const second = render(<Claimant id="second" slotKey="image:0" />);
    expect(first.getByTestId('first').textContent).toBe('no slot');
    expect(second.getByTestId('second').textContent).toBe('has slot');

    second.unmount();
    expect(first.getByTestId('first').textContent).toBe('has slot');

    act(() => registerFocalPointSlot('image:0', null));
    expect(first.getByTestId('first').textContent).toBe('no slot');
    first.unmount();
  });

  it('reads the image prop from a media library input name', () => {
    expect(
      imagePropFromInputName(
        'canvas_component_props[uuid][gallery][selection][1][target_id]',
      ),
    ).toBe('gallery');
    expect(imagePropFromInputName('something[else]')).toBeNull();
  });
});
