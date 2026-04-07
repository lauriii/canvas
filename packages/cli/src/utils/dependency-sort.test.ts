import { describe, expect, it } from 'vitest';

import { sortByDependencies } from './dependency-sort';

describe('sortByDependencies', () => {
  it('handles components into waves by dependency level', () => {
    // product-card imports button and badge
    // button imports icon
    // badge and icon have no imports
    const components = [
      { machineName: 'product-card' },
      { machineName: 'button' },
      { machineName: 'badge' },
      { machineName: 'icon' },
    ];

    const dependencies: Record<string, string[]> = {
      'product-card': ['button', 'badge'],
      button: ['icon'],
      badge: [],
      icon: [],
    };

    const waves = sortByDependencies(
      components,
      (c) => dependencies[c.machineName] || [],
    );

    expect(waves.length).toBe(3);
    // Wave 1: icon and badge (no dependencies)
    expect(new Set(waves[0].map((c) => c.machineName))).toEqual(
      new Set(['icon', 'badge']),
    );
    // Wave 2: button (depends on icon)
    expect(waves[1].map((c) => c.machineName)).toEqual(['button']);
    // Wave 3: product-card (depends on button and badge)
    expect(waves[2].map((c) => c.machineName)).toEqual(['product-card']);
  });

  it('puts all independent components in a single wave', () => {
    const components = [
      { machineName: 'avatar' },
      { machineName: 'badge' },
      { machineName: 'spinner' },
    ];

    const waves = sortByDependencies(components, () => []);

    expect(waves.length).toBe(1);
    expect(new Set(waves[0].map((c) => c.machineName))).toEqual(
      new Set(['avatar', 'badge', 'spinner']),
    );
  });

  it('handles diamond import pattern efficiently', () => {
    // dialog imports dialog-header and dialog-body
    // Both dialog-header and dialog-body import typography
    const components = [
      { machineName: 'typography' },
      { machineName: 'dialog-header' },
      { machineName: 'dialog-body' },
      { machineName: 'dialog' },
    ];

    const dependencies: Record<string, string[]> = {
      typography: [],
      'dialog-header': ['typography'],
      'dialog-body': ['typography'],
      dialog: ['dialog-header', 'dialog-body'],
    };

    const waves = sortByDependencies(
      components,
      (c) => dependencies[c.machineName] || [],
    );

    expect(waves.length).toBe(3);
    // Wave 1: typography
    expect(waves[0].map((c) => c.machineName)).toEqual(['typography']);
    // Wave 2: dialog-header and dialog-body can be parallel
    expect(new Set(waves[1].map((c) => c.machineName))).toEqual(
      new Set(['dialog-header', 'dialog-body']),
    );
    // Wave 3: dialog
    expect(waves[2].map((c) => c.machineName)).toEqual(['dialog']);
  });

  it('handles more complex dependency graph', () => {
    // Complex graph:
    // - icon, badge, avatar (no deps)
    // - button (imports icon)
    // - card (imports button + badge), menu (imports icon + button)
    // - page (imports card + menu + avatar)
    const components = [
      { machineName: 'page' },
      { machineName: 'card' },
      { machineName: 'menu' },
      { machineName: 'button' },
      { machineName: 'avatar' },
      { machineName: 'icon' },
      { machineName: 'badge' },
    ];

    const dependencies: Record<string, string[]> = {
      page: ['card', 'menu', 'avatar'],
      card: ['button', 'badge'],
      menu: ['icon', 'button'],
      button: ['icon'],
      avatar: [],
      icon: [],
      badge: [],
    };

    const waves = sortByDependencies(
      components,
      (c) => dependencies[c.machineName] || [],
    );

    expect(waves.length).toBe(4);
    // Wave 1: icon, badge, avatar (no dependencies)
    expect(new Set(waves[0].map((c) => c.machineName))).toEqual(
      new Set(['icon', 'badge', 'avatar']),
    );
    // Wave 2: button (depends on icon)
    expect(waves[1].map((c) => c.machineName)).toEqual(['button']);
    // Wave 3: card and menu can be parallel (both depend on wave 1 & 2)
    expect(new Set(waves[2].map((c) => c.machineName))).toEqual(
      new Set(['card', 'menu']),
    );
    // Wave 4: page (depends on everything)
    expect(waves[3].map((c) => c.machineName)).toEqual(['page']);
  });

  it('handles empty input', () => {
    const waves = sortByDependencies([], () => []);
    expect(waves).toEqual([]);
  });
});
