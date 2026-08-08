import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';

import {
  changesetId,
  classifyDrift,
  componentConfigEntityId,
  detectConcurrentWrite,
  globalAssetFingerprint,
  hasFleetFiles,
  isDiverged,
  listChangesets,
  readChangeset,
  readFleet,
  readLibrary,
  readLock,
  resolveSiteCredentials,
  resolveTargets,
  sourceFingerprint,
  targetedProtectedGroups,
  writeChangeset,
  writeLock,
} from './fleet';

import type { Component } from '../types/Component';
import type { Changeset, FleetFile, LockFile } from './fleet';

const FLEET: FleetFile = {
  groups: {
    canary: ['marketing-eu'],
    'wave-1': ['marketing-us', 'marketing-apac'],
    prod: ['brand-main', 'support-portal'],
  },
  sites: {
    'marketing-eu': {
      url: 'https://marketing-eu.example.com',
      credentialsEnv: 'CANVAS_OAUTH_MARKETING_EU',
    },
    'marketing-us': { url: 'https://marketing-us.example.com' },
    'marketing-apac': { url: 'https://marketing-apac.example.com' },
    'brand-main': { url: 'https://brand-main.example.com' },
    'support-portal': { url: 'https://support-portal.example.com' },
    'orphan-site': { url: 'https://orphan.example.com' },
  },
  protectedGroups: ['prod'],
};

describe('resolveTargets', () => {
  it('expands groups in the order the flags were given', () => {
    expect(
      resolveTargets(FLEET, { group: ['wave-1', 'canary'] }),
    ).toStrictEqual(['marketing-us', 'marketing-apac', 'marketing-eu']);
  });

  it('orders --all by group declaration, then ungrouped sites', () => {
    expect(resolveTargets(FLEET, { all: true })).toStrictEqual([
      'marketing-eu',
      'marketing-us',
      'marketing-apac',
      'brand-main',
      'support-portal',
      'orphan-site',
    ]);
  });

  it('deduplicates a site that appears in several groups', () => {
    const overlapping: FleetFile = {
      ...FLEET,
      groups: { ...FLEET.groups, extra: ['marketing-eu'] },
    };
    expect(
      resolveTargets(overlapping, { group: ['canary', 'extra'] }),
    ).toStrictEqual(['marketing-eu']);
  });

  it('subtracts --exclude from the resolved set', () => {
    expect(
      resolveTargets(FLEET, { group: ['wave-1'], exclude: ['marketing-apac'] }),
    ).toStrictEqual(['marketing-us']);
  });

  it('combines --site with --group', () => {
    expect(
      resolveTargets(FLEET, { group: ['canary'], site: ['brand-main'] }),
    ).toStrictEqual(['marketing-eu', 'brand-main']);
  });

  it('rejects unknown site, group, and exclude names', () => {
    expect(() => resolveTargets(FLEET, { site: ['nope'] })).toThrow(
      /Unknown site "nope"/,
    );
    expect(() => resolveTargets(FLEET, { group: ['nope'] })).toThrow(
      /Unknown group "nope"/,
    );
    expect(() =>
      resolveTargets(FLEET, { all: true, exclude: ['nope'] }),
    ).toThrow(/Unknown site "nope" in --exclude/);
  });

  it('returns nothing when no targeting flag is given', () => {
    expect(resolveTargets(FLEET, {})).toStrictEqual([]);
  });
});

describe('targetedProtectedGroups', () => {
  it('reports protected groups among the targets', () => {
    expect(targetedProtectedGroups(FLEET, ['brand-main'])).toStrictEqual([
      'prod',
    ]);
  });

  it('reports nothing for unprotected targets', () => {
    expect(targetedProtectedGroups(FLEET, ['marketing-eu'])).toStrictEqual([]);
  });
});

