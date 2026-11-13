# @drupal-canvas/translatable-strings

Finds the interface strings that Canvas Code Components translate with `t()` and
`formatPlural()` from `useTranslation()`. Used by the Canvas UI code editor, the
Canvas CLI, Canvas Workbench, and the Headless SDK, so that they find the same
strings. A private workspace package: consumers bundle its source.

It runs in browsers and Node.js alike. It reads no files, and knows no build
aliases or frameworks: callers pass source code, and resolve imports.

## API

```ts
import {
  getComponentTranslatableStrings,
  getTranslatableStringsFromSource,
} from '@drupal-canvas/translatable-strings';

// The strings of one module, or why they cannot be found.
const result = getTranslatableStringsFromSource(source);

// The strings of a component and of the components it imports. The caller
// resolves which imports are components, and their source code.
const strings = getComponentTranslatableStrings(
  { id: entryPath, source },
  (specifier, importer) => resolveComponent(specifier, importer),
);
```

- `analyzeModule()` parses a module once for its strings, invalid literals, use
  of `useTranslation()` and static imports.
- `getTranslatableStringsFromAst()` extracts the strings of an AST that the
  caller parsed.
- `normalizeTranslatableStrings()` deduplicates and sorts strings as Drupal
  stores them.

See [ADR 23](../../docs/adr/0023-code-component-interface-translation.md) for
the extraction rules.
