import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import {
  cancelBackendUpdates,
  createComponentFormStateHandler,
  flushBackendUpdate,
} from '@/components/form/react-hook-form/fields/componentFormHandlers';
import { COMPONENT_PREVIEW_UPDATE_EVENT } from '@/components/form/react-hook-form/fields/componentPreviewEvents';

import type { ComponentPreviewUpdateEvent } from '@/components/form/react-hook-form/fields/componentPreviewEvents';

const uuid = 'a1b2c3';
const field = (prop: string) =>
  `canvas_component_props[${uuid}][${prop}][0][value]`;

const createHandler = (source: string, propName = 'text') => {
  const component = {
    source,
    propSources: {
      text: { jsonSchema: { type: 'string' } },
      title: { jsonSchema: { type: 'string' } },
    },
  };
  const patchComponent = vi.fn();
  const handler = createComponentFormStateHandler({
    dispatch: vi.fn(),
    transforms: { text: { mainProperty: {} }, title: { mainProperty: {} } },
    selectedComponentType: 'test.component',
    selectedComponent: uuid,
    propName,
    isScalarProp: true,
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
        source: {
          text: { sourceType: 'static:field_item:string' },
          title: { sourceType: 'static:field_item:string' },
        },
        resolved: { text: '', title: '' },
      },
    },
  } as any;
  // Each change carries the whole form, as the form fields do.
  const type = (form: Record<string, string>) => {
    const formState = Object.fromEntries(
      Object.entries(form).map(([prop, value]) => [field(prop), value]),
    );
    handler(formState, inputAndUiData, formState);
  };
  const sentResolved = (call = 0) =>
    patchComponent.mock.calls[call][1].resolved;
  return { patchComponent, type, sentResolved };
};

describe('createComponentFormStateHandler', () => {
  beforeEach(() => {
    vi.useFakeTimers();
    window.history.pushState({}, '', '/canvas/editor/canvas_page/1');
  });
  afterEach(() => {
    cancelBackendUpdates();
    vi.useRealTimers();
  });

  it('debounces code component PATCHes without a client-side update', () => {
    const { patchComponent, type, sentResolved } =
      createHandler('Code component');
    type({ text: 'a' });
    type({ text: 'ab' });
    type({ text: 'abc' });
    expect(patchComponent).not.toHaveBeenCalled();
    vi.advanceTimersByTime(400);
    expect(patchComponent).toHaveBeenCalledOnce();
    expect(sentResolved().text).toBe('abc');
  });

  it('debounces code component PATCHes longer after a client-side update', () => {
    const listener = (event: Event) =>
      (event as ComponentPreviewUpdateEvent).setPreviewBackgroundUpdate(true);
    document.addEventListener(COMPONENT_PREVIEW_UPDATE_EVENT, listener);
    try {
      const { patchComponent, type } = createHandler('Code component');
      type({ text: 'a' });
      type({ text: 'ab' });
      vi.advanceTimersByTime(400);
      expect(patchComponent).not.toHaveBeenCalled();
      vi.advanceTimersByTime(600);
      expect(patchComponent).toHaveBeenCalledOnce();
    } finally {
      document.removeEventListener(COMPONENT_PREVIEW_UPDATE_EVENT, listener);
    }
  });

  it('sends other components right away, as the form debounces them', () => {
    const { patchComponent, type } = createHandler(
      'Single-Directory Component',
    );
    type({ text: 'a' });
    expect(patchComponent).toHaveBeenCalledOnce();
  });

  it('keeps only the newest waiting PATCH per component', () => {
    const text = createHandler('Code component', 'text');
    const title = createHandler('Code component', 'title');
    text.type({ text: 'a', title: '' });
    title.type({ text: 'a', title: 'b' });
    flushBackendUpdate(uuid);
    vi.advanceTimersByTime(1000);
    expect(text.patchComponent).not.toHaveBeenCalled();
    expect(title.patchComponent).toHaveBeenCalledOnce();
    expect(title.sentResolved()).toEqual({ text: 'a', title: 'b' });
  });

  it('sends a waiting PATCH when the form goes away, on the same entity', () => {
    const { patchComponent, type } = createHandler('Code component');
    type({ text: 'a' });
    flushBackendUpdate(uuid);
    expect(patchComponent).toHaveBeenCalledOnce();
    vi.advanceTimersByTime(1000);
    expect(patchComponent).toHaveBeenCalledOnce();

    // Not after navigating to another entity, for a deleted component, or
    // after undo or redo cancels it.
    type({ text: 'ab' });
    window.history.pushState({}, '', '/canvas/editor/canvas_page/2');
    flushBackendUpdate(uuid);
    window.history.pushState({}, '', '/canvas/editor/canvas_page/1');
    type({ text: 'abc' });
    flushBackendUpdate(uuid, () => false);
    type({ text: 'abcd' });
    cancelBackendUpdates();
    vi.advanceTimersByTime(1000);
    expect(patchComponent).toHaveBeenCalledOnce();
  });

  it('resolves a flush once the PATCH is done, so a link can follow it', async () => {
    const { patchComponent, type } = createHandler('Code component');
    let finishPatch = () => {};
    patchComponent.mockReturnValue(
      new Promise<void>((resolve) => (finishPatch = resolve)),
    );
    type({ text: 'a' });
    let flushed = false;
    void flushBackendUpdate(uuid).then(() => (flushed = true));
    await vi.advanceTimersByTimeAsync(100);
    expect(patchComponent).toHaveBeenCalledOnce();
    expect(flushed).toBe(false);
    finishPatch();
    await vi.advanceTimersByTimeAsync(0);
    expect(flushed).toBe(true);
  });
});
