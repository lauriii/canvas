import { useEffect } from 'react';
import { Provider } from 'react-redux';
import { describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen } from '@testing-library/react';

import { makeStore } from '@/app/store';
import { FormProvider } from '@/components/form/contexts/FormContext';
import { selectFormValues } from '@/features/form/formStateSlice';

import FocalPointPicker, { useFocalPointContext } from './FocalPointPicker';

import type { FieldContextValue } from '@/components/form/contexts/FieldContext';

const name = (axis: string) =>
  `canvas_component_props[uuid][focal][0][${axis}]`;

// Stands in for DrupalFocalPointCoordinate, which needs the full form
// pipeline: registers a value and a FieldContext with the picker.
const Coordinate = ({
  axis,
  value,
  fieldContext,
}: {
  axis: 'x' | 'y';
  value: string;
  fieldContext: FieldContextValue;
}) => {
  const focalPoint = useFocalPointContext();
  useEffect(() => {
    focalPoint?.register(axis, {
      name: name(axis),
      value,
      element: null,
      fieldContext,
    });
  }, [focalPoint, axis, value, fieldContext]);
  return null;
};

// jsdom's PointerEvent has no coordinates, so dispatch mouse events under the
// pointer event names React listens to.
const pointer = (
  element: HTMLElement,
  type: 'pointerdown' | 'pointermove' | 'pointerup',
  clientX: number,
  clientY: number,
) =>
  fireEvent(
    element,
    new MouseEvent(type, { bubbles: true, cancelable: true, clientX, clientY }),
  );

const fieldContext = (axis: string): FieldContextValue => ({
  fieldName: name(axis),
  triggerChange: vi.fn(),
  triggerBlur: vi.fn(),
});

const renderPicker = (x: string, y: string) => {
  const store = makeStore({});
  const contexts = { x: fieldContext('x'), y: fieldContext('y') };
  const onPointChange = vi.fn();
  render(
    <Provider store={store}>
      <FormProvider formId="component_instance_form">
        <FocalPointPicker onPointChange={onPointChange}>
          <Coordinate axis="x" value={x} fieldContext={contexts.x} />
          <Coordinate axis="y" value={y} fieldContext={contexts.y} />
        </FocalPointPicker>
      </FormProvider>
    </Provider>,
  );
  const frame = document.querySelector(
    '[data-canvas-focal-point-frame]',
  ) as HTMLElement;
  frame.getBoundingClientRect = () =>
    ({ left: 0, top: 0, width: 200, height: 100 }) as DOMRect;
  return { store, contexts, onPointChange, frame };
};

describe('FocalPointPicker', () => {
  it('shows the registered point', () => {
    const { onPointChange } = renderPicker('25', '75');
    expect(onPointChange).toHaveBeenLastCalledWith(
      { x: 25, y: 75 },
      expect.anything(),
    );
    expect(screen.getByText('No image selected')).toBeInTheDocument();
  });

  it('does not show an out of range point', () => {
    const { onPointChange } = renderPicker('140', '75');
    expect(onPointChange).toHaveBeenLastCalledWith(null, expect.anything());
  });

  it('commits a drag as one change that carries both coordinates', () => {
    const { store, contexts, frame } = renderPicker('25', '75');
    pointer(frame, 'pointerdown', 50, 75);
    pointer(frame, 'pointermove', 100, 50);
    pointer(frame, 'pointerup', 160, 30);

    // y is written to the form state without a change of its own, so that
    // x's single change handler sends both values.
    expect(
      selectFormValues(store.getState(), 'component_instance_form')[name('y')],
    ).toBe(30);
    expect(contexts.y.triggerChange).not.toHaveBeenCalled();
    expect(contexts.x.triggerChange).toHaveBeenCalledTimes(1);
    expect(contexts.x.triggerChange).toHaveBeenCalledWith(80);
  });

  it('ignores pointer moves without a pointer down', () => {
    const { contexts, frame } = renderPicker('25', '75');
    pointer(frame, 'pointermove', 100, 50);
    pointer(frame, 'pointerup', 100, 50);
    expect(contexts.x.triggerChange).not.toHaveBeenCalled();
  });
});