describe('resolveSiteCredentials', () => {
  const site = {
    url: 'https://x.example.com',
    credentialsEnv: 'SITE_CREDENTIALS',
  };

  it('splits the environment value at the first colon', () => {
    expect(
      resolveSiteCredentials('x', site, { SITE_CREDENTIALS: 'id:sec:ret' }),
    ).toStrictEqual({ clientId: 'id', clientSecret: 'sec:ret' });
  });

  it('returns undefined when the site declares no credentials variable', () => {
    expect(
      resolveSiteCredentials('x', { url: 'https://x.example.com' }, {}),
    ).toBeUndefined();
  });

  it('fails when the declared variable is unset', () => {
    expect(() => resolveSiteCredentials('x', site, {})).toThrow(
      /\$SITE_CREDENTIALS to "client_id:client_secret"/,
    );
  });

  it('fails when the value is not client_id:client_secret', () => {
    expect(() =>
      resolveSiteCredentials('x', site, { SITE_CREDENTIALS: 'no-separator' }),
    ).toThrow(/must be formatted/);
    expect(() =>
      resolveSiteCredentials('x', site, { SITE_CREDENTIALS: ':secret' }),
    ).toThrow(/must be formatted/);
  });
});

describe('sourceFingerprint', () => {
  const base = {
    machineName: 'hero',
    name: 'Hero',
    props: { title: { type: 'string' } },
    slots: {},
    required: ['title'],
    sourceCodeJs: 'export default () => null;',
    sourceCodeCss: '.hero {}',
    compiledJs: 'compiled-a',
    compiledCss: 'compiled-a',
    importedJsComponents: ['button', 'icon'],
    dataDependencies: {},
  };
  const component = (overrides: Record<string, unknown> = {}) =>
    ({ ...base, ...overrides }) as unknown as Partial<Component>;

  it('ignores compiled artifacts, which the build does not reproduce byte for byte', () => {
    expect(sourceFingerprint(component())).toBe(
      sourceFingerprint(
        component({ compiledJs: 'compiled-b', compiledCss: 'compiled-b' }),
      ),
    );
  });

  it('ignores key order and set ordering', () => {
    expect(sourceFingerprint(component())).toBe(
      sourceFingerprint(
        component({
          importedJsComponents: ['icon', 'button'],
          props: { title: { type: 'string' } },
        }),
      ),
    );
  });

  it('treats an absent status as enabled', () => {
    expect(sourceFingerprint(component())).toBe(
      sourceFingerprint(component({ status: true })),
    );
    expect(sourceFingerprint(component())).not.toBe(
      sourceFingerprint(component({ status: false })),
    );
  });

  it('changes when authored source changes', () => {
    expect(sourceFingerprint(component())).not.toBe(
      sourceFingerprint(component({ sourceCodeCss: '.hero { color: red }' })),
    );
  });

  it('treats an empty map and an empty list as the same nothing', () => {
    // The CLI sends `slots: {}`; PHP serializes the same empty map back as
    // `slots: []`. A round-trip must not read as an edit.
    expect(
      sourceFingerprint(component({ slots: {}, dataDependencies: {} })),
    ).toBe(sourceFingerprint(component({ slots: [], dataDependencies: [] })));
    // A populated map is still distinguishable.
    expect(sourceFingerprint(component({ slots: {} }))).not.toBe(
      sourceFingerprint(component({ slots: { body: { title: 'Body' } } })),
    );
  });
});

