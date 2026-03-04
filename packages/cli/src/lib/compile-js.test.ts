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
