import { describe, expect, it } from 'vitest';

import { getSliderStep, hasSliderRange } from './NumberSlider';

describe('hasSliderRange', () => {
  it.each([
    [{ min: '0', max: '100' }, true],
    [{ min: '-100', max: '100' }, true],
    [{ min: '0', max: '1', step: 'any' }, true],
    [{ min: '0' }, false],
    [{ max: '100' }, false],
    [{ min: '', max: '' }, false],
    [{ min: '5', max: '5' }, false],
    [{ min: '0', max: '10000' }, true],
    [{ min: '0', max: '20000' }, false],
    // A larger step allows a larger range.
    [{ min: '0', max: '100000', step: '10' }, true],
    // Timestamps: too many values per slider pixel.
    [{ min: '-2147483648', max: '2147483648' }, false],
  ])('%j → %s', (attributes, expected) => {
    expect(hasSliderRange(attributes)).toBe(expected);
  });
});

describe('getSliderStep', () => {
  it.each([
    [{ min: '0', max: '3' }, 1],
    [{ min: '0', max: '3', step: '' }, 1],
    [{ min: '0', max: '10', step: '0.5' }, 0.5],
    [{ min: '0', max: '1', step: 'any' }, 0.01],
  ])('%j → %s', (attributes, expected) => {
    expect(getSliderStep(attributes)).toBe(expected);
  });
});