describe('classifyDrift', () => {
  /** A component pushed as `s1`, which the site returned as `a1` / `v1`. */
  const locked = {
    pushedHash: 'v1',
    pushedSourceHash: 's1',
    appliedSourceHash: 'a1',
    observedHash: 'v1',
    observedSourceHash: 'a1',
  };

  it('is unknown without a lockfile entry', () => {
    expect(
      classifyDrift({
        desiredSourceHash: 's1',
        refreshed: true,
        observedSourceHash: 'a1',
        observedHash: 'v1',
      }),
    ).toBe('unknown');
  });

  it('is in sync when nothing moved on either side', () => {
    expect(
      classifyDrift({
        desiredSourceHash: 's1',
        locked,
        refreshed: true,
        observedSourceHash: 'a1',
        observedHash: 'v1',
      }),
    ).toBe('in-sync');
  });

  it('is behind when only the library moved', () => {
    expect(
      classifyDrift({
        desiredSourceHash: 's2',
        locked,
        refreshed: true,
        observedSourceHash: 'a1',
        observedHash: 'v1',
      }),
    ).toBe('behind');
  });

  it('is diverged when only the site moved', () => {
    expect(
      classifyDrift({
        desiredSourceHash: 's1',
        locked,
        refreshed: true,
        observedSourceHash: 'edited',
        observedHash: 'v1',
      }),
    ).toBe('diverged');
  });

  it('is diverged when the site-reported active version moved', () => {
    expect(
      classifyDrift({
        desiredSourceHash: 's1',
        locked,
        refreshed: true,
        observedSourceHash: 'a1',
        observedHash: 'v9',
      }),
    ).toBe('diverged');
  });

  it('is conflicted when both sides moved', () => {
    expect(
      classifyDrift({
        desiredSourceHash: 's2',
        locked,
        refreshed: true,
        observedSourceHash: 'edited',
        observedHash: 'v9',
      }),
    ).toBe('conflicted');
  });

  it('compares against the apply-time baseline, not the last observation', () => {
    // A refresh recorded the site-side edit in the observed columns. The
    // divergence must survive that, or the next apply would clobber the edit.
    const afterRefresh = {
      ...locked,
      observedSourceHash: 'edited',
      observedHash: 'v9',
    };
    expect(
      classifyDrift({
        desiredSourceHash: 's1',
        locked: afterRefresh,
        refreshed: true,
        observedSourceHash: 'edited',
        observedHash: 'v9',
      }),
    ).toBe('diverged');
    expect(
      classifyDrift({
        desiredSourceHash: 's2',
        locked: afterRefresh,
        refreshed: true,
        observedSourceHash: 'edited',
        observedHash: 'v9',
      }),
    ).toBe('conflicted');
  });

  it('treats a component deleted on the site as unapplied, not diverged', () => {
    // Nothing is there to clobber, so reconcile it forward.
    expect(
      classifyDrift({ desiredSourceHash: 's1', locked, refreshed: true }),
    ).toBe('unknown');
  });

  it('cannot see divergence without a refresh', () => {
    expect(
      classifyDrift({ desiredSourceHash: 's1', locked, refreshed: false }),
    ).toBe('in-sync');
    expect(
      classifyDrift({ desiredSourceHash: 's2', locked, refreshed: false }),
    ).toBe('behind');
  });

  it('marks only diverged and conflicted as unsafe to overwrite', () => {
    expect(isDiverged('diverged')).toBe(true);
    expect(isDiverged('conflicted')).toBe(true);
    expect(isDiverged('behind')).toBe(false);
    expect(isDiverged('unknown')).toBe(false);
    expect(isDiverged('in-sync')).toBe(false);
  });
});

describe('globalAssetFingerprint', () => {
  it('hashes authored global CSS and package.json only', () => {
    const base = {
      css: { original: 'body { color: red }', compiled: 'a' },
      js: { original: 'x', compiled: 'y' },
      packageJson: '{"name":"lib"}',
    };
    // Compiled output and manifest URIs differ per site and per build.
    expect(globalAssetFingerprint(base)).toBe(
      globalAssetFingerprint({
        ...base,
        css: { original: base.css.original, compiled: 'b' },
        js: { original: 'z', compiled: 'w' },
        imports: [{ name: 'react', uri: 'public://x' }],
      }),
    );
    expect(globalAssetFingerprint(base)).not.toBe(
      globalAssetFingerprint({
        ...base,
        css: { original: 'body { color: blue }', compiled: 'a' },
      }),
    );
    expect(globalAssetFingerprint(base)).not.toBe(
      globalAssetFingerprint({ ...base, packageJson: '{"name":"other"}' }),
    );
  });
});

describe('componentConfigEntityId', () => {
  it('maps a machine name onto its Component config entity ID', () => {
    expect(componentConfigEntityId('hero')).toBe('js.hero');
  });
});

