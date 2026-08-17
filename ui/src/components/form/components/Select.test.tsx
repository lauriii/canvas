import { describe, expect, it } from 'vitest';
import { render, screen } from '@testing-library/react';

import Select from '@/components/form/components/Select';
import {
  CLEAR_OPTION_LABEL,
  EMPTY_OPTION_LABEL,
  EMPTY_OPTION_VALUE,
  REQUIRED_EMPTY_OPTION_LABEL,
} from '@/components/form/components/selectEmptyOption';

const option = (value: string, label: string, selected = false) => ({
  value,
  label,
  selected,
  type: 'option',
});

const optionalOptions = [
  option(EMPTY_OPTION_VALUE, '- None -'),
  option('small', 'Small'),
  option('medium', 'Medium'),
];

const getSelect = () => screen.getByRole('combobox') as HTMLSelectElement;

describe('Select empty ("no value") state', () => {
  it('marks the control as unset and labels the sentinel as a state', () => {
    render(
      <Select
        attributes={{ name: 'size', value: EMPTY_OPTION_VALUE, onChange() {} }}
        options={optionalOptions}
      />,
    );

    expect(getSelect().dataset.canvasValueState).toBe('unset');
    // The empty state is conveyed by text, not by color or position alone.
    expect(
      screen.getByRole('option', { name: EMPTY_OPTION_LABEL }),
    ).toHaveValue(EMPTY_OPTION_VALUE);
    expect(screen.queryByRole('option', { name: '- None -' })).toBeNull();
  });

  it('treats a select with no value at all as unset', () => {
    render(<Select attributes={{ name: 'size' }} options={optionalOptions} />);

    expect(getSelect().dataset.canvasValueState).toBe('unset');
    expect(
      screen.getByRole('option', { name: EMPTY_OPTION_LABEL }),
    ).toBeInTheDocument();
  });

  it('marks the control as set and labels the sentinel as an action', () => {
    render(
      <Select
        attributes={{ name: 'size', value: 'small', onChange() {} }}
        options={optionalOptions}
      />,
    );

    expect(getSelect().dataset.canvasValueState).toBe('set');
    // With a value chosen, picking the sentinel is what clears the field.
    expect(
      screen.getByRole('option', { name: CLEAR_OPTION_LABEL }),
    ).toHaveValue(EMPTY_OPTION_VALUE);
    expect(
      screen.queryByRole('option', { name: EMPTY_OPTION_LABEL }),
    ).toBeNull();
  });

  it('flags the sentinel so it can be set apart from the real options', () => {
    render(<Select attributes={{ name: 'size' }} options={optionalOptions} />);

    const children = Array.from(getSelect().children) as HTMLOptionElement[];
    expect(children[0].dataset.canvasEmptyOption).toBe('true');
    expect(children[0].value).toBe(EMPTY_OPTION_VALUE);
    expect(children.slice(1).every((o) => !o.dataset.canvasEmptyOption)).toBe(
      true,
    );
  });

  it('prompts for a choice on a required select instead of offering to clear', () => {
    render(
      <Select
        attributes={{ name: 'size', required: true }}
        options={optionalOptions}
      />,
    );

    expect(
      screen.getByRole('option', { name: REQUIRED_EMPTY_OPTION_LABEL }),
    ).toHaveValue(EMPTY_OPTION_VALUE);
    expect(
      screen.queryByRole('option', { name: EMPTY_OPTION_LABEL }),
    ).toBeNull();
    expect(
      screen.queryByRole('option', { name: CLEAR_OPTION_LABEL }),
    ).toBeNull();
  });

  it('leaves a select without the sentinel untouched', () => {
    render(
      <Select
        attributes={{ name: 'weight', value: '0', onChange() {} }}
        options={[option('0', '0', true), option('1', '1')]}
      />,
    );

    const select = getSelect();
    expect(select.dataset.canvasValueState).toBeUndefined();
    expect(select.querySelector('[data-canvas-empty-option]')).toBeNull();
    expect(screen.getAllByRole('option')).toHaveLength(2);
  });

  it('does not rewrite options on a multi-select', () => {
    // On a multi-select an empty array is the empty state, so the sentinel is
    // stripped upstream and must not be relabeled here if it slips through.
    render(
      <Select
        attributes={{
          name: 'colors',
          multiple: true,
          value: ['small'],
          onChange() {},
        }}
        options={optionalOptions}
      />,
    );

    const select = screen.getByRole('listbox') as HTMLSelectElement;
    expect(select.dataset.canvasValueState).toBeUndefined();
    expect(select.querySelector('[data-canvas-empty-option]')).toBeNull();
    expect(
      screen.getByRole('option', { name: '- None -' }),
    ).toBeInTheDocument();
  });
});
