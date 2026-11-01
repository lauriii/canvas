import { describe, expect, it } from 'vitest';
import { Theme } from '@radix-ui/themes';
import { render, screen } from '@testing-library/react';

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
});
