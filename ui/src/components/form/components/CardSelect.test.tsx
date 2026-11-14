import { describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, within } from '@testing-library/react';

import { FieldContextProvider } from '@/components/form/contexts/FieldContext';

import CardSelect, { showsCards } from './CardSelect';

import type { Attributes } from '@/types/DrupalAttribute';

type Option = { value: string; label: string; selected: boolean; type: string };

const option = (value: string, label: string, selected = false): Option => ({
  value,
  label,
  selected,
  type: 'option',
});

// A plain select: jsdom wraps <select> in a Proxy, which rejects the
// interceptNativeSetter() of Select. That one fires the change event of a value
// that the picker sets.
vi.mock('@/components/form/components/Select', () => ({
  default: ({
    attributes,
    options,
  }: {
    attributes: Attributes;
    options: Option[];
  }) => (
    <select
      name={`${attributes.name}`}
      defaultValue={options.find((option) => option.selected)?.value}
    >
      {options.map((option) => (
        <option key={option.value} value={option.value}>
          {option.label}
        </option>
      ))}
    </select>
  ),
}));

const attributes = {
  id: 'edit-image',
  name: 'image',
  'data-canvas-options-display': 'cards',
  'data-canvas-option-images': JSON.stringify({
    1: '/files/sunset.jpg',
    2: '/files/desk.jpg',
  }),
};

const options = [
  option('_none', '- None -'),
  option('1', 'Sunset', true),
  option('2', 'Standing desk'),
  option('3', 'Unavailable (3)'),
  option('4', 'Sunrise'),
];

const renderPicker = (overrides = {}, pickerOptions = options) => {
  const triggerBlur = vi.fn();
  const view = render(
    <FieldContextProvider
      value={{ fieldName: 'image', triggerChange: vi.fn(), triggerBlur }}
    >
      <label htmlFor="edit-image">Image</label>
      <CardSelect
        attributes={{ ...attributes, ...overrides }}
        options={pickerOptions}
      />
    </FieldContextProvider>,
  );
  return { ...view, triggerBlur };
};

const open = () =>
  fireEvent.click(screen.getByRole('button', { name: /^Image/ }));

describe('showsCards', () => {
  it('follows the display of a single-value select', () => {
    expect(showsCards(attributes)).toBe(true);
    expect(showsCards({ id: 'edit-image' })).toBe(false);
    expect(showsCards({ ...attributes, multiple: 'multiple' })).toBe(false);
  });
});