describe('file round-trips', () => {
  let root: string;

  beforeEach(() => {
    root = fs.mkdtempSync(path.join(os.tmpdir(), 'canvas-fleet-'));
  });

  afterEach(() => {
    fs.rmSync(root, { recursive: true, force: true });
  });

  function write(name: string, contents: unknown): void {
    fs.writeFileSync(path.join(root, name), JSON.stringify(contents), 'utf-8');
  }

  it('reads the library and fleet manifests', () => {
    write('canvas.library.json', {
      name: '@acme/canvas-library',
      version: '3.4.0',
      components: ['hero'],
    });
    write('canvas.fleet.json', FLEET);
    expect(readLibrary(root).version).toBe('3.4.0');
    expect(Object.keys(readFleet(root).sites)).toContain('brand-main');
    expect(hasFleetFiles(root)).toBe(true);
  });

  it('explains what to run when the manifests are missing', () => {
    expect(hasFleetFiles(root)).toBe(false);
    expect(() => readLibrary(root)).toThrow(/canvas library init/);
    expect(() => readFleet(root)).toThrow(/canvas fleet init/);
  });

  it('rejects an inventory whose group names an unknown site', () => {
    write('canvas.fleet.json', {
      groups: { prod: ['ghost'] },
      sites: { real: { url: 'https://real.example.com' } },
    });
    expect(() => readFleet(root)).toThrow(/unknown site "ghost"/);
  });

  it('rejects a site without a URL', () => {
    write('canvas.fleet.json', { sites: { broken: {} } });
    expect(() => readFleet(root)).toThrow(/has no "url"/);
  });

  it('reports the offending file when JSON is malformed', () => {
    fs.writeFileSync(path.join(root, 'canvas.fleet.json'), '{oops', 'utf-8');
    expect(() => readFleet(root)).toThrow(/Invalid JSON in canvas.fleet.json/);
  });

  it('returns an empty lockfile when none exists, and round-trips writes', () => {
    expect(readLock(root).sites).toStrictEqual({});
    const lock: LockFile = {
      lockfileVersion: 1,
      generatedAt: '2026-08-05T10:22:00Z',
      sites: {
        'marketing-eu': {
          libraryVersion: '3.3.0',
          appliedAt: '2026-08-04T09:00:00Z',
          appliedBy: 'ci',
          appliedRef: 'abc123',
          lastRefresh: '2026-08-04T09:00:00Z',
          components: {
            hero: {
              pushedHash: '265d230570aec8cb',
              observedHash: '265d230570aec8cb',
            },
          },
        },
      },
    };
    writeLock(lock, root);
    const reread = readLock(root);
    expect(reread.sites['marketing-eu']).toStrictEqual(
      lock.sites['marketing-eu'],
    );
    expect(reread.lockfileVersion).toBe(1);
    // `generatedAt` is stamped on every write, so it is not round-tripped.
    expect(reread.generatedAt).not.toBe(lock.generatedAt);
  });

  it('round-trips changesets and lists them in capture order', () => {
    const changeset: Changeset = {
      id: changesetId('marketing-eu', new Date('2026-08-05T10:22:00Z')),
      site: 'marketing-eu',
      siteUrl: 'https://marketing-eu.example.com',
      capturedAt: '2026-08-05T10:22:00Z',
      libraryVersion: '3.4.0',
      components: {
        hero: {
          present: true,
          version: 'v1',
          payload: { machineName: 'hero' } as unknown as Component,
        },
        cta: { present: false },
      },
    };
    writeChangeset(changeset, root);
    writeChangeset(
      {
        ...changeset,
        id: changesetId('brand-main', new Date('2026-08-06T10:22:00Z')),
        site: 'brand-main',
      },
      root,
    );
    expect(listChangesets(root)).toStrictEqual([
      '2026-08-05T10-22-00-000Z-marketing-eu',
      '2026-08-06T10-22-00-000Z-brand-main',
    ]);
    expect(readChangeset(changeset.id, root).components.hero.present).toBe(
      true,
    );
    expect(() => readChangeset('nope', root)).toThrow(/No changeset "nope"/);
  });

  it('lists nothing before any changeset is captured', () => {
    expect(listChangesets(root)).toStrictEqual([]);
  });

  it('notices another run writing the lockfile underneath it', () => {
    const lock: LockFile = {
      lockfileVersion: 1,
      generatedAt: 'ignored',
      sites: {},
    };
    writeLock(lock, root);
    const readToken = readLock(root).writeToken;

    // Nothing else has written since this run read it.
    expect(detectConcurrentWrite(readToken, root)).toBeUndefined();

    // Somebody else's apply lands. Detection must not depend on the clock:
    // two writes can land in the same millisecond.
    writeLock(lock, root);
    expect(detectConcurrentWrite(readToken, root)).toBeDefined();
    expect(readLock(root).writeToken).not.toBe(readToken);
  });

  it('reports no collision when there was no lockfile to begin with', () => {
    expect(detectConcurrentWrite(undefined, root)).toBeUndefined();
  });
});
