import { describe, expect, it, vi } from 'vitest';
import { act, render } from '@testing-library/react';
import AppWrapper from '@tests/vitest/components/AppWrapper';

import { makeStore } from '@/app/store';
import { setLayoutModel } from '@/features/layout/layoutModelSlice';
import { setSelection } from '@/features/ui/uiSlice';

import layoutFixture from '../../../../tests/fixtures/layout-default.json';
import Layers from './Layers';

// The last row the fixture renders, which is the one that ends up out of view
// when the layers panel overflows.
const LAST_COMPONENT_UUID = 'eaa37ee1-7d50-4041-b04c-c80bdbac3412';

function seedStore() {
  const store = makeStore();
  store.dispatch(setLayoutModel(layoutFixture as never));
  return store;
}

function renderLayers(store = makeStore()) {
  render(
    <AppWrapper store={store} location="/" path="/">
      <Layers />
    </AppWrapper>,
  );

  return store;
}

function getLayerRow(uuid: string) {
  const row = document.getElementById(`layer-${uuid}-name`);
  expect(row).not.toBeNull();
  return row as HTMLElement;
}

// The ids of the rows that were scrolled, deduplicated: one selection can scroll
// a row more than once, because expanding a collapsed ancestor remounts it. Ids
// are unique in the document, so this compares identity, unlike `toEqual` on the
// elements themselves, which compares DOM nodes structurally with isEqualNode().
function scrolledRowIds(scrollIntoView: { mock: { contexts: unknown[] } }) {
  return [
    ...new Set(scrollIntoView.mock.contexts.map((c) => (c as HTMLElement).id)),
  ];
}

describe('Layers panel', () => {
  it('scrolls a component into view when it becomes selected, and leaves the other components alone', () => {
    const store = renderLayers(seedStore());
    const scrollIntoView = vi.spyOn(HTMLElement.prototype, 'scrollIntoView');

    act(() => {
      store.dispatch(setSelection({ items: [LAST_COMPONENT_UUID] }));
    });

    expect(scrollIntoView).toHaveBeenCalledWith({ block: 'nearest' });
    expect(scrolledRowIds(scrollIntoView)).toEqual([
      getLayerRow(LAST_COMPONENT_UUID).id,
    ]);
  });

  it('scrolls a component back into view when it is selected again', () => {
    const store = renderLayers(seedStore());
    act(() => {
      store.dispatch(setSelection({ items: [LAST_COMPONENT_UUID] }));
    });
    const scrollIntoView = vi.spyOn(HTMLElement.prototype, 'scrollIntoView');

    act(() => {
      store.dispatch(setSelection({ items: [LAST_COMPONENT_UUID] }));
    });

    expect(scrollIntoView).toHaveBeenCalledWith({ block: 'nearest' });
    expect(scrolledRowIds(scrollIntoView)).toEqual([
      getLayerRow(LAST_COMPONENT_UUID).id,
    ]);
  });

  it('scrolls an already selected component into view when the panel mounts', () => {
    const store = seedStore();
    store.dispatch(setSelection({ items: [LAST_COMPONENT_UUID] }));
    const scrollIntoView = vi.spyOn(HTMLElement.prototype, 'scrollIntoView');

    renderLayers(store);

    expect(scrollIntoView).toHaveBeenCalledWith({ block: 'nearest' });
    expect(scrolledRowIds(scrollIntoView)).toEqual([
      getLayerRow(LAST_COMPONENT_UUID).id,
    ]);
  });
});
