import { describe, expect, it } from 'vitest';

import { isMockSpecPath } from './mock-spec-path';

describe('isMockSpecPath', () => {
  it('matches mock spec files in either naming form', () => {
    expect(isMockSpecPath('src/components/card/mocks.json')).toBe(true);
    expect(isMockSpecPath('src/components/card/card.mocks.json')).toBe(true);
    expect(isMockSpecPath('src\\components\\card\\mocks.json')).toBe(true);
  });

  it('ignores other files', () => {
    expect(isMockSpecPath('src/components/card/component.yml')).toBe(false);
    expect(isMockSpecPath('src/components/card/index.jsx')).toBe(false);
    expect(isMockSpecPath('pages/home.json')).toBe(false);
    expect(isMockSpecPath('src/components/card/mocks.json.bak')).toBe(false);
  });
});
