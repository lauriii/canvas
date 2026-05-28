import { createElement, isValidElement } from 'react';
import { describe, expect, it } from 'vitest';

import { Region, RegionsProvider } from './Region';

describe('Region / RegionsProvider', () => {
  it('Region returns a valid React element', () => {
    const element = createElement(Region, { name: 'header' });
    expect(isValidElement(element)).toBe(true);
    expect(element.type).toBe(Region);
    expect(element.props).toMatchObject({ name: 'header' });
  });

  it('RegionsProvider returns a valid React element with regions in props', () => {
    const regions = { header: createElement('span', null, 'H') };
    const element = (
      <RegionsProvider regions={regions}>
        <Region name="header" />
      </RegionsProvider>
    );
    expect(isValidElement(element)).toBe(true);
    expect(element.type).toBe(RegionsProvider);
    expect(
      (element.props as unknown as { regions: typeof regions }).regions,
    ).toEqual(regions);
  });

  it('Region accepts an optional fallback prop', () => {
    const fallback = createElement('em', null, 'none');
    const element = createElement(Region, { name: 'missing', fallback });
    expect(isValidElement(element)).toBe(true);
    expect((element.props as { fallback: unknown }).fallback).toBe(fallback);
  });
});
