import { describe, expect, it } from 'vitest';

import { compileJS } from './compile-js';

describe('compile js', () => {
  it('should compile js', () => {
    expect(compileJS('console.log("Hello, world!");')).toBe(
      'console.log("Hello, world!");\n',
    );
  });

  it('should compile jsx', () => {
    expect(compileJS('const x = <div>Hello, world!</div>;'))
      .toMatchInlineSnapshot(`
      "import { jsx as _jsx } from "react/jsx-runtime";
      const x = /*#__PURE__*/ _jsx("div", {
          children: "Hello, world!"
      });
      "
    `);
  });

  it('should compile TypeScript', () => {
    expect(compileJS('const x: string = "hello";')).toMatchInlineSnapshot(`
      "const x = "hello";
      "
    `);
  });

  it('should compile TSX', () => {
    expect(compileJS('const x: string = <div>Hello, world!</div>;'))
      .toMatchInlineSnapshot(`
      "import { jsx as _jsx } from "react/jsx-runtime";
      const x = /*#__PURE__*/ _jsx("div", {
          children: "Hello, world!"
      });
      "
    `);
  });

  it('rewrites Vite-style image imports to import map resolutions', () => {
    expect(
      compileJS(
        [
          `import wallImage from './image-1.webp';`,
          `import cafeImage from './cafe.jpg';`,
          `const src = wallImage || cafeImage;`,
        ].join('\n'),
        {
          filePath: '/project/src/components/local-image-example/index.tsx',
          aliasBaseDir: '/project/src',
        },
      ),
    ).toMatchInlineSnapshot(`
      "const wallImage = import.meta.resolve("@/components/local-image-example/image-1.webp");
      const cafeImage = import.meta.resolve("@/components/local-image-example/cafe.jpg");
      const src = wallImage || cafeImage;
      "
    `);
  });

  it('rewrites image imports with query strings and hashes to import map resolutions', () => {
    expect(
      compileJS(
        [
          `import heroImage from './hero.webp?url';`,
          `import posterImage from './poster.jpg#poster';`,
          `const src = heroImage || posterImage;`,
        ].join('\n'),
        {
          filePath: '/project/src/components/card/index.tsx',
          aliasBaseDir: '/project/src',
        },
      ),
    ).toMatchInlineSnapshot(`
      "const heroImage = import.meta.resolve("@/components/card/hero.webp");
      const posterImage = import.meta.resolve("@/components/card/poster.jpg");
      const src = heroImage || posterImage;
      "
    `);
  });

  it('should handle errors', () => {
    expect(() => compileJS('const x')).toThrowErrorMatchingInlineSnapshot(`
      "  x 'const' declarations must be initialized
         ,----
       1 | const x
         :       ^
         \`----


      Caused by:
          0: failed to process js file
          1: Syntax Error"
    `);
  });
});
