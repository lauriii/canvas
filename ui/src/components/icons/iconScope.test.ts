import { describe, expect, it } from 'vitest';

import { ICON_SCHEMA_REF, isIconSchema } from '@/components/icons/iconScope';

describe('isIconSchema', () => {
  it('recognizes the icon $ref and scope patterns', () => {
    expect(isIconSchema({ type: 'string', $ref: ICON_SCHEMA_REF })).toBe(true);
    expect(isIconSchema({ type: 'string', pattern: '^(phosphor):.+$' })).toBe(
      true,
    );
  });

  it('uses the first declared type, like SDC metadata', () => {
    expect(
      isIconSchema({ type: ['string', 'object'], pattern: '^(phosphor):.+$' }),
    ).toBe(true);
    expect(
      isIconSchema({ type: ['object', 'string'], pattern: '^(phosphor):.+$' }),
    ).toBe(false);
  });

  it('rejects non-icon schemas', () => {
    expect(isIconSchema({ type: 'string' })).toBe(false);
    expect(isIconSchema(null)).toBe(false);
  });
});