describe('CardSelect', () => {
  it('shows the choice with its image, named by the field label', () => {
    renderPicker({ 'aria-describedby': 'edit-image--description' });
    const trigger = screen.getByRole('button', { name: 'Image Sunset' });
    expect(trigger.getAttribute('aria-describedby')).toBe(
      'edit-image--description',
    );
    expect(trigger.querySelector('img')?.getAttribute('src')).toBe(
      '/files/sunset.jpg',
    );
    // The cards only render while the popover is open.
    expect(screen.queryByRole('listbox')).toBeNull();
  });

  it('offers a card per option, with a decorative image or placeholder', () => {
    renderPicker();
    open();
    const cards = within(
      screen.getByRole('listbox', { name: 'Image' }),
    ).getAllByRole('option');
    // "None" is not a card: the field is cleared with its own button.
    expect(cards.map((card) => card.textContent)).toEqual([
      'Sunset',
      'Standing desk',
      'Unavailable (3)',
      'Sunrise',
    ]);
    expect(cards.map((card) => card.getAttribute('aria-selected'))).toEqual([
      'true',
      'false',
      'false',
      'false',
    ]);
    // The label names the card: its image has no text alternative.
    const image = cards[1].querySelector('img');
    expect(image?.getAttribute('src')).toBe('/files/desk.jpg');
    expect(image?.getAttribute('alt')).toBe('');
    expect(image?.closest('[aria-hidden="true"]')).not.toBeNull();
    // An option without an image gets a placeholder.
    expect(cards[2].querySelector('img')).toBeNull();
    expect(cards[2].querySelector('[aria-hidden="true"] svg')).not.toBeNull();
  });

  it('filters the cards by label', () => {
    renderPicker();
    open();
    const search = screen.getByRole('textbox', { name: 'Search' });
    fireEvent.change(search, { target: { value: ' SUN' } });
    expect(
      screen.getAllByRole('option').map((card) => card.textContent),
    ).toEqual(['Sunset', 'Sunrise']);
    fireEvent.change(search, { target: { value: 'moon' } });
    expect(screen.queryByRole('listbox')).toBeNull();
    expect(screen.getByRole('status').textContent).toBe('No matches');
  });

  it('says when the site has no options', () => {
    renderPicker({}, [option('_none', '- None -', true)]);
    expect(screen.getByRole('button', { name: 'Image Choose…' })).toBeTruthy();
    open();
    expect(screen.getByRole('status').textContent).toBe(
      'Nothing to choose from yet',
    );
  });

  it('moves between the cards with the arrow keys', () => {
    renderPicker();
    open();
    const cards = screen.getAllByRole('option');
    // The chosen card is the one in the tab order.
    expect(cards.map((card) => card.tabIndex)).toEqual([0, -1, -1, -1]);
    fireEvent.keyDown(screen.getByRole('textbox', { name: 'Search' }), {
      key: 'ArrowDown',
    });
    expect(document.activeElement).toBe(cards[0]);
    fireEvent.keyDown(cards[0], { key: 'ArrowRight' });
    expect(document.activeElement).toBe(cards[1]);
    // The next row starts three cards on.
    fireEvent.keyDown(cards[0], { key: 'ArrowDown' });
    expect(document.activeElement).toBe(cards[3]);
    fireEvent.keyDown(cards[3], { key: 'ArrowLeft' });
    expect(document.activeElement).toBe(cards[2]);
    // No card above the first row: focus stays.
    fireEvent.keyDown(cards[2], { key: 'ArrowUp' });
    expect(document.activeElement).toBe(cards[2]);
  });

  it('stores the value of a chosen card in the select and closes', () => {
    const { container } = renderPicker();
    open();
    fireEvent.click(screen.getByRole('option', { name: 'Standing desk' }));
    // The select stays the form element.
    expect(container.querySelector('select')?.value).toBe('2');
    expect(screen.queryByRole('listbox')).toBeNull();
    expect(
      screen.getByRole('button', { name: 'Image Standing desk' }),
    ).toBeTruthy();
  });

  it('clears an optional select', () => {
    const { container } = renderPicker();
    expect(container.querySelector('select')?.value).toBe('1');
    fireEvent.click(screen.getByRole('button', { name: 'Clear Sunset' }));
    expect(container.querySelector('select')?.value).toBe('_none');
    const trigger = screen.getByRole('button', { name: 'Image Choose…' });
    expect(document.activeElement).toBe(trigger);
    expect(screen.queryByRole('button', { name: /^Clear/ })).toBeNull();
  });

  it('offers no clear button without "None", or when disabled', () => {
    const { unmount } = renderPicker({}, options.slice(1));
    expect(screen.queryByRole('button', { name: /^Clear/ })).toBeNull();
    unmount();
    renderPicker({ disabled: true });
    expect(screen.queryByRole('button', { name: /^Clear/ })).toBeNull();
    expect(
      screen
        .getByRole('button', { name: 'Image Sunset' })
        .hasAttribute('disabled'),
    ).toBe(true);
  });

  it('forwards the invalid state and blur', () => {
    const { container, triggerBlur } = renderPicker({
      'data-invalid-prop-value': 'true',
    });
    expect(
      container.querySelector('[data-invalid-prop-value]')?.tagName,
    ).not.toBe('SELECT');
    const trigger = screen.getByRole('button', { name: 'Image Sunset' });
    const clear = screen.getByRole('button', { name: 'Clear Sunset' });
    // Moving within the picker is not a blur of the field.
    fireEvent.blur(trigger, { relatedTarget: clear });
    expect(triggerBlur).not.toHaveBeenCalled();
    fireEvent.blur(clear, { relatedTarget: document.body });
    expect(triggerBlur).toHaveBeenCalledWith(container.querySelector('select'));
  });
});
