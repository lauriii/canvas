import { afterEach, describe, expect, it, vi } from 'vitest';
import { Theme } from '@radix-ui/themes';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';

import {
  DrupalObjectPropsInline,
  DrupalObjectPropsItem,
  DrupalObjectPropsItemList,
} from './DrupalObjectPropsGroup';

const ROW_HEIGHT = 40;

/**
 * Renders an item list the way the server sends it: every item contains its
 * widgets and the hidden submit buttons of the actions the list allows.
 */
const renderList = (
  titles: string[],
  actions: string[] = ['remove', 'duplicate', 'move'],
) => {
  const onSubmit = vi.fn((e) => e.preventDefault());
  const list = (titles: string[]) => (
    <Theme>
      <form onSubmit={onSubmit}>
        <div id="canvas-object-props-features">
          <DrupalObjectPropsItemList>
            {titles.map((title, delta) => (
              <DrupalObjectPropsItem
                key={title}
                attributes={{ 'data-item-label': 'Features item' }}
              >
                <input type="text" defaultValue={title} />
                {actions.map((action) => (
                  <input
                    key={action}
                    type="submit"
                    name={`features_${delta}_object_props_${action}`}
                    {...{ [`data-object-props-${action}`]: 'true' }}
                  />
                ))}
              </DrupalObjectPropsItem>
            ))}
            <input type="submit" data-object-props-add="true" />
          </DrupalObjectPropsItemList>
        </div>
      </form>
    </Theme>
  );
  const { rerender } = render(list(titles));
  // The submit button and the position the form was submitted with.
  const submitted = () =>
    onSubmit.mock.calls.map(([event]) => [
      event.nativeEvent.submitter.name,
      new FormData(event.target).get('canvas_object_props_position'),
    ]);
  return {
    submitted,
    rerenderWith: (titles: string[]) => rerender(list(titles)),
  };
};

// Clicking a hidden submit button marks the page as busy with an AJAX request.
afterEach(() => {
  document.body.removeAttribute('data-canvas-ajax-behaviors');
});

describe('DrupalObjectPropsItem', () => {
  it('offers the actions whose hidden submit button the server rendered', async () => {
    const { submitted } = renderList(['Fast', 'Flexible']);
    fireEvent.click(
      await screen.findByRole('button', { name: 'Edit Features item: Fast' }),
    );
    expect(
      screen.getAllByRole('button', { name: 'Remove' }).length,
    ).toBeGreaterThan(0);
    fireEvent.click(screen.getAllByRole('button', { name: 'Duplicate' })[0]);
    await waitFor(() =>
      expect(submitted()).toEqual([
        ['features_0_object_props_duplicate', null],
      ]),
    );
  });

  it('offers no action the list does not allow', async () => {
    // A list at `minItems` and `maxItems`, with one item: nothing to remove,
    // duplicate or move.
    renderList(['Fast'], []);
    fireEvent.click(
      await screen.findByRole('button', { name: 'Edit Features item: Fast' }),
    );
    expect(screen.queryByRole('button', { name: 'Remove' })).toBeNull();
    expect(screen.queryByRole('button', { name: 'Duplicate' })).toBeNull();
    expect(screen.queryByRole('button', { name: /^Reorder/ })).toBeNull();
  });
});

describe('DrupalObjectPropsInline', () => {
  it('shows the widgets in the form, as a group named after the prop', () => {
    render(
      <DrupalObjectPropsInline attributes={{ 'data-item-label': 'Button' }}>
        <input type="text" aria-label="Label" defaultValue="Get started" />
      </DrupalObjectPropsInline>,
    );
    const group = screen.getByRole('group', { name: 'Button' });
    // No row to click and no popover to open: the widget is in the group.
    expect(screen.queryByRole('button')).toBeNull();
    expect(screen.getByRole('textbox', { name: 'Label' })).toBeVisible();
    expect(group.contains(screen.getByRole('textbox'))).toBe(true);
  });
});

// jsdom has no layout: stack the rows like the list does. The test setup puts
// its own getBoundingClientRect() on HTMLElement.
const stackRows = () =>
  vi
    .spyOn(HTMLElement.prototype, 'getBoundingClientRect')
    .mockImplementation(function (this: HTMLElement) {
      const rows = [
        ...document.querySelectorAll('[data-object-props-item-row]'),
      ].map((trigger) => trigger.parentElement);
      const index = rows.indexOf(this);
      // Every other element, such as the list, is as tall as all rows.
      return index === -1
        ? new DOMRect(0, 0, 200, rows.length * ROW_HEIGHT)
        : new DOMRect(0, index * ROW_HEIGHT, 200, ROW_HEIGHT);
    });

describe('DrupalObjectPropsItemList', () => {
  it('moves a row from the keyboard and tells the server where to', async () => {
    stackRows();
    const { submitted } = renderList(['Fast', 'Flexible', 'Friendly']);
    const handle = await screen.findByRole('button', {
      name: 'Reorder Features item: Fast',
    });
    handle.focus();
    fireEvent.keyDown(handle, { code: 'Enter' });
    await waitFor(() =>
      expect(handle.getAttribute('aria-pressed')).toBe('true'),
    );
    fireEvent.keyDown(handle, { code: 'ArrowDown' });
    fireEvent.keyDown(handle, { code: 'ArrowDown' });
    fireEvent.keyDown(handle, { code: 'Enter' });
    await waitFor(() =>
      expect(submitted()).toEqual([['features_0_object_props_move', '2']]),
    );
    // The rows already show the new order while the server rebuilds the list.
    expect(
      screen
        .getAllByRole('button', { name: /^Edit Features item/ })
        .map((row) => row.textContent),
    ).toEqual(['Flexible', 'Friendly', 'Fast']);
  });

  it('keeps its rows once an AJAX response has replaced it', async () => {
    const { rerenderWith } = renderList(['Fast', 'Flexible']);
    await screen.findByRole('button', { name: 'Edit Features item: Fast' });
    const rowCount = (list: Element) =>
      list.querySelectorAll('[data-object-props-item-row]').length;
    const wrapper = document.querySelector('#canvas-object-props-features');
    expect.assert(wrapper);

    // The form reloads: a list that is still in the page shows the new rows.
    rerenderWith(['Fast', 'Flexible', 'Friendly']);
    expect(rowCount(wrapper)).toBe(3);

    // An AJAX response replaces the list. React keeps rendering the replaced
    // list, which must not mount the rows of a later reload: their fields
    // would overwrite the form state of the rows the author sees.
    wrapper.remove();
    rerenderWith(['Fast', 'Flexible', 'Friendly', 'Fresh']);
    expect(rowCount(wrapper)).toBe(3);
  });

  it('does not move a row while the list is being rebuilt', async () => {
    stackRows();
    const { submitted } = renderList(['Fast', 'Flexible']);
    const handle = await screen.findByRole('button', {
      name: 'Reorder Features item: Fast',
    });
    document.body.setAttribute('data-canvas-ajax-behaviors', 'true');
    fireEvent.keyDown(handle, { code: 'Enter' });
    await waitFor(() =>
      expect(handle.getAttribute('aria-pressed')).toBe('true'),
    );
    fireEvent.keyDown(handle, { code: 'ArrowDown' });
    fireEvent.keyDown(handle, { code: 'Enter' });
    await waitFor(() =>
      expect(handle.getAttribute('aria-pressed')).not.toBe('true'),
    );
    expect(submitted()).toEqual([]);
  });
});
