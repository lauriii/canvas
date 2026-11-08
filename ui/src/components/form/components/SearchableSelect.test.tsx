import { describe, expect, it } from 'vitest';
import { cleanup, render, screen } from '@testing-library/react';

import { needsSearchableSelect } from '@/components/form/components/SearchableSelect';
import {
  formatTreeOptionLabel,
  noOptionsMessage,
} from '@/components/form/components/treeOptionLabel';

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

  it('searches options from site data, however many there are', () => {
    expect(
      needsSearchableSelect(
        { 'data-canvas-options-source': 'true' },
        options(['A']),
      ),
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
    }) as {
      props: {
        children: [{ props: { children: string } }, string];
        style: object;
      };
    };
    const [parents, leaf] = rendered.props.children;
    expect(leaf).toBe('Color');
    // Screen readers still get the path.
    expect(parents.props.children).toBe('Design › ');
    expect(rendered.props.style).toEqual({ paddingInlineStart: '16px' });
  });

  it('keeps the path for the chosen value and while searching', () => {
    for (const meta of [
      { context: 'value' as const, inputValue: '' },
      { context: 'menu' as const, inputValue: 'col' },
    ]) {
      render(<>{formatTreeOptionLabel(nested, meta)}</>);
      expect(screen.getByText('Design ›', { exact: false })).toBeTruthy();
      expect(screen.getByText('Color')).toBeTruthy();
      cleanup();
    }
  });

  it('leaves labels without a path alone', () => {
    expect(
      formatTreeOptionLabel(
        { label: 'News' },
        { context: 'value', inputValue: '' },
      ),
    ).toBe('News');
  });
});

describe('noOptionsMessage', () => {
  it('tells an empty list from a search without matches', () => {
    expect(noOptionsMessage({ inputValue: '' })).toBe(
      'Nothing to choose from yet',
    );
    expect(noOptionsMessage({ inputValue: 'x' })).toBe('No matches');
  });
});
