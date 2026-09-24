/* eslint vitest/expect-expect: ["error", { "assertFunctionNames": ["expect", "assert", "expectTypeOf"] }] */

import { expectTypeOf, it } from 'vitest';

import type { getPageData } from 'drupal-canvas';
import type { DrupalRouteTranslation } from './page';

it('shares exact translation entry types with getPageData, except external', () => {
  type PageDataTranslation = NonNullable<
    ReturnType<typeof getPageData>['mainEntity']
  >['translations'][number];

  // Checked by tsc, including the type-check step in this package's CI build.
  // Import the public package type, without loading its runtime or duplicating
  // its interface. Exact equality rejects extra keys and different value types.
  expectTypeOf<
    Omit<DrupalRouteTranslation, 'external'>
  >().toEqualTypeOf<PageDataTranslation>();
});
