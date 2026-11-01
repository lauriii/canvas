import { describe, expect, it } from 'vitest';

import { resolveLinkUri } from './transforms';

describe('resolveLinkUri', () => {
  it.each([
    ['hello@example.com', 'mailto:hello@example.com'],
    [' hello@example.com ', 'mailto:hello@example.com'],
    ['+1 (555) 123-4567', 'tel:+15551234567'],
    ['555-123-4567', 'tel:5551234567'],
    ['Example (3)', 'entity:node/3'],
    ['https://example.com', 'https://example.com'],
    ['mailto:hello@example.com', 'mailto:hello@example.com'],
    ['tel:+15551234567', 'tel:+15551234567'],
    ['/about', '/about'],
    ['#contact', '#contact'],
    // Bare numbers are not phone numbers.
    ['12345678', '12345678'],
  ])('resolves %j to %j', (typed, expected) => {
    expect(resolveLinkUri(typed)).toBe(expected);
  });
});
