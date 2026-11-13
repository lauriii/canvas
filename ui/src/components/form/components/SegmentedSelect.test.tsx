import { describe, expect, it, vi } from 'vitest';
import { Theme } from '@radix-ui/themes';
import { fireEvent, render, screen } from '@testing-library/react';

import { FieldContextProvider } from '@/components/form/contexts/FieldContext';

import SegmentedSelect, { fitsSegmentedControl } from './SegmentedSelect';

const option = (value: string, label: string, selected = false) => ({
  value,
  label,
  selected,
  type: 'option',
});

describe('fitsSegmentedControl', () => {
  it('accepts 2 to 4 short options', () => {
    expect(
      fitsSegmentedControl({}, [option('a', 'Left'), option('b', 'Right')]),
    ).toBe(true);
    expect(fitsSegmentedControl({}, [option('a', 'Only')])).toBe(false);
    expect(
      fitsSegmentedControl(
        {},
        ['1', '2', '3', '4', '5'].map((v) => option(v, v)),
      ),
    ).toBe(false);
  });

  it('rejects long labels and multi-selects', () => {
    expect(
      fitsSegmentedControl({}, [
        option('a', 'A very long option label'),
        option('b', 'Another very long label'),
      ]),
    ).toBe(false);
    expect(
      fitsSegmentedControl({ multiple: 'multiple' }, [
        option('a', 'A'),
        option('b', 'B'),
      ]),
    ).toBe(false);
  });
});

describe('option icons', () => {
  const attributes = {
    'data-canvas-option-icons': JSON.stringify({
      a: 'text-align-left',
      b: 'no-such-icon',
    }),
  };

  it('fits long labels that are replaced by icons', () => {
    const options = [
      option('a', 'A very long option label that would not fit'),
      option('b', 'Right'),
    ];
    expect(fitsSegmentedControl({}, options)).toBe(false);
    expect(fitsSegmentedControl(attributes, options)).toBe(true);
  });

  it('shows the icon, and keeps the label of an unknown icon', () => {
    render(
      <Theme>
        <SegmentedSelect
          attributes={{ id: 'edit-align', name: 'align', ...attributes }}
          options={[option('a', 'Left', true), option('b', 'Right')]}
        />
      </Theme>,
    );
    const left = screen.getByRole('radio', { name: 'Left' });
    expect(left.querySelector('svg')).not.toBeNull();
    expect(left.textContent).toBe('');
    expect(left.getAttribute('title')).toBe('Left');
    const right = screen.getByRole('radio', { name: 'Right' });
    expect(right.querySelector('svg')).toBeNull();
  });
});

// Clicking a segment is covered by ui/tests/e2e/prop-types.cy.js: jsdom wraps
// <select> in a Proxy, which rejects interceptNativeSetter().
describe('SegmentedSelect', () => {
  it('renders one labelled segment per option', () => {
    const { container } = render(
      <Theme>
        <label htmlFor="edit-align">Alignment</label>
        <SegmentedSelect
          attributes={{ id: 'edit-align', name: 'align', value: 'left' }}
          options={[
            option('_none', '- None -'),
            option('left', 'Left'),
            option('right', 'Right'),
          ]}
        />
      </Theme>,
    );
    expect(screen.getByRole('group', { name: 'Alignment' })).toBeTruthy();
    expect(screen.getByRole('radio', { name: 'None' })).toBeTruthy();
    expect(
      screen.getByRole('radio', { name: 'Left' }).getAttribute('aria-checked'),
    ).toBe('true');
    // The select stays the form element.
    expect(container.querySelector('select')?.value).toBe('left');
  });

  it('forwards the description, the invalid state and blur', () => {
    const triggerBlur = vi.fn();
    const { container } = render(
      <Theme>
        <FieldContextProvider
          value={{ fieldName: 'align', triggerChange: vi.fn(), triggerBlur }}
        >
          <p id="edit-align--description">Text alignment</p>
          <SegmentedSelect
            attributes={{
              id: 'edit-align',
              name: 'align',
              value: 'left',
              'aria-describedby': 'edit-align--description',
              'data-invalid-prop-value': 'true',
            }}
            options={[option('left', 'Left'), option('right', 'Right')]}
          />
        </FieldContextProvider>
      </Theme>,
    );
    const group = screen.getByRole('group');
    expect(group.getAttribute('aria-describedby')).toBe(
      'edit-align--description',
    );
    expect(group.hasAttribute('data-invalid-prop-value')).toBe(true);

    const left = screen.getByRole('radio', { name: 'Left' });
    const right = screen.getByRole('radio', { name: 'Right' });
    // Moving between segments is not a blur of the field.
    fireEvent.blur(left, { relatedTarget: right });
    expect(triggerBlur).not.toHaveBeenCalled();
    fireEvent.blur(right, { relatedTarget: document.body });
    expect(triggerBlur).toHaveBeenCalledWith(container.querySelector('select'));
  });
});
