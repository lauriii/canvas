import { describe, expect, it } from 'vitest';

import { getComponentTranslatableStrings } from './graph';

import type { ComponentImportResolver, ComponentModule } from './graph';

const HOOK = "import { useTranslation } from 'drupal-canvas/react';\n";

/**
 * Analyzes a component among components that imports resolve to by name.
 *
 * @param sources
 *   The source code of the components by name, or `null` for one that cannot
 *   be analyzed.
 */
const analyze = (entry: string, sources: Record<string, string | null>) => {
  const modules: Record<string, ComponentModule> = Object.fromEntries(
    Object.entries(sources).map(([id, source]) => [id, { id, source }]),
  );
  // Specifiers name components, or are relative to the importing one.
  const resolve: ComponentImportResolver = (specifier, importer) =>
    modules[
      specifier.startsWith('./')
        ? `${importer}/${specifier.slice(2)}`
        : specifier
    ] ?? null;
  return getComponentTranslatableStrings(modules[entry], resolve);
};

describe('getComponentTranslatableStrings', () => {
  it('merges the strings of the components a component imports', () => {
    expect(
      analyze('card', {
        card: `${HOOK}
          import Badge from 'badge';
          import { useLabel } from 'helper';
          import type { Props } from 'types';
          export default function Card() {
            const { t } = useTranslation();
            return [t('Card'), t('Shared'), Badge, useLabel()];
          }
          export const load = () => import('lazy');`,
        badge: `${HOOK}
          export { default as Icon } from 'icon';
          import Card from 'card';
          export default () => useTranslation().t('Shared');`,
        icon: `${HOOK}
          export default () =>
            useTranslation().formatPlural(1, '1 icon', '@count icons');`,
        // Not components: the resolver does not resolve them.
        types: `${HOOK}export const x = useTranslation().t('Types');`,
        lazy: `${HOOK}export default () => useTranslation().t('Lazy');`,
      }),
    ).toEqual({
      usesTranslationHook: true,
      // Directly and transitively, including the cycle back to the card, but
      // not the type-only and dynamic imports.
      modules: ['card', 'badge', 'icon'],
      translatableStrings: [
        { source: '1 icon', plural: '@count icons' },
        { source: 'Card' },
        { source: 'Shared' },
      ],
    });
  });

  it('resolves imports relative to the module that imports them', () => {
    expect(
      analyze('card', {
        card: `import Badge from './badge';\nexport default () => Badge;`,
        'card/badge': `${HOOK}
          import Icon from './icon';
          export default () => useTranslation().t('Badge');`,
        'card/badge/icon': `${HOOK}export default () => useTranslation().t('Icon');`,
      }),
    ).toEqual({
      usesTranslationHook: true,
      modules: ['card', 'card/badge', 'card/badge/icon'],
      translatableStrings: [{ source: 'Badge' }, { source: 'Icon' }],
    });
  });

  it('does not follow translation functions imported from other modules', () => {
    expect(
      analyze('card', {
        card: `import { t } from 'badge';
          export default () => t('Not from the hook');`,
        badge: 'export const t = (string) => string;',
      }),
    ).toEqual({
      usesTranslationHook: false,
      modules: ['card', 'badge'],
      translatableStrings: [],
    });
  });

  it('reports invalid literals with their module', () => {
    expect(
      analyze('card', {
        card: `${HOOK}
          import Badge from 'badge';
          export default () => useTranslation().t('');`,
        badge: `${HOOK}
          export default () => useTranslation().formatPlural(1, 'One', '');`,
      }),
    ).toEqual({
      usesTranslationHook: true,
      modules: ['card', 'badge'],
      errors: [
        expect.objectContaining({
          module: 'card',
          message: 'A translatable string must not be empty.',
        }),
        expect.objectContaining({ module: 'badge' }),
      ],
    });
  });

  it('makes the strings unknown when a component cannot be analyzed', () => {
    const card = `${HOOK}
      import Badge from 'badge';
      export default () => useTranslation().t('Card');`;
    expect(
      analyze('card', { card, badge: 'export default () => <p>;' }),
    ).toEqual({
      usesTranslationHook: true,
      modules: ['card', 'badge'],
      unknown: {
        module: 'badge',
        syntaxError: expect.objectContaining({ line: 1 }),
      },
    });
    expect(analyze('card', { card, badge: null })).toEqual({
      usesTranslationHook: true,
      modules: ['card', 'badge'],
      unknown: { module: 'badge' },
    });
    expect(analyze('card', { card: null })).toEqual({
      usesTranslationHook: null,
      modules: ['card'],
      unknown: { module: 'card' },
    });
  });

  it('analyzes changed components again', () => {
    const source = (string: string) =>
      `${HOOK}export default () => useTranslation().t('${string}');`;
    expect(analyze('card', { card: source('One') })).toMatchObject({
      translatableStrings: [{ source: 'One' }],
    });
    expect(analyze('card', { card: source('Two') })).toMatchObject({
      translatableStrings: [{ source: 'Two' }],
    });
  });
});

describe('usesTranslationHook', () => {
  it('is true for any reference to the hook in the component or the components it imports', () => {
    expect(
      analyze('card', {
        card: "import Badge from 'badge';\nexport default () => Badge;",
        // Calls without string literals translate interface text too.
        badge: `${HOOK}export default ({ label }) => useTranslation().t(label);`,
      }),
    ).toMatchObject({ usesTranslationHook: true, translatableStrings: [] });
  });

  it('is false without a reference to the hook', () => {
    expect(
      analyze('card', {
        card: `${HOOK}
          export default () => {
            const useTranslation = () => ({ t: (s) => s });
            return useTranslation().t('Shadowed');
          };`,
      }),
    ).toEqual({
      usesTranslationHook: false,
      modules: ['card'],
      translatableStrings: [],
    });
  });

  it('is unknown when a component cannot be analyzed', () => {
    expect(
      analyze('card', {
        card: "import Badge from 'badge';\nexport default () => Badge;",
        badge: 'export default () => <p>;',
      }).usesTranslationHook,
    ).toBeNull();
  });
});
