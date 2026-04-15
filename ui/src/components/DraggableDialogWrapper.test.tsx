import { describe, expect, it, vi } from 'vitest';
import { fireEvent, render } from '@testing-library/react';

import DraggableDialogWrapper from '@/components/DraggableDialogWrapper';

import styles from '@/components/DraggableDialogWrapper.module.css';

describe('DraggableDialogWrapper', () => {
  const dispatchMouseMove = (
    target: Document,
    options: { movementX: number; movementY: number; buttons: number },
  ) => {
    const event = new MouseEvent('mousemove', {
      bubbles: true,
      cancelable: true,
      buttons: options.buttons,
    });

    Object.defineProperties(event, {
      movementX: { value: options.movementX },
      movementY: { value: options.movementY },
      buttons: { value: options.buttons },
    });

    fireEvent(target, event);
  };

  it('does not start dragging on right click', () => {
    Object.defineProperty(window, 'visualViewport', {
      configurable: true,
      value: {
        width: 1000,
        height: 800,
      },
    });

    render(
      <DraggableDialogWrapper
        onOpenChange={vi.fn()}
        open={true}
        description={null}
      >
        <div>Dialog body</div>
      </DraggableDialogWrapper>,
    );

    const dragHandle = document.querySelector(
      `.${styles.DraggableArea}`,
    ) as HTMLDivElement | null;
    const dialogContent = document.querySelector(
      `.${styles.DialogContent}`,
    ) as HTMLDivElement | null;

    expect(dragHandle).toBeTruthy();
    expect(dialogContent).toBeTruthy();
    expect(dialogContent?.style.transform).toBe('translate(250px, 160px)');

    fireEvent.mouseDown(dragHandle!, { button: 2 });
    dispatchMouseMove(document, { movementX: 40, movementY: 25, buttons: 2 });

    expect(dialogContent?.style.transform).toBe('translate(250px, 160px)');
  });

  it('stops dragging when mouse is no longer pressed', () => {
    Object.defineProperty(window, 'visualViewport', {
      configurable: true,
      value: {
        width: 1000,
        height: 800,
      },
    });

    render(
      <DraggableDialogWrapper
        onOpenChange={vi.fn()}
        open={true}
        description={null}
      >
        <div>Dialog body</div>
      </DraggableDialogWrapper>,
    );

    const dragHandle = document.querySelector(
      `.${styles.DraggableArea}`,
    ) as HTMLDivElement | null;
    const dialogContent = document.querySelector(
      `.${styles.DialogContent}`,
    ) as HTMLDivElement | null;

    expect(dragHandle).toBeTruthy();
    expect(dialogContent).toBeTruthy();

    fireEvent.mouseDown(dragHandle!, { button: 0 });
    dispatchMouseMove(document, { movementX: 50, movementY: 50, buttons: 1 });
    expect(dialogContent?.style.transform).toBe('translate(300px, 210px)');

    dispatchMouseMove(document, { movementX: 20, movementY: 20, buttons: 0 });
    dispatchMouseMove(document, { movementX: 20, movementY: 20, buttons: 1 });

    expect(dialogContent?.style.transform).toBe('translate(300px, 210px)');
  });
});
