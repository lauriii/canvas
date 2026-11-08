import { describe, expect, it } from 'vitest';

import { needsSearchableSelect } from '@/components/form/components/SearchableSelect';
import { formatTreeOptionLabel } from '@/components/form/components/treeOptionLabel';

const options = (labels: string[]) =>
  labels.map((label, index) => ({
    value: `${index}`,
    label,
    selected: false,
    type: 'option',
  }));

describe('needsSearchableSelect', () => {
  it('keeps short flat lists as dropdowns', () => {
    expect(needsSearchableSelect({}, options(['A', 'B', 'C']))).toBe(false);
  });

  it('searches long lists', () => {
    expect(needsSearchableSelect({}, options([...'ABCDEFGH']))).toBe(true);
  });

  it('shows nested options as a tree', () => {
    expect(
      needsSearchableSelect({}, options(['Design', 'Design › Color'])),
    ).toBe(true);
  });

  it('leaves multiple-value selects alone', () => {
    expect(
      needsSearchableSelect({ multiple: true }, options([...'ABCDEFGH'])),
    ).toBe(false);
  });
});

describe('formatTreeOptionLabel', () => {
  const nested = { label: 'Design › Color' };

  it('indents the last level in the menu', () => {
    const rendered = formatTreeOptionLabel(nested, {
      context: 'menu',
      inputValue: '',
    }) as { props: { children: string; style: object } };
    expect(rendered.props.children).toBe('Color');
    expect(rendered.props.style).toEqual({ paddingInlineStart: '16px' });
  });

  it('keeps the path for the chosen value and while searching', () => {
    expect(
      formatTreeOptionLabel(nested, { context: 'value', inputValue: '' }),
    ).toBe('Design › Color');
    expect(
      formatTreeOptionLabel(nested, { context: 'menu', inputValue: 'col' }),
    ).toBe('Design › Color');
  });
});
