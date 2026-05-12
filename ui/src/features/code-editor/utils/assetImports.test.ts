import { describe, expect, it } from 'vitest';

import { rewriteAssetImportsForCanvas } from './assetImports';

describe('rewriteAssetImportsForCanvas', () => {
  it('rewrites relative image imports to manifest asset specifiers', () => {
    expect(
      rewriteAssetImportsForCanvas(
        [
          `import wallImage from './image-1.webp';`,
          `import cafeImage from './cafe.jpg';`,
          `const src = wallImage || cafeImage;`,
        ].join('\n'),
        {
          componentId: 'local-image-example',
          manifestAssetNames: [
            '@/components/local-image-example/image-1.webp',
            '@/components/local-image-example/cafe.jpg',
          ],
        },
      ),
    ).toMatchInlineSnapshot(`
      "const wallImage = import.meta.resolve("@/components/local-image-example/image-1.webp");
      const cafeImage = import.meta.resolve("@/components/local-image-example/cafe.jpg");
      const src = wallImage || cafeImage;"
    `);
  });

  it('uses exact alias image imports when present in the manifest', () => {
    expect(
      rewriteAssetImportsForCanvas(
        `import imageUrl from '@/components/card/image.webp';`,
        {
          manifestAssetNames: ['@/components/card/image.webp'],
        },
      ),
    ).toBe(
      `const imageUrl = import.meta.resolve("@/components/card/image.webp");`,
    );
  });

  it('leaves asset imports unchanged when no manifest entry matches', () => {
    expect(
      rewriteAssetImportsForCanvas(`import imageUrl from './image.webp';`, {
        componentId: 'card',
        manifestAssetNames: ['@/components/other/image.webp'],
      }),
    ).toBe(`import imageUrl from './image.webp';`);
  });

  it('does not rewrite SVG React component imports', () => {
    expect(
      rewriteAssetImportsForCanvas(`import Icon from './icon.svg?react';`, {
        componentId: 'card',
        manifestAssetNames: ['@/components/card/icon.svg'],
      }),
    ).toBe(`import Icon from './icon.svg?react';`);
  });

  it('rewrites relative assets outside the component folder when the manifest has one match', () => {
    expect(
      rewriteAssetImportsForCanvas(
        `import cafeImage from '../../lib/cafe.webp';`,
        {
          componentId: 'card',
          manifestAssetNames: ['@/lib/cafe.webp'],
        },
      ),
    ).toBe(`const cafeImage = import.meta.resolve("@/lib/cafe.webp");`);
  });
});
