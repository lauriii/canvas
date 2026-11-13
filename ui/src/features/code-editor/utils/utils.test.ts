import { describe, expect, it } from 'vitest';

import {
  detectValidPropOrSlotChange,
  getTranslatableStringsPayload,
} from './utils';

describe('detectValidSlotsOrPropChange', () => {
  it('invalid change when arrays are identical', () => {
    const arr1 = [
      { id: '1', name: 'test' },
      {
        id: '2',
        name: 'test2',
      },
    ];
    const arr2 = [
      { id: '1', name: 'test' },
      { id: '2', name: 'test2' },
    ];
    expect(detectValidPropOrSlotChange(arr1, arr2)).toBe(false);
  });

  it('invalid change when the only difference is one additional item with empty name', () => {
    const current = [
      { id: '1', name: 'test' },
      { id: '2', name: 'test2' },
      { id: '3', name: '' },
    ];
    const last = [
      { id: '1', name: 'test' },
      { id: '2', name: 'test2' },
    ];
    expect(detectValidPropOrSlotChange(current, last)).toBe(false);
  });

  it('invalid change when the only difference is multiple items with empty names', () => {
    const current = [
      { id: '1', name: 'test' },
      { id: '2', name: 'test2' },
      { id: '3', name: '' },
      { id: '4', name: '' },
      { id: '5', name: '' },
    ];
    const last = [
      { id: '1', name: 'test' },
      { id: '2', name: 'test2' },
    ];
    expect(detectValidPropOrSlotChange(current, last)).toBe(false);
  });

  it('valid change when an item with a name is added', () => {
    const current = [
      { id: '1', name: 'test' },
      { id: '2', name: 'test2' },
      { id: '3', name: 'test3' },
    ];
    const last = [
      { id: '1', name: 'test' },
      { id: '2', name: 'test2' },
    ];
    expect(detectValidPropOrSlotChange(current, last)).toBe(true);
  });

  it('valid change when an item is removed', () => {
    const current = [{ id: '1', name: 'test' }];
    const last = [
      { id: '1', name: 'test' },
      { id: '2', name: 'test2' },
    ];
    expect(detectValidPropOrSlotChange(current, last)).toBe(true);
  });

  it('valid change when an item is updated', () => {
    const current = [
      { id: '1', name: 'newName' },
      { id: '2', name: 'test2' },
    ];
    const last = [
      { id: '1', name: 'test' },
      { id: '2', name: 'test2' },
    ];
    expect(detectValidPropOrSlotChange(current, last)).toBe(true);
  });

  it('valid change when empty-named item is added but a name is changed', () => {
    const current = [
      { id: '1', name: 'newName' },
      { id: '2', name: 'test2' },
      { id: '3', name: '' },
    ];
    const last = [
      { id: '1', name: 'test' },
      { id: '2', name: 'test2' },
    ];
    expect(detectValidPropOrSlotChange(current, last)).toBe(true);
  });

  it('valid change when empty-named item is added and order of other items is changed', () => {
    const current = [
      { id: '2', name: 'test' },
      { id: '1', name: 'test2' },
      { id: '3', name: '' },
    ];
    const last = [
      { id: '1', name: 'test' },
      { id: '2', name: 'test2' },
      { id: '3', name: '' },
    ];
    expect(detectValidPropOrSlotChange(current, last)).toBe(true);
  });

  it('invalid change when position of empty-named item is changed', () => {
    const current = [
      { id: '1', name: 'test' },
      { id: '3', name: '' },
      { id: '2', name: 'test2' },
    ];
    const last = [
      { id: '1', name: 'test' },
      { id: '2', name: 'test2' },
      { id: '3', name: '' },
    ];
    expect(detectValidPropOrSlotChange(current, last)).toBe(false);
  });

  it('valid change when item is removed and empty-named item is added', () => {
    const current = [
      { id: '1', name: 'test' },
      { id: '3', name: '' },
    ];
    const last = [
      { id: '1', name: 'test' },
      { id: '2', name: 'test2' },
    ];
    expect(detectValidPropOrSlotChange(current, last)).toBe(true);
  });
});

describe('getTranslatableStringsPayload', () => {
  const hook = "import { useTranslation } from 'drupal-canvas/react';\n";

  it('derives the strings of the source code', () => {
    expect(
      getTranslatableStringsPayload(
        `${hook}const { t } = useTranslation();\nt('Hello', {}, { context: 'Greeting' });`,
      ),
    ).toEqual({
      translatableStrings: [{ source: 'Hello', context: 'Greeting' }],
    });
    expect(getTranslatableStringsPayload('export default () => null;')).toEqual(
      { translatableStrings: [] },
    );
  });

  it('derives the strings of the component only', () => {
    // Drupal delivers the strings of the components that a component imports
    // with their own, and helpers are passed translated text.
    expect(
      getTranslatableStringsPayload(
        `${hook}import Badge from '@/components/badge';
import { useLabel } from '@/lib/labels';
import { format } from './format';
export default () => [useTranslation().t('Card'), useLabel(), Badge];`,
      ),
    ).toEqual({ translatableStrings: [{ source: 'Card' }] });
  });

  it('omits the strings when they cannot be determined', () => {
    expect(
      getTranslatableStringsPayload(
        `${hook}const { t } = useTranslation();\nt('');`,
      ),
    ).toEqual({});
    expect(getTranslatableStringsPayload('const x = ;')).toEqual({});
  });
});
