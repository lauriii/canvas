import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { createComponentFormStateHandler } from '@/components/form/react-hook-form/fields/componentFormHandlers';
import { COMPONENT_PREVIEW_UPDATE_EVENT } from '@/components/form/react-hook-form/fields/componentPreviewEvents';

import type { ComponentPreviewUpdateEvent } from '@/components/form/react-hook-form/fields/componentPreviewEvents';

const uuid = 'a1b2c3';
const createHandler = (source: string) => {
  const component = {
    source,
    propSources: { text: { jsonSchema: { type: 'string' } } },
  };
  const patchComponent = vi.fn();
  const handler = createComponentFormStateHandler({
    dispatch: vi.fn(),
    transforms: { text: {} },
    selectedComponentType: 'test.component',
    selectedComponent: uuid,
    propName: 'text',
    isScalarProp: true,
    polledBackgroundUpdate: { current: null },
    component,
    patchComponent,
    editorFrameContext: 'entity',
    version: '1',
  });
  const inputAndUiData = {
    selectedComponent: uuid,
    selectedComponentType: 'test.component',
    components: { 'test.component': component },
    model: {
      [uuid]: {
        source: { text: { sourceType: 'static:field_item:string', value: '' } },
        resolved: { text: '' },
      },
    },
  } as any;
  const type = (text: string) =>
    handler({ text }, inputAndUiData, { text: '' });
  return { patchComponent, type };
};

describe('createComponentFormStateHandler', () => {
  beforeEach(() => {
    vi.useFakeTimers();
  });
  afterEach(() => {
    vi.useRealTimers();
  });

  it('debounces code component PATCHes without a client-side update', () => {
    const { patchComponent, type } = createHandler('Code component');
    type('a');
    type('ab');
    type('abc');
    expect(patchComponent).not.toHaveBeenCalled();
    vi.advanceTimersByTime(400);
    expect(patchComponent).toHaveBeenCalledTimes(1);
  });

  it('debounces code component PATCHes longer after a client-side update', () => {
    const listener = (event: Event) =>
      (event as ComponentPreviewUpdateEvent).setPreviewBackgroundUpdate(true);
    document.addEventListener(COMPONENT_PREVIEW_UPDATE_EVENT, listener);
    try {
      const { patchComponent, type } = createHandler('Code component');
      type('a');
      type('ab');
      vi.advanceTimersByTime(400);
      expect(patchComponent).not.toHaveBeenCalled();
      vi.advanceTimersByTime(600);
      expect(patchComponent).toHaveBeenCalledTimes(1);
    } finally {
      document.removeEventListener(COMPONENT_PREVIEW_UPDATE_EVENT, listener);
    }
  });

  it('sends other components right away, as the form debounces them', () => {
    const { patchComponent, type } = createHandler(
      'Single-Directory Component',
    );
    type('a');
    expect(patchComponent).toHaveBeenCalledTimes(1);
  });
});
